//! Explicit metadata columns keep observation independent of application hydration.
use super::{
    Result, RuntimeError,
    backend::Backend,
    control_plane::Operation,
    run_observation::{Options, Window, command_contract},
    store::Store,
};
use axum::http::StatusCode;
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(super) fn terminal(status: &str) -> bool {
    matches!(status, "completed" | "failed" | "cancelled" | "terminated")
}

pub(super) fn iso(time: DateTime<Utc>) -> String {
    time.to_rfc3339_opts(SecondsFormat::Secs, false)
}

pub(super) fn fields<DB: Backend>(
    row: &DB::Row,
    strings: &[&str],
    numbers: &[&str],
    times: &[&str],
) -> Result<Value> {
    let mut value = json!({});
    for field in strings {
        value[*field] = json!(DB::optional_string(row, field)?);
    }
    for field in numbers {
        value[*field] = json!(DB::optional_number(row, field)?);
    }
    for field in times {
        value[*field] = json!(DB::optional_instant(row, field)?.map(iso));
    }
    Ok(value)
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
    pub(crate) async fn run_observation(
        &self,
        workflow: &str,
        requested_run: Option<&str>,
        options: &Options,
        key: &str,
    ) -> Result<Value> {
        let missing = || {
            RuntimeError::Protocol { status: StatusCode::NOT_FOUND,
            response: Operation::Observe.response(json!({"workflow_id":workflow,"run_id":requested_run,
                "reason":if requested_run.is_some() {"run_not_found"} else {"current_run_observation_unavailable"},
                "message":"Workflow run observation not found."})) }
        };
        let instance = Self::query("SELECT id,namespace,current_run_id FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?.ok_or_else(missing)?;
        if DB::string(&instance, "id")? != workflow
            || DB::optional_string(&instance, "namespace")?.as_deref()
                != Some(self.namespace.as_ref())
        {
            return Err(missing());
        }
        let current_id = DB::optional_string(&instance, "current_run_id")?;
        let run_id = requested_run
            .or(current_id.as_deref())
            .ok_or_else(missing)?;
        let row = Self::query("SELECT id,workflow_instance_id,namespace,workflow_class,workflow_type,run_number,status,closed_reason,business_key,visibility_labels,compatibility,connection,queue,started_at,closed_at,archived_at,details_pruned_at,created_at,updated_at,execution_deadline_at,run_deadline_at FROM workflow_runs WHERE id=$1 AND workflow_instance_id=$2 AND namespace=$3")
            .bind(run_id).bind(workflow).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?.ok_or_else(missing)?;
        if DB::string(&row, "id")? != run_id
            || DB::string(&row, "workflow_instance_id")? != workflow
            || DB::optional_string(&row, "namespace")?.as_deref() != Some(self.namespace.as_ref())
        {
            return Err(missing());
        }
        // Decode after identity resolution, just as the PHP controller does.
        let window = options
            .cursor
            .as_deref()
            .map(|token| Window::decode(token, key, &self.namespace, workflow, run_id))
            .transpose()?;
        let now = Utc::now();
        let status = DB::string(&row, "status")?;
        let pruned = DB::optional_instant(&row, "details_pruned_at")?.is_some();
        let mut result = fields::<DB>(
            &row,
            &[
                "id",
                "namespace",
                "workflow_type",
                "status",
                "closed_reason",
                "business_key",
                "compatibility",
                "connection",
                "queue",
            ],
            &["run_number"],
            &[
                "started_at",
                "closed_at",
                "archived_at",
                "details_pruned_at",
                "created_at",
                "updated_at",
                "execution_deadline_at",
                "run_deadline_at",
            ],
        )?;
        result["class"] = json!(DB::string(&row, "workflow_class")?);
        result["visibility_labels"] =
            DB::optional_document_row(&row, "visibility_labels")?.unwrap_or(json!([]));
        result["instance_id"] = json!(workflow);
        result["workflow_id"] = json!(workflow);
        result["run_id"] = json!(run_id);
        result["selected_run_id"] = json!(run_id);
        result["task_queue"] = result["queue"].clone();
        result["is_terminal"] = json!(terminal(&status));
        result["read_mode"] = json!("bounded");
        result["observed_at"] = json!(iso(now));
        result["history_audit"] = json!("not_evaluated");
        result["current_run_audit"] = json!("not_evaluated");
        result["current_run_source"] = json!("workflow_instances.current_run_id");
        let current = Self::query("SELECT id,workflow_instance_id,namespace,status FROM workflow_runs WHERE id=$1 AND workflow_instance_id=$2 AND namespace=$3")
            .bind(current_id.as_deref()).bind(workflow).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?;
        let current = current.filter(|row| {
            DB::optional_string(row, "id").ok().flatten() == current_id
                && DB::string(row, "workflow_instance_id").ok().as_deref() == Some(workflow)
                && DB::optional_string(row, "namespace")
                    .ok()
                    .flatten()
                    .as_deref()
                    == Some(self.namespace.as_ref())
        });
        result["current_run_id"] = json!(current.as_ref().and(current_id.as_deref()));
        result["current_run_status"] = json!(
            current
                .as_ref()
                .map(|r| DB::string(r, "status"))
                .transpose()?
        );
        result["is_current_run"] = json!(
            current
                .as_ref()
                .map(|_| current_id.as_deref() == Some(run_id))
        );
        result["current_run_state"] = json!(if current.is_some() {
            "observed"
        } else {
            "unavailable"
        });
        let summary = Self::query("SELECT id,workflow_instance_id,namespace,duration_ms,exception_count,history_event_count,history_size_bytes,history_fan_out,liveness_state,liveness_reason,updated_at FROM workflow_run_summaries WHERE id=$1 AND workflow_instance_id=$2 AND namespace=$3")
            .bind(run_id).bind(workflow).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?;
        let summary = summary.filter(|r| {
            DB::string(r, "id").ok().as_deref() == Some(run_id)
                && DB::string(r, "workflow_instance_id").ok().as_deref() == Some(workflow)
                && DB::optional_string(r, "namespace")
                    .ok()
                    .flatten()
                    .as_deref()
                    == Some(self.namespace.as_ref())
        });
        result["summary_state"] = json!(if summary.is_some() {
            "observed"
        } else {
            "unavailable"
        });
        result["summary_observed_at"] = json!(
            summary
                .as_ref()
                .map(|r| DB::optional_instant(r, "updated_at"))
                .transpose()?
                .flatten()
                .map(iso)
        );
        for field in [
            "duration_ms",
            "exception_count",
            "history_event_count",
            "history_size_bytes",
            "history_fan_out",
        ] {
            result[field] = json!(
                summary
                    .as_ref()
                    .map(|r| DB::optional_number(r, field))
                    .transpose()?
                    .flatten()
            );
        }
        for field in ["liveness_state", "liveness_reason"] {
            result[field] = json!(
                summary
                    .as_ref()
                    .map(|r| DB::optional_string(r, field))
                    .transpose()?
                    .flatten()
            );
        }
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(run_id).fetch_optional(&self.pool).await?.as_ref().map(|r| DB::optional_document_row(r,"payload").map(|v| v.unwrap_or(Value::Null))).transpose()?;
        result["command_contract"] = command_contract(started.as_ref());
        result["workflow_definition_fingerprint"] = json!(
            started
                .as_ref()
                .and_then(|v| v["workflow_definition_fingerprint"].as_str())
                .filter(|s| !s.is_empty())
        );
        result["history"] = self
            .observation_history(workflow, run_id, pruned, options.limit, window, key)
            .await?;
        result["parents"] = self
            .observation_relationships(workflow, run_id, pruned, true)
            .await?;
        result["children"] = self
            .observation_relationships(workflow, run_id, pruned, false)
            .await?;
        result["recent_failures"] = self
            .observation_failures(workflow, run_id, pruned, key)
            .await?;
        result["current_waits"] = self
            .observation_waits(workflow, run_id, pruned, terminal(&status), now)
            .await?;
        result["search_attributes"] = self
            .observation_attributes(workflow, run_id, &options.keys)
            .await?;
        Ok(result)
    }

    async fn observation_history(
        &self,
        workflow: &str,
        run: &str,
        pruned: bool,
        limit: i64,
        window: Option<Window>,
        key: &str,
    ) -> Result<Value> {
        let window = match window {
            Some(window) => window,
            None => {
                // MariaDB promotes COALESCE(unsigned sequence, 0) to DECIMAL.
                // Keep the stored integer type and default an empty history here.
                let row = Self::query("SELECT MAX(sequence) AS ceiling FROM workflow_history_events WHERE workflow_run_id=$1")
                    .bind(run).fetch_one(&self.pool).await?;
                Window::new(
                    &self.namespace,
                    workflow,
                    run,
                    0,
                    DB::optional_number(&row, "ceiling")?.unwrap_or(0),
                )
            }
        };
        let rows = Self::query("SELECT id,sequence,event_type,payload,recorded_at,workflow_task_id,workflow_command_id FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence>$2 AND sequence<=$3 ORDER BY sequence LIMIT $4")
            .bind(run).bind(window.after).bind(window.through).bind(limit+1).fetch_all(&self.pool).await?;
        let more = rows.len() > limit as usize;
        let mut events = vec![];
        for row in rows.iter().take(limit as usize) {
            let mut event = fields::<DB>(
                row,
                &[
                    "id",
                    "event_type",
                    "workflow_task_id",
                    "workflow_command_id",
                ],
                &["sequence"],
                &["recorded_at"],
            )?;
            event["payload"] = DB::optional_document_row(row, "payload")?.unwrap_or(Value::Null);
            events.push(event);
        }
        let first = events.first().map(|e| e["sequence"].clone());
        let last = events.last().map(|e| e["sequence"].clone());
        let next = if more {
            Some(
                Window::new(
                    &self.namespace,
                    workflow,
                    run,
                    last.as_ref().unwrap().as_i64().unwrap(),
                    window.through,
                )
                .encode(key)?,
            )
        } else {
            None
        };
        Ok(
            json!({"instance_id":workflow,"run_id":run,"namespace":self.namespace.as_ref(),"source":"workflow_history_events",
            "returned_count":events.len(),"events":events,"limit":limit,"after_sequence":window.after,"through_sequence":window.through,
            "history_window_from_start":window.after==0,"first_sequence":first,"last_sequence":last,"has_more":more,
            "next_sequence":if more {last} else {None},"next_page_token":next,"details_state":if pruned {"pruned"} else {"retained"}}),
        )
    }

    async fn observation_relationships(
        &self,
        workflow: &str,
        run: &str,
        pruned: bool,
        parents: bool,
    ) -> Result<Value> {
        let max = if parents {
            "SELECT MAX(id) AS ceiling FROM workflow_links WHERE child_workflow_run_id=$1 AND child_workflow_instance_id=$2"
        } else {
            "SELECT MAX(id) AS ceiling FROM workflow_links WHERE parent_workflow_run_id=$1 AND parent_workflow_instance_id=$2"
        };
        let ceiling = Self::query(max)
            .bind(run)
            .bind(workflow)
            .fetch_one(&self.pool)
            .await?;
        let ceiling = DB::optional_string(&ceiling, "ceiling")?.unwrap_or_default();
        let sql = if parents { PARENTS } else { CHILDREN };
        let rows = Self::query(sql)
            .bind(self.namespace.as_ref())
            .bind(run)
            .bind(workflow)
            .bind(&ceiling)
            .fetch_all(&self.pool)
            .await?;
        let more = rows.len() > 50;
        let mut links = vec![];
        for row in rows.iter().take(50) {
            let mut item = fields::<DB>(
                row,
                &[
                    "link_id",
                    "link_type",
                    "parent_close_policy",
                    "instance_id",
                    "run_id",
                ],
                &["sequence"],
                &[],
            )?;
            item["is_primary_parent"] = json!(DB::number(row, "primary_parent")? != 0);
            let available = DB::optional_string(row, "related_id")?.as_deref()
                == item["run_id"].as_str()
                && DB::optional_string(row, "related_instance")?.as_deref()
                    == item["instance_id"].as_str()
                && DB::optional_string(row, "related_namespace")?.as_deref()
                    == Some(self.namespace.as_ref());
            for field in ["class", "workflow_type", "status", "closed_reason"] {
                item[field] = if available {
                    json!(DB::optional_string(row, field)?)
                } else {
                    Value::Null
                };
            }
            item["is_terminal"] = if available {
                json!(terminal(item["status"].as_str().unwrap_or_default()))
            } else {
                Value::Null
            };
            item["closed_at"] = if available {
                json!(DB::optional_instant(row, "closed_at")?.map(iso))
            } else {
                Value::Null
            };
            item["metadata_state"] = json!(if available {
                "available"
            } else {
                "unavailable"
            });
            item["details_state"] = json!(if !available {
                "unavailable"
            } else if DB::optional_instant(row, "details_pruned_at")?.is_some() {
                "pruned"
            } else {
                "retained"
            });
            links.push(item);
        }
        Ok(
            json!({"instance_id":workflow,"run_id":run,"namespace":self.namespace.as_ref(),"direction":if parents {"parents"} else {"children"},
            "source":"workflow_links","status_source":"workflow_runs","history_audit":"not_evaluated","returned_count":links.len(),
            "next_link_id":if more {links.last().map(|v| v["link_id"].clone())} else {None},"relationships":links,"limit":50,"after_link_id":"",
            "through_link_id":ceiling,"has_more":more,"details_state":if pruned {"pruned"} else {"retained"}}),
        )
    }

    async fn observation_failures(
        &self,
        workflow: &str,
        run: &str,
        pruned: bool,
        key: &str,
    ) -> Result<Value> {
        let rows = Self::query("SELECT id,source_kind,source_id,propagation_kind,failure_category,CASE WHEN non_retryable THEN 1 ELSE 0 END AS non_retryable,CASE WHEN handled THEN 1 ELSE 0 END AS handled,exception_class,message,file,line,trace_preview,created_at FROM workflow_failures WHERE workflow_run_id=$1 ORDER BY created_at DESC,id DESC LIMIT 11")
            .bind(run).fetch_all(&self.pool).await?;
        let more = rows.len() > 10;
        let mut failures = vec![];
        for row in rows.iter().take(10) {
            let mut item = fields::<DB>(
                row,
                &[
                    "id",
                    "source_kind",
                    "source_id",
                    "propagation_kind",
                    "failure_category",
                    "exception_class",
                    "message",
                    "file",
                    "trace_preview",
                ],
                &["line"],
                &["created_at"],
            )?;
            item["non_retryable"] = json!(DB::number(row, "non_retryable")? != 0);
            item["handled"] = json!(DB::number(row, "handled")? != 0);
            item["handled_source"] = json!("workflow_failures");
            let event = Self::query(DB::OBSERVATION_FAILURE_EVENT)
                .bind(run)
                .bind(item["id"].as_str().unwrap())
                .fetch_optional(&self.pool)
                .await?;
            let mut supporting = json!({"state":if pruned {"pruned"} else {"unavailable"}});
            item["event_sequence"] = Value::Null;
            if let Some(event) = event {
                supporting = fields::<DB>(
                    &event,
                    &["id", "event_type"],
                    &["sequence"],
                    &["recorded_at"],
                )?;
                let sequence = DB::number(&event, "sequence")?;
                supporting["state"] = json!("retained");
                supporting["after_sequence"] = json!((sequence - 1).max(0));
                supporting["next_page_token"] = json!(
                    Window::new(
                        &self.namespace,
                        workflow,
                        run,
                        (sequence - 1).max(0),
                        sequence
                    )
                    .encode(key)?
                );
                item["event_sequence"] = json!(sequence);
            }
            item["supporting_event"] = supporting;
            failures.push(item);
        }
        Ok(
            json!({"instance_id":workflow,"run_id":run,"namespace":self.namespace.as_ref(),"source":"workflow_failures",
            "history_audit":"not_evaluated","state":if pruned {"pruned"} else {"partial"},"returned_count":failures.len(),
            "failures":failures,"total_count":null,"limit":10,"has_more":more}),
        )
    }

    async fn observation_attributes(
        &self,
        workflow: &str,
        run: &str,
        keys: &[String],
    ) -> Result<Value> {
        if keys.is_empty() {
            return Ok(json!([]));
        }
        let mut query = Self::query(DB::OBSERVATION_ATTRIBUTES)
            .bind(run)
            .bind(workflow);
        for index in 0..20 {
            query = query.bind(keys.get(index).map(String::as_str));
        }
        let rows = query.fetch_all(&self.pool).await?;
        let mut values = serde_json::Map::new();
        for row in rows {
            let name = DB::string(&row, "attribute_key")?;
            if !keys.contains(&name) {
                continue;
            }
            let value = match DB::string(&row, "type")?.as_str() {
                "string" => json!(DB::optional_string(&row, "value_string")?),
                "keyword" => json!(DB::optional_string(&row, "value_keyword")?),
                "keyword_list" => {
                    DB::optional_document_row(&row, "value_keyword_list")?.unwrap_or(json!([]))
                }
                "int" => json!(DB::optional_number(&row, "value_int")?),
                "float" => json!(DB::optional_float(&row, "value_float")?),
                "bool" => json!(DB::optional_number(&row, "value_bool")?.map(|v| v != 0)),
                "datetime" => json!(
                    DB::optional_instant(&row, "value_datetime")?
                        .map(|v| v.to_rfc3339_opts(SecondsFormat::Micros, true))
                ),
                _ => Value::Null,
            };
            values.insert(name, value);
        }
        Ok(if values.is_empty() {
            json!([])
        } else {
            Value::Object(values)
        })
    }
}

const PARENTS: &str = "SELECT l.id AS link_id,l.link_type,l.sequence,CASE WHEN l.is_primary_parent THEN 1 ELSE 0 END AS primary_parent,l.parent_close_policy,l.parent_workflow_instance_id AS instance_id,l.parent_workflow_run_id AS run_id,r.id AS related_id,r.workflow_instance_id AS related_instance,r.namespace AS related_namespace,r.workflow_class AS class,r.workflow_type,r.status,r.closed_reason,r.closed_at,r.details_pruned_at FROM workflow_links l LEFT JOIN workflow_runs r ON r.id=l.parent_workflow_run_id AND r.workflow_instance_id=l.parent_workflow_instance_id AND r.namespace=$1 WHERE l.child_workflow_run_id=$2 AND l.child_workflow_instance_id=$3 AND l.id>'' AND l.id<=$4 ORDER BY l.id LIMIT 51";
const CHILDREN: &str = "SELECT l.id AS link_id,l.link_type,l.sequence,CASE WHEN l.is_primary_parent THEN 1 ELSE 0 END AS primary_parent,l.parent_close_policy,l.child_workflow_instance_id AS instance_id,l.child_workflow_run_id AS run_id,r.id AS related_id,r.workflow_instance_id AS related_instance,r.namespace AS related_namespace,r.workflow_class AS class,r.workflow_type,r.status,r.closed_reason,r.closed_at,r.details_pruned_at FROM workflow_links l LEFT JOIN workflow_runs r ON r.id=l.child_workflow_run_id AND r.workflow_instance_id=l.child_workflow_instance_id AND r.namespace=$1 WHERE l.parent_workflow_run_id=$2 AND l.parent_workflow_instance_id=$3 AND l.id>'' AND l.id<=$4 ORDER BY l.id LIMIT 51";
