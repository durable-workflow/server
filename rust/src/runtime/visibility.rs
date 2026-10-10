//! Bounded visibility reads from authoritative native runs. Persisted PHP
//! summary projections and Waterline consumption require their own fixtures.
use axum::http::StatusCode;
use base64::{Engine, engine::general_purpose::STANDARD};
use serde::Deserialize;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

use super::{Result, RuntimeError, backend::Backend, refuse, store::Store};

#[derive(Default, Deserialize)]
pub(super) struct VisibilityQuery {
    workflow_type: Option<String>,
    status: Option<String>,
    query: Option<String>,
    page_size: Option<String>,
    next_page_token: Option<String>,
}

pub(super) struct VisibilityFilter {
    workflow_type: Option<String>,
    status: Option<String>,
    pattern: Option<String>,
    limit: i64,
    offset: i64,
}

impl VisibilityQuery {
    pub(super) fn validate(self) -> Result<VisibilityFilter> {
        if self
            .status
            .as_deref()
            .is_some_and(|value| !matches!(value, "running" | "completed" | "failed"))
        {
            return Err(validation("status", "The selected status is invalid."));
        }
        let limit = match self.page_size {
            Some(value) => value
                .parse::<i64>()
                .ok()
                .filter(|value| (1..=200).contains(value))
                .ok_or_else(|| {
                    validation(
                        "page_size",
                        "The page size must be an integer between 1 and 200.",
                    )
                })?,
            None => 50,
        };
        let term = self.query.as_deref().unwrap_or("").trim();
        if term.len() > 4096 || term.contains(['=', '<', '>', '"', '\'']) {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "unsupported_visibility_query",
            ));
        }
        let offset = self
            .next_page_token
            .as_deref()
            .and_then(|token| STANDARD.decode(token.trim()).ok())
            .and_then(|bytes| String::from_utf8(bytes).ok())
            .filter(|value| !value.is_empty() && value.bytes().all(|byte| byte.is_ascii_digit()))
            .map_or(0, |value| value.parse::<i64>().unwrap_or(i64::MAX));
        Ok(VisibilityFilter {
            workflow_type: self.workflow_type,
            status: self.status,
            pattern: (!term.is_empty()).then(|| format!("%{term}%")),
            limit,
            offset,
        })
    }
}

pub(super) fn status_bucket(status: &str) -> Result<&'static str> {
    match status {
        "pending" | "running" | "waiting" => Ok("running"),
        "completed" => Ok("completed"),
        "failed" | "cancelled" | "terminated" => Ok("failed"),
        _ => Err(refuse(StatusCode::CONFLICT, "unsupported_run_status")),
    }
}

pub(super) fn request_contract() -> Value {
    json!({"schema":"durable-workflow.v2.control-plane-request.contract", "version":1,
        "operations":{"list":{"fields":{"status":{"canonical_values":["running","completed","failed"],
            "rejected_aliases":{"cancelled":"failed","terminated":"failed","pending":"running","waiting":"running"}}}}}})
}

fn response(body: Value) -> Value {
    super::control_plane::Operation::List.response(body)
}

fn validation(field: &'static str, message: &'static str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: response(json!({"reason":"validation_failed", "message":message,
            "errors":{(field):[message]}, "validation_errors":{(field):[message]}})),
    }
}

pub(super) const VISIBILITY_PAGE: &str = "SELECT r.id,r.workflow_instance_id,r.workflow_type,r.queue,r.status,r.compatibility,r.started_at,r.closed_at,i.business_key FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE r.namespace='default' AND i.namespace='default' AND ($1 IS NULL OR r.workflow_type=$2) AND ($3 IS NULL OR CASE WHEN r.status IN ('pending','running','waiting') THEN 'running' WHEN r.status='completed' THEN 'completed' ELSE 'failed' END=$4) AND ($5 IS NULL OR r.workflow_instance_id LIKE $6 OR i.business_key LIKE $7) ORDER BY r.started_at DESC,r.id DESC LIMIT $8 OFFSET $9";

