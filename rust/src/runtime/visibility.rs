//! Bounded visibility reads from authoritative native runs. Persisted PHP
//! summary projections and Waterline consumption require their own fixtures.
use axum::http::StatusCode;
use base64::{Engine, engine::general_purpose::STANDARD};
use serde::Deserialize;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

use std::collections::{BTreeMap, HashMap};
use std::sync::LazyLock;

use super::{Result, RuntimeError, backend::Backend, refuse, search_attribute_upserts::Typed, store::Store};

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
    /// A structured query, which replaces the free-text `pattern`.
    visibility: Option<super::visibility_query::Parsed>,
    limit: i64,
    offset: i64,
}

impl VisibilityFilter {
    /// The run list for one workflow type, as a status bucket and page.
    pub(super) fn of_type(workflow_type: &str, status: Option<String>, limit: i64, offset: i64) -> Self {
        Self { workflow_type: Some(workflow_type.to_owned()), status, pattern: None, visibility: None, limit, offset }
    }
}

/// Candidate runs read per page while a structured query filters them.
const QUERY_SCAN_PAGE: i64 = 500;

impl VisibilityQuery {
    pub(super) fn validate(self) -> Result<VisibilityFilter> {
        if self
            .status
            .as_deref()
            .is_some_and(|value| {
                !matches!(value, "running" | "completed" | "failed" | "cancelled" | "terminated")
            })
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
        // `WorkflowVisibilityQuery::apply`; a plain term keeps the free-text
        // workflow ID and business key search.
        let term = self.query.as_deref().unwrap_or("").trim_matches([' ', '\t', '\n', '\r', '\0', '\x0b']);
        let visibility = if term.is_empty() {
            None
        } else {
            super::visibility_query::parse(term).map_err(query_failure)?
        };
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
            pattern: (!term.is_empty() && visibility.is_none()).then(|| format!("%{term}%")),
            visibility,
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
            "rejected_aliases":{"cancelled":"failed","terminated":"failed","pending":"running","waiting":"running"}}}},
            "update":{"fields":{"wait_for":{"canonical_values":["accepted","completed"]},
                "wait_timeout_seconds":{"minimum":1,"maximum":super::command_requests::UPDATE_WAIT_TIMEOUT_SECONDS_MAX}}}}})
}

fn response(body: Value) -> Value {
    super::control_plane::Operation::List.response(body)
}

fn query_failure(message: String) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: response(json!({"reason":"validation_failed", "message":message,
            "errors":{"query":[message]}, "validation_errors":{"query":[message]}})),
    }
}

fn validation(field: &'static str, message: &'static str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: response(json!({"reason":"validation_failed", "message":message,
            "errors":{(field):[message]}, "validation_errors":{(field):[message]}})),
    }
}

pub(super) const VISIBILITY_PAGE: &str = "SELECT r.id,r.workflow_instance_id,r.workflow_type,r.queue,r.status,r.compatibility,r.started_at,r.closed_at,COALESCE(r.business_key,i.business_key) AS business_key FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id LEFT JOIN workflow_run_summaries s ON s.id=r.id AND r.import_source='waterline_v1_projection' WHERE r.namespace=$1 AND i.namespace=$2 AND ($3 IS NULL OR r.workflow_type=$4) AND ($5 IS NULL OR CASE WHEN r.status IN ('pending','running','waiting') THEN 'running' WHEN r.status='completed' THEN 'completed' ELSE 'failed' END=$6 OR r.status=$7) AND ($8 IS NULL OR r.workflow_instance_id LIKE $9 OR COALESCE(r.business_key,i.business_key) LIKE $10) ORDER BY COALESCE(s.sort_timestamp,r.started_at) DESC,r.id DESC LIMIT $11 OFFSET $12";

