//! The published v1 activity-result upload allowance grants no task completion.
use super::transaction::Transaction;
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, now},
};
use crate::external_payload::Reference;
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

#[derive(Clone)]
pub(crate) struct Context {
    value: Value,
    task: String,
    attempt: String,
    owner: String,
}

fn invalid() -> RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "external_payload_completion_invalid",
    )
}
fn rejected() -> RuntimeError {
    refuse(
        StatusCode::CONFLICT,
        "external_payload_completion_lease_rejected",
    )
}
fn unavailable() -> RuntimeError {
    refuse(
        StatusCode::SERVICE_UNAVAILABLE,
        "external_payload_unavailable",
    )
}
fn lease_error(error: RuntimeError) -> RuntimeError {
    match error {
        RuntimeError::Refused { .. } => rejected(),
        error => error,
    }
}
fn identifier(value: &Value) -> Result<String> {
    value
        .as_str()
        .filter(|value| {
            !value.is_empty()
                && value.len() <= 255
                && !value.chars().any(|c| c <= '\u{1f}' || c == '\u{7f}')
        })
        .map(str::to_owned)
        .ok_or_else(invalid)
}

// PHP's default json_encode escapes slashes and UTF-16 code units. These hashes
// must also match retained PHP budget identities during a later database takeover.
fn identity(value: &Value) -> String {
    use std::fmt::Write;
    let mut encoded = String::new();
    for c in serde_json::to_string(value).expect("JSON value").chars() {
        if c == '/' {
            encoded.push_str("\\/");
        } else if c.is_ascii() {
            encoded.push(c);
        } else {
            for unit in c.encode_utf16(&mut [0; 2]) {
                write!(encoded, "\\u{unit:04x}").expect("String write");
            }
        }
    }
    Sha256::digest(encoded.as_bytes())
        .iter()
        .map(|b| format!("{b:02x}"))
        .collect()
}