// Preserve decimal micro/nanosecond ordering across PHP SQL UTC and earlier
// native RFC3339 UTC strings without SQLite floating-point date conversion.
pub(super) const SQLITE_VISIBILITY_PAGE: &str = r#"
WITH runs AS (
    SELECT r.id,r.workflow_instance_id,r.workflow_type,r.queue,r.status,r.compatibility,r.started_at,r.closed_at,i.business_key,
        replace(replace(replace(r.started_at,'T',' '),'Z',''),'+00:00','') AS _dw_started_utc
    FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id
    WHERE r.namespace='default' AND i.namespace='default' AND ($1 IS NULL OR r.workflow_type=$2)
        AND ($3 IS NULL OR CASE WHEN r.status IN ('pending','running','waiting') THEN 'running' WHEN r.status='completed' THEN 'completed' ELSE 'failed' END=$4)
        AND ($5 IS NULL OR r.workflow_instance_id LIKE $6 OR i.business_key LIKE $7)
)
SELECT *,rtrim(rtrim(CASE WHEN length(_dw_started_utc)=19 THEN _dw_started_utc||'.0' ELSE _dw_started_utc END,'0'),'.') AS _dw_started_order
FROM runs ORDER BY _dw_started_order DESC,id DESC LIMIT $8 OFFSET $9
"#;

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
    pub(super) async fn list_workflows(&self, filter: &VisibilityFilter) -> Result<Value> {
        let rows = Self::query(DB::VISIBILITY_PAGE)
            .bind(filter.workflow_type.as_deref())
            .bind(filter.workflow_type.as_deref())
            .bind(filter.status.as_deref())
            .bind(filter.status.as_deref())
            .bind(filter.pattern.as_deref())
            .bind(filter.pattern.as_deref())
            .bind(filter.pattern.as_deref())
            .bind(filter.limit + 1)
            .bind(filter.offset)
            .fetch_all(&self.pool)
            .await?;
        let more = rows.len() > filter.limit as usize;
        let mut workflows = Vec::new();
        for row in rows.iter().take(filter.limit as usize) {
            if DB::optional_string(row, "compatibility")?.is_some() {
                return Err(refuse(StatusCode::CONFLICT, "unqualified_build_visibility"));
            }
            let status = DB::string(row, "status")?;
            let bucket = status_bucket(&status)?;
            workflows.push(json!({"workflow_id":DB::string(row,"workflow_instance_id")?, "run_id":DB::string(row,"id")?,
                "workflow_type":DB::string(row,"workflow_type")?, "business_key":DB::optional_string(row,"business_key")?,
                "status":status, "status_bucket":bucket, "task_queue":DB::string(row,"queue")?,
                "is_terminal":bucket!="running", "compatibility":null, "compatibility_status":"compatible",
                "compatibility_supported_in_fleet":true, "compatibility_fleet_reason":null,
                "started_at":DB::instant(row,"started_at")?, "closed_at":DB::optional_instant(row,"closed_at")?, "search_attributes":[]}));
        }
        let next =
            more.then(|| STANDARD.encode((filter.offset.saturating_add(filter.limit)).to_string()));
        Ok(response(
            json!({"workflow_count":workflows.len(), "workflows":workflows, "next_page_token":next}),
        ))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::codec::{Value as Payload, ValueCodec};

    #[tokio::test]
    async fn sqlite_visibility_preserves_nanosecond_ordering_and_id_ties() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("visibility.sqlite");
        let store = Store::<sqlx::Sqlite>::new(
            super::super::sqlite::open(path.to_str().unwrap())
                .await
                .unwrap(),
        );
        let input = json!({"codec":"avro", "blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
        let mut runs = Vec::new();
        for id in ["ordering-a", "ordering-b"] {
            let started = store.start(json!({"workflow_id":id,"workflow_type":"echo","task_queue":"test","input":input})).await.unwrap();
            runs.push(started["run_id"].as_str().unwrap().to_owned());
        }
        // Storage representation regression only: these rows are not shared
        // authored-workflow qualification observations or adjusted deadlines.
        for (a, b, winner) in [
            (
                "2026-01-01T00:00:00.000001999Z",
                "2026-01-01 00:00:00.000002",
                "ordering-b",
            ),
            (
                "2026-01-01T00:00:00.000001001Z",
                "2026-01-01 00:00:00.000001",
                "ordering-a",
            ),
            (
                "2026-01-01T00:00:00.000002000+00:00",
                "2026-01-01 00:00:00.000002",
                if runs[0] > runs[1] {
                    "ordering-a"
                } else {
                    "ordering-b"
                },
            ),
        ] {
            Store::<sqlx::Sqlite>::query("UPDATE workflow_runs SET started_at=CASE WHEN workflow_instance_id='ordering-a' THEN $1 ELSE $2 END")
                .bind(a).bind(b).execute(&store.pool).await.unwrap();
            let filter = VisibilityQuery {
                query: Some("ordering-".into()),
                page_size: Some("1".into()),
                ..Default::default()
            }
            .validate()
            .unwrap();
            let page = store.list_workflows(&filter).await.unwrap();
            assert_eq!(page["workflows"][0]["workflow_id"], winner);
            assert_eq!(page["next_page_token"], "MQ==");
        }
        store.close().await;
    }
}
