//! Update contracts for runs started before their worker registered one
//! (Server #962).
//!
//! A start copies the current worker registration's command contract into
//! `WorkflowStarted`. A run started before any worker on its queue
//! registered has no captured update contract. Rather than reject its
//! updates for the rest of the run, such a run binds one contract exactly
//! once: the contract the completing worker registered for the run's type,
//! committed in the transaction that accepts the run's first workflow task
//! completion. A claimed task that is abandoned binds nothing.
//!
//! The binding is server-side admission state in `dw_run_command_contracts`,
//! not history, so replay is unchanged. Describe keeps published PHP's
//! shape, so the binding shows only through update admission. A captured start contract is never
//! replaced, and a bound contract is never rebound. A worker with no valid
//! contract for the type binds an empty one, so its updates are unknown, as
//! before, instead of waiting for a contract that never comes.
//!
//! Until a run binds, its updates are refused with the retryable
//! `contract_pending` (HTTP 409), and nothing is recorded.
use super::{Result, refuse, store::now, transaction::Transaction};
use axum::http::StatusCode;
use serde_json::{Map, Value, json};

/// The retryable refusal for an update before the run's contract is bound.
pub(super) const PENDING: &str = "contract_pending";

/// `WorkflowStarted` fields a worker declaration supplies.
const FIELDS: [(&str, &str); 7] = [
    ("signals", "declared_signals"),
    ("signal_contracts", "declared_signal_contracts"),
    ("queries", "declared_queries"),
    ("query_contracts", "declared_query_contracts"),
    ("updates", "declared_updates"),
    ("update_contracts", "declared_update_contracts"),
    ("update_validators", "declared_update_validators"),
];

/// Whether a start payload captured an update contract. An empty list is
/// a captured contract that declares no updates.
pub(super) fn captured(started: &Value) -> bool {
    started
        .get("declared_updates")
        .is_some_and(|value| value.is_array() || value.is_object())
}

/// The start-payload fields for a worker's registered declaration of
/// `workflow_type`, with the defaults `WorkflowStartService` gives an
/// external worker's contract, which make it a complete durable contract.
/// `None` for a missing or malformed declaration.
pub(super) fn started_fields(workflow_type: &str, declaration: Option<&Value>) -> Option<Map<String, Value>> {
    // Older development registrations may predate validation. Never turn a
    // malformed entry into authoritative empties.
    let contract = super::worker_contracts::declaration(declaration?).ok()?;
    let mut fields = Map::new();
    for (source, target) in FIELDS {
        if let Some(value) = contract.get(source) {
            fields.insert(target.into(), value.clone());
        }
    }
    fields.insert("declared_entry_method".into(), json!("handle"));
    fields.insert("declared_entry_mode".into(), json!("canonical"));
    fields.insert("declared_entry_declaring_class".into(), json!(format!("external:{workflow_type}")));
    Some(fields)
}

/// The contract bound for a worker without a valid declaration: no
/// signals, queries or updates are declared.
fn empty_fields(workflow_type: &str) -> Map<String, Value> {
    started_fields(workflow_type, Some(&json!({}))).expect("an empty declaration is valid")
}

/// The start payload with a bound contract's fields filled in where the
/// start captured none. Captured fields always win.
pub(super) fn effective(started: &Value, bound: Option<&Value>) -> Value {
    let mut contract = started.clone();
    if let (Some(fields), Some(bound)) = (contract.as_object_mut(), bound.and_then(Value::as_object)) {
        for (key, value) in bound {
            fields.entry(key.clone()).or_insert_with(|| value.clone());
        }
    }
    contract
}

