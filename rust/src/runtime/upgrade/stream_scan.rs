//! Message and workflow stream rows that hold an external payload
//! reference. Native carries streams over unchanged, but it neither resolves
//! nor deletes stored external payload objects, so takeover refuses such a
//! row as it refuses any other external payload. The report counts rows and
//! never names streams.
use crate::runtime::{Result, backend::Backend};
use serde_json::{Value, json};
use sqlx::{Executor, IntoArguments};

pub(super) async fn inspect<DB: Backend>(connection: &mut DB::Connection) -> Result<Value>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
{
    let row = sqlx::query(sqlx::AssertSqlSafe(DB::statement(DB::STREAM_REFERENCES)))
        .fetch_one(&mut *connection)
        .await?;
    Ok(json!({"references": DB::number(&row, "n")?}))
}
