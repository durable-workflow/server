//! Targeted canonical activity outcomes without decoding unrelated history.
use super::{Result, RuntimeError, backend::Backend, store::Store, wire};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

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
    pub(crate) async fn activity_result(
        &self,
        workflow_id: &str,
        run_id: &str,
        activity_id: &str,
    ) -> Result<Value> {
        let identity = json!({"workflow_id":workflow_id,"run_id":run_id,"activity_id":activity_id});
        let missing = |reason, message| RuntimeError::Protocol {
            status: StatusCode::NOT_FOUND,
            response: json!({"workflow_id":workflow_id,"run_id":run_id,"activity_id":activity_id,
                "reason":reason,"message":message}),
        };
        let run = Self::query("SELECT id,workflow_instance_id,namespace,payload_codec,details_pruned_at FROM workflow_runs WHERE id=$1 AND workflow_instance_id=$2 AND namespace=$3")
            .bind(run_id).bind(workflow_id).bind(self.namespace.as_ref())
            .fetch_optional(&self.pool).await?
            .ok_or_else(|| missing("run_not_found", "Workflow run not found."))?;
        // MySQL's default identifier collation is case insensitive. A SQL
        // match cannot authorize an alias of a different durable identity.
        if DB::string(&run, "id")? != run_id
            || DB::string(&run, "workflow_instance_id")? != workflow_id
            || DB::optional_string(&run, "namespace")?.as_deref() != Some(self.namespace.as_ref())
        {
            return Err(missing("run_not_found", "Workflow run not found."));
        }
        let event = Self::query(DB::ACTIVITY_RESULT_EVENT_SQL)
            .bind(run_id)
            .bind(activity_id)
            .bind(activity_id)
            .fetch_optional(&self.pool)
            .await?;
        if let Some(event) = event {
            let mut identity = identity;
            identity["history_sequence"] = json!(DB::number(&event, "sequence")?);
            return Ok(project(
                identity,
                activity_id,
                &DB::string(&event, "event_type")?,
                &DB::document_row(&event, "payload")?,
                DB::optional_string(&run, "payload_codec")?.as_deref(),
            ));
        }
        let row = Self::query(
            "SELECT id,workflow_run_id FROM activity_executions WHERE id=$1 AND workflow_run_id=$2",
        )
        .bind(activity_id)
        .bind(run_id)
        .fetch_optional(&self.pool)
        .await?;
        if row.as_ref().is_none_or(|row| {
            DB::string(row, "id").ok().as_deref() != Some(activity_id)
                || DB::string(row, "workflow_run_id").ok().as_deref() != Some(run_id)
        }) {
            return Err(missing(
                "activity_not_found",
                "Activity not found in this run.",
            ));
        }
        let pruned = DB::optional_instant(&run, "details_pruned_at")?.is_some();
        Ok(state(
            identity,
            if pruned { "pruned" } else { "unsupported" },
            "activity_result_history_unavailable",
        ))
    }
}

fn state(mut identity: Value, state: &str, reason: &str) -> Value {
    identity["state"] = json!(state);
    identity["available"] = json!(false);
    identity["reason"] = json!(reason);
    identity
}

fn nonempty(value: &Value) -> Option<&str> {
    value.as_str().filter(|text| !text.is_empty())
}

fn payload(value: &Value) -> Option<&Value> {
    (value.is_string() || value.is_object() || value.is_array()).then_some(value)
}

fn envelope(value: &Value) -> Value {
    value.as_str().map_or_else(|| value.clone(), wire)
}