store_methods! {
    /// The run's bound contract fields, if it has bound one.
    pub(super) async fn bound_contract(connection: &mut DB::Connection, run_id: &str) -> Result<Option<Value>> {
        let Some(row) = Self::query("SELECT contract FROM dw_run_command_contracts WHERE workflow_run_id=$1")
            .bind(run_id)
            .fetch_optional(&mut *connection)
            .await?
        else {
            return Ok(None);
        };
        serde_json::from_str::<Value>(&DB::string(&row, "contract")?)
            .map(|contract| Some(contract).filter(|contract| !contract.is_null()))
            .map_err(|_| refuse(StatusCode::INTERNAL_SERVER_ERROR, "invalid_bound_command_contract"))
    }

    /// Record the run's contract inside the transaction that accepts its
    /// first workflow task completion: the completing worker's when the
    /// start captured none. The caller holds the run's transition, so no
    /// concurrent completion of the run can bind first.
    pub(super) async fn bind_update_contract(
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
    ) -> Result<()> {
        let run_id = DB::string(task, "workflow_run_id")?;
        // Every later completion of the run stops at this primary-key read.
        let bound: Option<String> = Self::query("SELECT workflow_run_id FROM dw_run_command_contracts WHERE workflow_run_id=$1")
            .bind(&run_id)
            .fetch_optional(&mut **tx)
            .await?
            .map(|row| DB::string(&row, "workflow_run_id"))
            .transpose()?;
        if bound.is_some() {
            return Ok(());
        }
        let run = Self::query("SELECT namespace,workflow_type FROM workflow_runs WHERE id=$1")
            .bind(&run_id)
            .fetch_one(&mut **tx)
            .await?;
        let namespace = DB::optional_string(&run, "namespace")?.unwrap_or_default();
        let workflow_type = DB::string(&run, "workflow_type")?;
        let worker_id = DB::optional_string(task, "lease_owner")?;
        let declaration = match worker_id.as_deref() {
            Some(worker) => Self::query("SELECT workflow_command_contracts FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
                .bind(namespace.as_str())
                .bind(worker)
                .fetch_optional(&mut **tx)
                .await?
                .map(|row| DB::optional_document_row(&row, "workflow_command_contracts"))
                .transpose()?
                .flatten()
                .and_then(|contracts| contracts.get(&workflow_type).cloned()),
            None => None,
        };
        // A run whose start captured its contract records JSON null: its first
        // completion is past and admission keeps reading the start contract.
        let contract = if captured(&Self::started_payload(&mut **tx, &run_id).await?) {
            Value::Null
        } else {
            Value::Object(started_fields(&workflow_type, declaration.as_ref()).unwrap_or_else(|| empty_fields(&workflow_type)))
        };
        Self::query("INSERT INTO dw_run_command_contracts(workflow_run_id,namespace,workflow_type,contract,worker_id,workflow_task_id,bound_at) VALUES ($1,$2,$3,$4,$5,$6,$7)")
            .bind(&run_id)
            .bind(namespace.as_str())
            .bind(workflow_type.as_str())
            .bind(contract.to_string())
            .bind(worker_id.as_deref())
            .bind(DB::string(task, "id")?)
            .bind(DB::bind_time(now()))
            .execute(&mut **tx)
            .await?;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_captured_empty_list_is_a_captured_contract() {
        assert!(captured(&json!({"declared_updates":[]})));
        assert!(captured(&json!({"declared_updates":{"0":"set"}})));
        assert!(!captured(&json!({})));
        assert!(!captured(&json!({"declared_updates":null})));
        assert!(!captured(&Value::Null));
    }

    #[test]
    fn bound_fields_fill_only_what_the_start_did_not_capture() {
        let bound = started_fields("noted", Some(&json!({"updates":["set_note"],"queries":["state"]}))).unwrap();
        assert_eq!(bound["declared_updates"], json!(["set_note"]));
        assert_eq!(bound["declared_entry_declaring_class"], "external:noted");
        let started = json!({"workflow_type":"noted","declared_entry_method":"run"});
        let contract = effective(&started, Some(&Value::Object(bound)));
        assert_eq!(contract["declared_updates"], json!(["set_note"]));
        assert_eq!(contract["declared_queries"], json!(["state"]));
        // A captured field is never replaced.
        assert_eq!(contract["declared_entry_method"], "run");
        assert_eq!(effective(&started, None), started);
    }

    #[test]
    fn a_missing_or_malformed_declaration_binds_no_updates() {
        assert!(started_fields("noted", None).is_none());
        assert!(started_fields("noted", Some(&json!({"updates":"set"}))).is_none());
        let empty = empty_fields("noted");
        assert_eq!(empty["declared_updates"], json!([]));
        assert!(super::super::update_validation::durable_contract(&Value::Object(empty)));
    }
}
