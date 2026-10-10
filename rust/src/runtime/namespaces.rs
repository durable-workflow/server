//! Namespace metadata in a verified native database.
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, now},
    text,
};
use axum::http::StatusCode;
use chrono::SecondsFormat;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};

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
    pub(super) async fn namespace_exists(&self, name: &str) -> Result<bool> {
        Ok(
            Self::scalar("SELECT COUNT(*) FROM workflow_namespaces WHERE name=$1")
                .bind(name)
                .fetch_one(&self.pool)
                .await?
                > 0,
        )
    }

    pub(super) async fn initialize_namespace_registry(&self) -> Result<()> {
        // Domain seed data; ownership and catalog preflight precede Runtime open.
        let mut tx = self.begin().await?;
        if Self::scalar("SELECT COUNT(*) FROM workflow_namespaces WHERE name='default'")
            .fetch_one(&mut *tx)
            .await?
            == 0
        {
            Self::insert_namespace(&mut tx, "default", Some("Default namespace"), "bounded", 30)
                .await?;
        }
        tx.commit().await?;
        Ok(())
    }

    async fn insert_namespace(
        tx: &mut Transaction<'_, DB>,
        name: &str,
        description: Option<&str>,
        mode: &str,
        days: i64,
    ) -> Result<()> {
        let timestamp = now();
        Self::query("INSERT INTO workflow_namespaces(name,description,retention_mode,retention_days,status,created_at,updated_at) VALUES ($1,$2,$3,NULLIF($4,0),'active',$5,$6)")
            .bind(name).bind(description).bind(mode).bind(days).bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp)).execute(&mut **tx).await?;
        Ok(())
    }

    fn namespace_metadata(row: &DB::Row) -> Result<Value> {
        Ok(
            json!({"name":DB::string(row,"name")?,"description":DB::optional_string(row,"description")?,
            "retention_mode":DB::string(row,"retention_mode")?,"retention_days":DB::optional_number(row,"retention_days")?,
            "status":DB::string(row,"status")?,"external_payload_storage":DB::optional_document_row(row,"external_payload_storage")?,
            "created_at":DB::optional_instant(row,"created_at")?.map(|t|t.to_rfc3339_opts(SecondsFormat::Secs,false)),
            "updated_at":DB::optional_instant(row,"updated_at")?.map(|t|t.to_rfc3339_opts(SecondsFormat::Secs,false))}),
        )
    }

    pub(super) async fn list_namespaces(&self) -> Result<Value> {
        let rows = Self::query("SELECT * FROM workflow_namespaces ORDER BY id")
            .fetch_all(&self.pool)
            .await?;
        Ok(
            json!({"namespaces":rows.iter().map(Self::namespace_metadata).collect::<Result<Vec<_>>>()?}),
        )
    }

    pub(super) async fn describe_namespace(&self, name: &str) -> Result<Value> {
        let name = name.to_ascii_lowercase();
        let row = Self::query("SELECT * FROM workflow_namespaces WHERE name=$1").bind(&name).fetch_optional(&self.pool).await?
            .ok_or_else(||RuntimeError::Protocol {status:StatusCode::NOT_FOUND,
                response:json!({"reason":"namespace_not_found","namespace":name,"message":format!("Namespace [{name}] not found.")})})?;
        Self::namespace_metadata(&row)
    }

    pub(super) async fn create_namespace(&self, body: Value) -> Result<Value> {
        let name = text(&body, "name")?;
        let description = body.get("description").filter(|v| !v.is_null());
        let mode = body
            .get("retention_mode")
            .map_or(Some("bounded"), Value::as_str);
        let days = match body.get("retention_days") {
            None => {
                if mode == Some("forever") {
                    Some(0)
                } else {
                    Some(30)
                }
            }
            Some(Value::Null) if mode == Some("forever") => Some(0),
            Some(value) => value.as_i64().filter(|n| (1..=365).contains(n)),
        };
        if name.len() > 128
            || !name
                .bytes()
                .all(|c| c.is_ascii_alphanumeric() || b"._-".contains(&c))
            || description.is_some_and(|v| v.as_str().is_none_or(|s| s.chars().count() > 1000))
            || !matches!(mode, Some("bounded" | "forever"))
            || days.is_none()
            || mode == Some("forever") && days != Some(0)
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_namespace_definition",
            ));
        }
        let name = name.to_ascii_lowercase();
        let mut tx = self.begin().await?;
        if Self::scalar("SELECT COUNT(*) FROM workflow_namespaces WHERE name=$1")
            .bind(&name)
            .fetch_one(&mut *tx)
            .await?
            > 0
        {
            return Err(RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"reason":"namespace_already_exists","namespace":name,"message":"Namespace already exists."}),
            });
        }
        Self::insert_namespace(
            &mut tx,
            &name,
            description.and_then(Value::as_str),
            mode.unwrap(),
            days.unwrap(),
        )
        .await?;
        let row = Self::query("SELECT * FROM workflow_namespaces WHERE name=$1")
            .bind(&name)
            .fetch_one(&mut *tx)
            .await?;
        let response = Self::namespace_metadata(&row)?;
        tx.commit().await?;
        Ok(response)
    }
}
