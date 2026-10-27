//! PHP 2.11.3's service-call dispatch outbox. PHP schedules a call's handler
//! there and a later pass dispatches it; native runs the handler when it
//! accepts the call, so it cannot resume a dispatch that is still scheduled or
//! claimed. A completed dispatch is only PHP's bookkeeping. The report counts
//! rows and never names calls.
use crate::runtime::{Result, backend::Backend};
use serde_json::{Value, json};
use sqlx::{Executor, IntoArguments};
use std::collections::BTreeMap;

const PENDING: &str = "SELECT COUNT(*) AS n FROM workflow_service_call_dispatches WHERE state<>'completed'";

pub(super) async fn inspect<DB: Backend>(
    connection: &mut DB::Connection,
    counts: &BTreeMap<String, i64>,
) -> Result<Value>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
{
    let rows = counts.get("workflow_service_call_dispatches").copied().unwrap_or(0);
    let pending = if rows == 0 {
        0
    } else {
        let row = sqlx::query(sqlx::AssertSqlSafe(DB::statement(PENDING)))
            .fetch_one(&mut *connection)
            .await?;
        DB::number(&row, "n")?
    };
    Ok(json!({"dispatches": rows, "pending": pending}))
}