// Preserve decimal micro/nanosecond ordering across PHP SQL UTC and earlier
// native RFC3339 UTC strings without SQLite floating-point date conversion.
// A Waterline v1 projection sorts by its summary's sort time, as PHP's
// summary-ordered list places it; other runs keep their start time.
pub(super) const SQLITE_VISIBILITY_PAGE: &str = r#"
WITH runs AS (
    SELECT r.id,r.workflow_instance_id,r.workflow_type,r.queue,r.status,r.compatibility,r.started_at,r.closed_at,COALESCE(r.business_key,i.business_key) AS business_key,
        replace(replace(replace(COALESCE(s.sort_timestamp,r.started_at),'T',' '),'Z',''),'+00:00','') AS _dw_started_utc
    FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id
    LEFT JOIN workflow_run_summaries s ON s.id=r.id AND r.import_source='waterline_v1_projection'
    WHERE r.namespace=$1 AND i.namespace=$2 AND ($3 IS NULL OR r.workflow_type=$4)
        AND ($5 IS NULL OR CASE WHEN r.status IN ('pending','running','waiting') THEN 'running' WHEN r.status='completed' THEN 'completed' ELSE 'failed' END=$6 OR r.status=$7)
        AND ($8 IS NULL OR r.workflow_instance_id LIKE $9 OR COALESCE(r.business_key,i.business_key) LIKE $10)
)
SELECT *,rtrim(rtrim(CASE WHEN length(_dw_started_utc)=19 THEN _dw_started_utc||'.0' ELSE _dw_started_utc END,'0'),'.') AS _dw_started_order
FROM runs ORDER BY _dw_started_order DESC,id DESC LIMIT $11 OFFSET $12
"#;

/// Text values whose MySQL collation keys one statement reads.
const COLLATION_BATCH: usize = 64;

/// One statement giving each bound text's comparison key under the published
/// MySQL schema's `utf8mb4_unicode_ci`, which ignores case and accents and,
/// as a PAD SPACE collation, trailing spaces: the collation weight of the
/// value without trailing spaces. Unused positions are bound NULL.
static COLLATION_KEYS: LazyLock<String> = LazyLock::new(|| {
    (1..=COLLATION_BATCH)
        .map(|position| {
            format!("SELECT {position} AS position,HEX(WEIGHT_STRING(TRIM(TRAILING ' ' FROM CAST(${position} AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))) AS collation_key")
        })
        .collect::<Vec<_>>()
        .join(" UNION ALL ")
});

/// A value bound to a built statement.
enum Bound {
    Text(String),
    Int(i64),
}

/// A statement built from fixed SQL text with every value bound: numbered
/// `$1`, `$2`, ... in order, or `?` on MySQL.
struct Statement {
    text: String,
    binds: Vec<Bound>,
    mysql: bool,
}

impl Statement {
    fn new(mysql: bool) -> Self {
        Self { text: String::new(), binds: Vec::new(), mysql }
    }

    fn push(&mut self, text: &str) -> &mut Self {
        self.text.push_str(text);
        self
    }

    fn bind(&mut self, value: Bound) -> &mut Self {
        self.binds.push(value);
        if self.mysql {
            self.text.push('?');
        } else {
            self.text.push_str(&format!("${}", self.binds.len()));
        }
        self
    }

    fn text(&mut self, value: &str) -> &mut Self {
        self.bind(Bound::Text(value.to_owned()))
    }

    fn int(&mut self, value: i64) -> &mut Self {
        self.bind(Bound::Int(value))
    }

    fn texts(&mut self, values: &[String]) -> &mut Self {
        self.push("(");
        for (index, value) in values.iter().enumerate() {
            if index > 0 {
                self.push(",");
            }
            self.text(value);
        }
        self.push(")")
    }

    fn ints(&mut self, values: &[i64]) -> &mut Self {
        self.push("(");
        for (index, value) in values.iter().enumerate() {
            if index > 0 {
                self.push(",");
            }
            self.int(*value);
        }
        self.push(")")
    }