fn project(
    mut identity: Value,
    activity_id: &str,
    event: &str,
    body: &Value,
    codec: Option<&str>,
) -> Value {
    let snapshot = &body["activity"];
    let id = nonempty(&snapshot["id"]).or_else(|| nonempty(&body["activity_execution_id"]));
    if id != Some(activity_id)
        || body
            .get("activity_execution_id")
            .is_some_and(|id| !id.is_null() && id != activity_id)
    {
        return state(
            identity,
            "unsupported",
            "activity_result_history_identity_mismatch",
        );
    }
    let local =
        |value: &Value| value["local_activity"] == true || value["execution_mode"] == "local";
    let local =
        if snapshot.get("local_activity").is_some() || snapshot.get("execution_mode").is_some() {
            local(snapshot)
        } else {
            local(body)
        };
    if local {
        return state(
            identity,
            "unsupported",
            "local_activity_result_inspection_unsupported",
        );
    }
    let status = nonempty(&snapshot["status"]).unwrap_or(match event {
        "ActivityScheduled" | "ActivityRetryScheduled" => "pending",
        "ActivityStarted" | "ActivityHeartbeatRecorded" => "running",
        "ActivityCompleted" => "completed",
        "ActivityFailed" | "ActivityTimedOut" => "failed",
        "ActivityCancelled" => "cancelled",
        _ => "unsupported",
    });
    match status {
        "pending" | "running" => return state(identity, "pending", "activity_result_pending"),
        "failed" => return state(identity, "failed", "activity_result_failed"),
        "cancelled" => return state(identity, "cancelled", "activity_result_cancelled"),
        "completed" if event == "ActivityCompleted" => {}
        _ => {
            return state(
                identity,
                "unsupported",
                "activity_result_terminal_event_unavailable",
            );
        }
    }
    if nonempty(&snapshot["payload_codec"])
        .or(codec)
        .is_some_and(|codec| codec != "avro")
    {
        return state(identity, "unsupported", "activity_result_codec_unsupported");
    }
    let result = payload(&snapshot["result"])
        .or_else(|| payload(&body["result"]))
        .map(envelope)
        .unwrap_or(Value::Null);
    if result.is_object() && result["codec"] != "avro" {
        return state(identity, "unsupported", "activity_result_codec_unsupported");
    }
    if result.as_object().is_none_or(|object| object.len() != 2)
        || (!result["blob"].is_string() && !result["external_payload"].is_object())
    {
        return state(
            identity,
            "unavailable",
            "activity_result_envelope_unavailable",
        );
    }
    if body
        .get("result")
        .is_some_and(|original| !original.is_null() && envelope(original) != result)
    {
        return state(
            identity,
            "unsupported",
            "activity_result_history_value_mismatch",
        );
    }
    identity["state"] = json!("available");
    identity["available"] = json!(true);
    identity["reason"] = Value::Null;
    identity["result_envelope"] = result;
    identity
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn canonical_snapshot_controls_state_and_missing_value_is_not_null() {
        let cases = [
            (
                "ActivityFailed",
                json!({"status":"pending"}),
                "pending",
                "activity_result_pending",
            ),
            (
                "ActivityTimedOut",
                json!({}),
                "failed",
                "activity_result_failed",
            ),
            (
                "ActivityCancelled",
                json!({}),
                "cancelled",
                "activity_result_cancelled",
            ),
            (
                "ActivityCompleted",
                json!({}),
                "unavailable",
                "activity_result_envelope_unavailable",
            ),
            (
                "ActivityStarted",
                json!({"status":"completed"}),
                "unsupported",
                "activity_result_terminal_event_unavailable",
            ),
            (
                "ActivityCompleted",
                json!({"local_activity":true}),
                "unsupported",
                "local_activity_result_inspection_unsupported",
            ),
            (
                "ActivityCompleted",
                json!({"execution_mode":"local"}),
                "unsupported",
                "local_activity_result_inspection_unsupported",
            ),
            (
                "ActivityCompleted",
                json!({"payload_codec":"json"}),
                "unsupported",
                "activity_result_codec_unsupported",
            ),
            (
                "ActivityCompleted",
                json!({"id":"other"}),
                "unsupported",
                "activity_result_history_identity_mismatch",
            ),
            (
                "ActivityCompleted",
                json!({"result":{"codec":"avro","blob":"conflicting"}}),
                "unsupported",
                "activity_result_history_value_mismatch",
            ),
        ];
        for (event, mut snapshot, expected, reason) in cases {
            snapshot
                .as_object_mut()
                .unwrap()
                .entry("id")
                .or_insert(json!("activity"));
            let mut body = json!({"activity_execution_id":"activity","activity":snapshot});
            if reason == "activity_result_history_value_mismatch" {
                body["result"] = json!("original");
            }
            let actual = project(json!({}), "activity", event, &body, Some("avro"));
            assert_eq!(actual["state"], expected, "{body}");
            assert_eq!(actual["reason"], reason, "{body}");
            assert_eq!(actual["available"], false);
            assert!(actual.get("result_envelope").is_none());
        }
    }

    #[test]
    fn retained_result_accepts_nested_and_top_level_native_history() {
        for result in ["encoded-null", "encoded-false"] {
            for body in [
                json!({"activity_execution_id":"activity","result":result}),
                json!({"activity":{"id":"activity","result":{"blob":result,"codec":"avro"}},"result":result}),
            ] {
                let actual = project(
                    json!({}),
                    "activity",
                    "ActivityCompleted",
                    &body,
                    Some("avro"),
                );
                assert_eq!(actual["state"], "available", "{actual}");
                assert_eq!(
                    actual["result_envelope"],
                    json!({"codec":"avro","blob":result})
                );
            }
        }
    }
}