impl Context {
    pub(super) fn parse_bytes(header: &[u8]) -> Result<Self> {
        if header.len() > 4096 {
            return Err(invalid());
        }
        Self::parse(std::str::from_utf8(header).map_err(|_| invalid())?)
    }
    pub(super) fn parse(header: &str) -> Result<Self> {
        if header.len() > 4096 {
            return Err(invalid());
        }
        let value: Value = serde_json::from_str(header).map_err(|_| invalid())?;
        if value["schema"] == "durable-workflow.v2.payload-completion-context.v2" {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_payload_completion_upload_unqualified",
            ));
        }
        let fields = [
            "schema",
            "kind",
            "task_id",
            "attempt",
            "lease_owner",
            "operation",
            "slot",
        ];
        if value["schema"] != "durable-workflow.v2.payload-completion-context.v1"
            || value.as_object().is_none_or(|map| {
                map.len() != fields.len() || fields.iter().any(|key| !map.contains_key(*key))
            })
        {
            return Err(invalid());
        }
        if value["kind"] != "activity" || value["operation"] != "complete" {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_payload_completion_upload_unqualified",
            ));
        }
        if value["slot"] != json!(["result"]) {
            return Err(invalid());
        }
        Ok(Self {
            task: identifier(&value["task_id"])?,
            attempt: identifier(&value["attempt"])?,
            owner: identifier(&value["lease_owner"])?,
            value,
        })
    }
    /// The task, attempt and lease owner this context names.
    pub(super) fn lease_identity(&self) -> (&str, &str, &str) {
        (&self.task, &self.attempt, &self.owner)
    }
    fn scope(&self, namespace: &str) -> String {
        identity(&json!([
            namespace,
            "activity",
            self.task,
            self.attempt,
            self.owner
        ]))
    }
    fn slot(&self) -> String {
        identity(&json!(["complete", ["result"]]))
    }
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    pub(super) async fn completion_upload_lease(
        &self,
        tx: &mut Transaction<'_, DB>,
        context: &Context,
    ) -> Result<DateTime<Utc>> {
        let task = self
            .task_row(tx, &context.task)
            .await
            .map_err(lease_error)?;
        // A case-folding database lookup must not create another allowance for
        // an alias of the same canonical task or attempt.
        if DB::string(&task, "task_type")? != "activity" || DB::string(&task, "id")? != context.task
        {
            return Err(rejected());
        }
        Self::fence(&task, &json!({"lease_owner":context.owner})).map_err(lease_error)?;
        let attempt =
            Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND workflow_task_id=$2")
                .bind(&context.attempt)
                .bind(&context.task)
                .fetch_optional(&mut **tx)
                .await?
                .ok_or_else(rejected)?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        Self::active_run(tx, &run_id).await.map_err(lease_error)?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(DB::string(&attempt, "activity_execution_id")?)
                .bind(&run_id)
                .fetch_optional(&mut **tx)
                .await?
                .ok_or_else(rejected)?;
        if DB::string(&attempt, "status")? != "running"
            || DB::string(&attempt, "id")? != context.attempt
            || DB::number(&attempt, "attempt_number")? != DB::number(&task, "attempt_count")?
            || DB::string(&attempt, "lease_owner")? != context.owner
            || DB::optional_string(&activity, "current_attempt_id")?.as_deref()
                != Some(context.attempt.as_str())
            || DB::number(&activity, "attempt_count")? != DB::number(&attempt, "attempt_number")?
            || DB::string(&activity, "status")? != "running"
        {
            return Err(rejected());
        }
        super::activity_deadlines::fence::<DB>(&activity).map_err(lease_error)?;
        DB::optional_instant(&task, "lease_expires_at")?.ok_or_else(rejected)
    }

    async fn completion_upload_reference(
        &self,
        tx: &mut Transaction<'_, DB>,
        context: &Context,
        expected: &Reference,
    ) -> Result<Option<Reference>> {
        let scope = context.scope(&self.namespace);
        let Some(budget) = Self::query(
            "SELECT slots FROM runtime_payload_completion_budgets WHERE id=$1 AND namespace=$2",
        )
        .bind(&scope)
        .bind(self.namespace.as_ref())
        .fetch_optional(&mut **tx)
        .await?
        else {
            return Ok(None);
        };
        let slots = DB::document_row(&budget, "slots")?;
        let slot = &slots[context.slot()];
        if slot["sha256"] != expected.sha256 || slot["size_bytes"] != expected.size_bytes {
            return Ok(None);
        }
        let Ok(reference) = Reference::validate(&slot["reference"]) else {
            return Ok(None);
        };
        let Some(row) =
            Self::query("SELECT * FROM runtime_external_payloads WHERE id=$1 AND namespace=$2")
                .bind(&reference.reference_id)
                .bind(self.namespace.as_ref())
                .fetch_optional(&mut **tx)
                .await?
        else {
            return Ok(None);
        };
        if DB::string(&row, "codec")? != "avro"
            || DB::string(&row, "sha256")? != expected.sha256
            || DB::number(&row, "size_bytes")? != expected.size_bytes
            || DB::string(&row, "upload_status")? != "ready"
            || DB::optional_instant(&row, "retained_at")?.is_none()
                && DB::optional_instant(&row, "expires_at")?.is_none_or(|expires| expires <= now())
        {
            return Ok(None);
        }
        Ok(Some(reference))
    }

    pub(super) async fn authorize_completion_upload(
        &self,
        context: &Context,
        expected: &Reference,
    ) -> Result<Option<Reference>> {
        let mut tx = self.begin().await?;
        let existing = self
            .completion_upload_reference(&mut tx, context, expected)
            .await?;
        if existing.is_none() {
            self.completion_upload_lease(&mut tx, context).await?;
        }
        tx.commit().await?;
        Ok(existing)
    }

    pub(super) async fn upload_activity_completion(
        &self,
        local: crate::external_payload::LocalStorage,
        expected: Reference,
        snapshot: crate::external_payload::Snapshot,
        expires: DateTime<Utc>,
        quota: super::external_config::Quota,
        context: Context,
        max_bytes: u64,
        retention: i64,
    ) -> Result<Reference> {
        let scope = context.scope(&self.namespace);
        let slot = context.slot();
        let mut tx = self.begin().await?;
        Self::payload_object_lock(&mut tx, &scope).await?;
        if let Some(reference) = self
            .completion_upload_reference(&mut tx, &context, &expected)
            .await?
        {
            return Ok(reference);
        }
        let lease = self.completion_upload_lease(&mut tx, &context).await?;
        let expiry = chrono::Duration::try_seconds(retention)
            .and_then(|duration| lease.checked_add_signed(duration))
            .ok_or_else(unavailable)?;
        let budget = Self::query("SELECT slots,objects FROM runtime_payload_completion_budgets WHERE id=$1 AND namespace=$2")
            .bind(&scope).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?;
        let (mut slots, mut objects) = match &budget {
            Some(row) => (
                DB::document_row(row, "slots")?,
                DB::document_row(row, "objects")?,
            ),
            None => (json!({}), json!({})),
        };
        let slots_map = slots.as_object_mut().ok_or_else(unavailable)?;
        let objects_map = objects.as_object_mut().ok_or_else(unavailable)?;
        let recorded = slots_map.get(&slot);
        if recorded.is_some_and(|value| {
            value["sha256"] != expected.sha256 || value["size_bytes"] != expected.size_bytes
        }) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "external_payload_completion_conflict",
            ));
        }
        let total = objects_map
            .values()
            .try_fold(0_u64, |sum, size| sum.checked_add(size.as_u64()?))
            .ok_or_else(unavailable)?;
        if recorded.is_none() && slots_map.len() >= 128
            || !objects_map.contains_key(&expected.sha256)
                && expected.size_bytes as u64 > max_bytes.saturating_sub(total)
        {
            return Err(refuse(
                StatusCode::TOO_MANY_REQUESTS,
                "external_payload_completion_budget_exhausted",
            ));
        }
        slots_map
            .entry(slot.clone())
            .or_insert_with(|| json!({"sha256":expected.sha256,"size_bytes":expected.size_bytes}));
        objects_map.insert(expected.sha256.clone(), json!(expected.size_bytes));
        let at = now();
        if budget.is_none() {
            Self::query("INSERT INTO runtime_payload_completion_budgets(id,namespace,context,slots,objects,expires_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)")
                .bind(&scope).bind(self.namespace.as_ref()).bind(DB::document(&context.value)).bind(DB::document(&slots))
                .bind(DB::document(&objects)).bind(DB::bind_time(expiry)).bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut *tx).await?;
        } else {
            Self::query("UPDATE runtime_payload_completion_budgets SET slots=$1,objects=$2,expires_at=$3,updated_at=$4 WHERE id=$5 AND namespace=$6")
                .bind(DB::document(&slots)).bind(DB::document(&objects)).bind(DB::bind_time(expiry)).bind(DB::bind_time(at))
                .bind(&scope).bind(self.namespace.as_ref()).execute(&mut *tx).await?;
        }
        tx.commit().await?;
        let reference = self
            .upload_external_local_guarded(
                local,
                expected,
                snapshot,
                expires,
                quota,
                Some(&context),
            )
            .await?;
        let mut tx = self.begin().await?;
        Self::payload_object_lock(&mut tx, &scope).await?;
        let row = Self::query(
            "SELECT slots FROM runtime_payload_completion_budgets WHERE id=$1 AND namespace=$2",
        )
        .bind(&scope)
        .bind(self.namespace.as_ref())
        .fetch_one(&mut *tx)
        .await?;
        let mut slots = DB::document_row(&row, "slots")?;
        slots[&slot]["reference"] = json!(reference);
        Self::query("UPDATE runtime_payload_completion_budgets SET slots=$1,updated_at=$2 WHERE id=$3 AND namespace=$4")
            .bind(DB::document(&slots)).bind(DB::bind_time(now())).bind(&scope).bind(self.namespace.as_ref()).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(reference)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn malformed_contexts_refuse_without_becoming_upload_authority() {
        let valid = json!({"schema":"durable-workflow.v2.payload-completion-context.v1","kind":"activity",
            "task_id":"task","attempt":"attempt","lease_owner":"owner","operation":"complete","slot":["result"]});
        assert!(Context::parse(&valid.to_string()).is_ok());
        for (field, value) in [
            ("attempt", json!(1)),
            ("lease_owner", json!("owner\n")),
            ("task_id", json!("")),
            ("slot", json!(["commands", 0, "result"])),
        ] {
            let mut bad = valid.clone();
            bad[field] = value;
            assert!(
                matches!(
                    Context::parse(&bad.to_string()),
                    Err(RuntimeError::Refused {
                        reason: "external_payload_completion_invalid",
                        ..
                    })
                ),
                "{bad}"
            );
        }
        let mut extra = valid;
        extra["new_authority"] = json!(true);
        assert!(Context::parse(&extra.to_string()).is_err());
        assert!(Context::parse(&" ".repeat(4097)).is_err());
    }
    #[test]
    fn scope_matches_actual_published_php_ascii_slash_unicode_and_surrogate_hashes() {
        assert_eq!(
            identity(&json!(["default", "activity", "task", "attempt", "owner"])),
            "eb0609d789d53c946f889b345ed8231ec6e217431628f1c5d59ccb0d24867427"
        );
        assert_eq!(
            identity(&json!([
                "default",
                "activity",
                "task",
                "attempt",
                "owner/λ😀"
            ])),
            "3ed044a74929ada4c313de51b22715f4e974a0a12c7356330500bc736df66641"
        );
        assert_eq!(
            identity(&json!(["complete", ["result"]])),
            "be37893a3312755fa3826ca9282c3bd053521dc77235d9024c4c58de6e49dcd0"
        );
    }
    #[test]
    fn completion_header_accepts_utf8_and_preserves_escaped_identity() {
        let raw = json!({"schema":"durable-workflow.v2.payload-completion-context.v1",
            "kind":"activity", "task_id":"task", "attempt":"attempt",
            "lease_owner":"owner/λ😀", "operation":"complete", "slot":["result"]})
        .to_string();
        let escaped = raw.replace('λ', "\\u03bb").replace('😀', "\\ud83d\\ude00");
        let raw = Context::parse_bytes(raw.as_bytes()).unwrap();
        let escaped = Context::parse_bytes(escaped.as_bytes()).unwrap();
        assert_eq!(raw.owner, "owner/λ😀");
        assert_eq!(raw.scope("default"), escaped.scope("default"));
        for malformed in [vec![0xff], vec![b' '; 4097]] {
            assert!(matches!(
                Context::parse_bytes(&malformed),
                Err(RuntimeError::Refused {
                    reason: "external_payload_completion_invalid",
                    ..
                })
            ));
        }
    }
}