    /// The query's narrowing (`Compiled::narrowing`) as one condition.
    fn narrowing(&mut self, groups: &[Vec<super::visibility_query::Narrowing>]) -> &mut Self {
        use super::visibility_query::{AttributeTest, Narrowing};
        let key = if self.mysql { "a.`key`" } else { "a.\"key\"" };
        self.push(" AND (");
        for (index, group) in groups.iter().enumerate() {
            if index > 0 {
                self.push(" OR ");
            }
            self.push("(");
            for (position, narrowing) in group.iter().enumerate() {
                if position > 0 {
                    self.push(" AND ");
                }
                match narrowing {
                    Narrowing::Status { bucket, value, equal } => {
                        self.push(if *bucket { STATUS_BUCKET } else { "r.status" });
                        self.push(if *equal { "=" } else { "<>" }).text(value);
                    }
                    Narrowing::Column { column, values } => {
                        self.push("r.").push(column).push(" IN ").texts(values);
                    }
                    Narrowing::Attribute { key: attribute, test } => {
                        self.push("EXISTS (SELECT 1 FROM workflow_search_attributes a WHERE a.workflow_run_id=r.id AND ");
                        self.push(key).push("=").text(attribute);
                        match test {
                            AttributeTest::Present => {}
                            AttributeTest::Keyword(values) => {
                                self.push(" AND a.value_keyword IN ").texts(values);
                            }
                            AttributeTest::Int { operator: "IN", values } => {
                                self.push(" AND a.value_int IN ").ints(values);
                            }
                            AttributeTest::Int { operator, values } => {
                                let operator = if *operator == "!=" { "<>" } else { operator };
                                self.push(" AND a.value_int ").push(operator).int(values[0]);
                            }
                        }
                        self.push(")");
                    }
                }
            }
            self.push(")");
        }
        self.push(")")
    }
}

/// A run's status bucket, as `status_bucket` gives it.
const STATUS_BUCKET: &str = "CASE WHEN r.status IN ('pending','running','waiting') THEN 'running' WHEN r.status='completed' THEN 'completed' ELSE 'failed' END";

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
    /// Up to `limit` runs from the filter's offset, in the list's order.
    pub(super) async fn visibility_rows(&self, filter: &VisibilityFilter, limit: i64) -> Result<Vec<DB::Row>> {
        self.visibility_page(filter, limit, filter.offset).await
    }

    async fn visibility_page(&self, filter: &VisibilityFilter, limit: i64, offset: i64) -> Result<Vec<DB::Row>> {
        // PHP's index filters cancelled and terminated runs by their exact
        // status and the other values by status bucket.
        Ok(Self::query(DB::VISIBILITY_PAGE)
            .bind(self.namespace.as_ref())
            .bind(self.namespace.as_ref())
            .bind(filter.workflow_type.as_deref())
            .bind(filter.workflow_type.as_deref())
            .bind(filter.status.as_deref())
            .bind(filter.status.as_deref())
            .bind(filter.status.as_deref())
            .bind(filter.pattern.as_deref())
            .bind(filter.pattern.as_deref())
            .bind(filter.pattern.as_deref())
            .bind(limit)
            .bind(offset)
            .fetch_all(&self.pool)
            .await?)
    }

    /// One page of candidate runs for a structured query, in the list's
    /// order: the filter's runs that satisfy the query's narrowing. The
    /// statement is `VISIBILITY_PAGE` (or SQLite's form of it) with the
    /// narrowing added.
    async fn candidate_page(
        &self,
        filter: &VisibilityFilter,
        narrowing: Option<&[Vec<super::visibility_query::Narrowing>]>,
        limit: i64,
        offset: i64,
    ) -> Result<Vec<DB::Row>> {
        let sqlite = DB::NAME == "SQLite";
        let mut statement = Statement::new(DB::NAME == "MySQL");
        statement.push(if sqlite { "WITH runs AS (SELECT " } else { "SELECT " });
        statement.push("r.id,r.workflow_instance_id,r.workflow_type,r.queue,r.status,r.compatibility,r.started_at,r.closed_at,COALESCE(r.business_key,i.business_key) AS business_key");
        if sqlite {
            statement.push(",replace(replace(replace(COALESCE(s.sort_timestamp,r.started_at),'T',' '),'Z',''),'+00:00','') AS _dw_started_utc");
        }
        statement.push(" FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id LEFT JOIN workflow_run_summaries s ON s.id=r.id AND r.import_source='waterline_v1_projection' WHERE r.namespace=");
        statement.text(self.namespace.as_ref()).push(" AND i.namespace=").text(self.namespace.as_ref());
        if let Some(workflow_type) = filter.workflow_type.as_deref() {
            statement.push(" AND r.workflow_type=").text(workflow_type);
        }
        if let Some(status) = filter.status.as_deref() {
            statement.push(" AND (").push(STATUS_BUCKET).push("=").text(status).push(" OR r.status=").text(status).push(")");
        }
        if let Some(pattern) = filter.pattern.as_deref() {
            statement.push(" AND (r.workflow_instance_id LIKE ").text(pattern);
            statement.push(" OR COALESCE(r.business_key,i.business_key) LIKE ").text(pattern).push(")");
        }
        if let Some(groups) = narrowing {
            statement.narrowing(groups);
        }
        if sqlite {
            statement.push(") SELECT *,rtrim(rtrim(CASE WHEN length(_dw_started_utc)=19 THEN _dw_started_utc||'.0' ELSE _dw_started_utc END,'0'),'.') AS _dw_started_order FROM runs ORDER BY _dw_started_order DESC,id DESC");
        } else {
            statement.push(" ORDER BY COALESCE(s.sort_timestamp,r.started_at) DESC,r.id DESC");
        }
        statement.push(" LIMIT ").int(limit).push(" OFFSET ").int(offset);
        self.fetch_statement(statement).await
    }

    async fn fetch_statement(&self, statement: Statement) -> Result<Vec<DB::Row>> {
        let mut query = sqlx::query::<DB>(sqlx::AssertSqlSafe(statement.text));
        for bound in statement.binds {
            query = match bound {
                Bound::Text(text) => query.bind(text),
                Bound::Int(number) => query.bind(number),
            };
        }
        Ok(query.fetch_all(&self.pool).await?)
    }

    /// The queried attribute rows of the given runs, by run and key.
    async fn page_attributes(&self, keys: &[String], runs: &[String]) -> Result<BTreeMap<String, BTreeMap<String, Typed>>> {
        let mut attributes: BTreeMap<String, BTreeMap<String, Typed>> = BTreeMap::new();
        if keys.is_empty() || runs.is_empty() {
            return Ok(attributes);
        }
        // The columns `SEARCH_ATTRIBUTE_KEY_ROWS` reads, for these runs only.
        let columns = DB::SEARCH_ATTRIBUTE_KEY_ROWS.split(" FROM ").next().unwrap_or_default();
        let mysql = DB::NAME == "MySQL";
        let mut statement = Statement::new(mysql);
        statement.push(columns).push(" FROM workflow_search_attributes a WHERE ");
        statement.push(if mysql { "a.`key`" } else { "a.\"key\"" }).push(" IN ").texts(keys);
        statement.push(" AND a.workflow_run_id IN ").texts(runs);
        for row in self.fetch_statement(statement).await? {
            if let Some(value) = Self::typed_value(&row)? {
                attributes
                    .entry(DB::string(&row, "workflow_run_id")?)
                    .or_default()
                    .insert(DB::string(&row, "attribute_key")?, value);
            }
        }
        Ok(attributes)
    }

    /// Adds the MySQL collation key of each text not yet keyed. SQLite and
    /// PostgreSQL compare text exactly, as PHP's queries there do, and need
    /// no keys.
    async fn collation_keys(&self, texts: Vec<String>, keys: &mut HashMap<String, String>) -> Result<()> {
        if DB::NAME != "MySQL" {
            return Ok(());
        }
        let mut missing: Vec<String> = texts.into_iter().filter(|text| !keys.contains_key(text)).collect();
        missing.sort();
        missing.dedup();
        for chunk in missing.chunks(COLLATION_BATCH) {
            let mut query = Self::query(COLLATION_KEYS.as_str());
            for position in 0..COLLATION_BATCH {
                query = query.bind(chunk.get(position).map(String::as_str));
            }
            for row in query.fetch_all(&self.pool).await? {
                let position = DB::number(&row, "position")?;
                let text = usize::try_from(position).ok().and_then(|position| chunk.get(position.checked_sub(1)?));
                if let (Some(text), Some(key)) = (text, DB::optional_string(&row, "collation_key")?) {
                    keys.insert(text.clone(), key);
                }
            }
        }
        Ok(())
    }

    /// The runs a structured query selects, in list order, until one past
    /// the requested page, and how many candidate runs were read. The
    /// database applies the query's narrowing, so a selective query reads
    /// about as many runs as it selects; `matches` decides each one, with
    /// the attribute rows of that page's runs only.
    async fn query_matches(
        &self,
        filter: &VisibilityFilter,
        parsed: &super::visibility_query::Parsed,
    ) -> Result<(Vec<DB::Row>, i64)> {
        let definitions = Self::query("SELECT name,type FROM search_attribute_definitions WHERE namespace=$1")
            .bind(self.namespace.as_ref())
            .fetch_all(&self.pool)
            .await?
            .iter()
            .map(|row| Ok((DB::string(row, "name")?, DB::string(row, "type")?)))
            .collect::<Result<BTreeMap<_, _>>>()?;
        let compiled = super::visibility_query::compile(parsed, &definitions, &self.namespace)
            .map_err(query_failure)?;
        let narrowing = compiled.narrowing();
        let attribute_keys = compiled.keys();
        // Text compares under the backend's collation (`collation_keys`).
        let mut keys = HashMap::new();
        self.collation_keys(compiled.text_literals(), &mut keys).await?;
        let wanted = (filter.offset.saturating_add(filter.limit).saturating_add(1)) as usize;
        let none = BTreeMap::new();
        let mut matches = Vec::new();
        let mut scanned = 0;
        loop {
            let rows = self.candidate_page(filter, narrowing.as_deref(), QUERY_SCAN_PAGE, scanned).await?;
            let exhausted = (rows.len() as i64) < QUERY_SCAN_PAGE;
            scanned += rows.len() as i64;
            let mut page = Vec::with_capacity(rows.len());
            for row in rows {
                let status = DB::string(&row, "status")?;
                let run_id = DB::string(&row, "id")?;
                let fields = super::visibility_query::RunFields {
                    workflow_type: DB::string(&row, "workflow_type")?,
                    workflow_id: DB::string(&row, "workflow_instance_id")?,
                    run_id: run_id.clone(),
                    queue: DB::string(&row, "queue")?,
                    compatibility: DB::optional_string(&row, "compatibility")?,
                    started_at: DB::optional_instant(&row, "started_at")?,
                    closed_at: DB::optional_instant(&row, "closed_at")?,
                    status_bucket: status_bucket(&status)?.to_owned(),
                    status,
                };
                page.push((row, run_id, fields));
            }
            let runs: Vec<String> = page.iter().map(|(_, run_id, _)| run_id.clone()).collect();
            let attributes = self.page_attributes(&attribute_keys, &runs).await?;
            let mut texts: Vec<String> = page.iter().flat_map(|(_, _, fields)| compiled.run_texts(fields)).collect();
            for values in attributes.values() {
                for (attribute, value) in values {
                    if let (true, Typed::Keyword(text) | Typed::String(text)) = (compiled.compares_text(attribute), value) {
                        texts.push(text.clone());
                    }
                }
            }
            self.collation_keys(texts, &mut keys).await?;
            let key = |text: &str| keys.get(text).cloned().unwrap_or_else(|| text.to_owned());
            for (row, run_id, fields) in page {
                if compiled.matches(&fields, attributes.get(&run_id).unwrap_or(&none), &key) {
                    matches.push(row);
                    if matches.len() >= wanted {
                        return Ok((matches, scanned));
                    }
                }
            }
            if exhausted {
                return Ok((matches, scanned));
            }
        }
    }

    pub(super) async fn list_workflows(&self, filter: &VisibilityFilter) -> Result<Value> {
        let rows = match &filter.visibility {
            Some(parsed) => self
                .query_matches(filter, parsed)
                .await?
                .0
                .into_iter()
                .skip(filter.offset.max(0) as usize)
                .collect(),
            None => self.visibility_page(filter, filter.limit + 1, filter.offset).await?,
        };
        let more = rows.len() > filter.limit as usize;
        let mut workflows = Vec::new();
        let mut connection = self.pool.acquire().await?;
        for row in rows.iter().take(filter.limit as usize) {
            if DB::optional_string(row, "compatibility")?.is_some() {
                return Err(refuse(StatusCode::CONFLICT, "unqualified_build_visibility"));
            }
            let status = DB::string(row, "status")?;
            let bucket = status_bucket(&status)?;
            let run_id = DB::string(row, "id")?;
            // `getTypedSearchAttributes`; PHP renders an empty set as `[]`.
            let typed = Self::typed_rows(&mut connection, &run_id).await?;
            let search_attributes = if typed.is_empty() {
                json!([])
            } else {
                Value::Object(typed.into_iter().map(|(key, value)| (key, value.json())).collect())
            };
            workflows.push(json!({"workflow_id":DB::string(row,"workflow_instance_id")?, "run_id":run_id,
                "workflow_type":DB::string(row,"workflow_type")?, "business_key":DB::optional_string(row,"business_key")?,
                "status":status, "status_bucket":bucket, "task_queue":DB::optional_string(row,"queue")?,
                "is_terminal":bucket!="running", "compatibility":null, "compatibility_status":"compatible",
                "compatibility_supported_in_fleet":true, "compatibility_fleet_reason":null,
                "started_at":super::task_queues::json_time(DB::optional_instant(row,"started_at")?), "closed_at":super::task_queues::json_time(DB::optional_instant(row,"closed_at")?),
                "search_attributes":search_attributes}));
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
            let started = store.start(json!({"workflow_id":id,"workflow_type":"echo","task_queue":"test","input":input}), Default::default(), None, None).await.unwrap();
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

    #[tokio::test]
    async fn a_selective_query_reads_its_candidates_not_the_whole_namespace() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("visibility.sqlite");
        let store = Store::<sqlx::Sqlite>::new(super::super::sqlite::open(path.to_str().unwrap()).await.unwrap());
        for (name, kind) in [("CustomerId", "keyword"), ("Attempt", "int")] {
            Store::<sqlx::Sqlite>::query("INSERT INTO search_attribute_definitions(namespace,name,type,created_at,updated_at) VALUES ('default',$1,$2,'2026-11-01 00:00:00','2026-11-01 00:00:00')")
                .bind(name).bind(kind).execute(&store.pool).await.unwrap();
        }
        let input = json!({"codec":"avro", "blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
        // More runs than one scan page, three of them for one customer.
        let total = QUERY_SCAN_PAGE as usize + 150;
        for index in 0..total {
            let customer = if index % 200 == 7 { "acme" } else { "other" };
            store.start(json!({"workflow_id":format!("wf-{index:04}"),"workflow_type":"echo","task_queue":"test","input":input,
                "search_attributes":{"CustomerId":customer,"Attempt":index}}), Default::default(), None, None).await.unwrap();
        }
        let parse = |query: &str| super::super::visibility_query::parse(query).unwrap().unwrap();
        let filter = |query: &str| VisibilityQuery { query: Some(query.into()), ..Default::default() }.validate().unwrap();
        for (query, expected) in [
            ("CustomerId = \"acme\"", vec!["wf-0607", "wf-0407", "wf-0207", "wf-0007"]),
            ("CustomerId = \"acme\" AND Attempt > 300", vec!["wf-0607", "wf-0407"]),
            ("Attempt IN (5, 600)", vec!["wf-0600", "wf-0005"]),
        ] {
            let (rows, scanned) = store.query_matches(&filter(query), &parse(query)).await.unwrap();
            let ids: Vec<String> = rows.iter().map(|row| <sqlx::Sqlite as Backend>::string(row, "workflow_instance_id").unwrap()).collect();
            assert_eq!(ids, expected, "{query}");
            // The database leaves the other runs out: only candidates are read.
            assert_eq!(scanned as usize, expected.len(), "{query}");
        }
        // A query the database cannot narrow still answers every match.
        let query = "StartTime > \"2000-01-01\" AND CustomerId = \"acme\"";
        let (rows, scanned) = store.query_matches(&filter(query), &parse(query)).await.unwrap();
        assert_eq!(rows.len(), 4, "{query}");
        assert_eq!(scanned as usize, 4, "{query}: the customer narrows the group the time cannot");
        let query = "StartTime > \"2000-01-01\"";
        let page = store.list_workflows(&filter(query)).await.unwrap();
        assert_eq!(page["workflow_count"], 50);
        let (_, scanned) = store.query_matches(&filter(query), &parse(query)).await.unwrap();
        assert_eq!(scanned as usize, QUERY_SCAN_PAGE as usize, "{query}: one scan page holds the 51 wanted runs");
        store.close().await;
    }
}
