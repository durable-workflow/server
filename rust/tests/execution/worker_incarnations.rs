use super::*;

impl TestDatabase {
    async fn seed_expired_incarnations(&self, namespace: &str, first: usize, count: usize) {
        const INSERT: &str = "INSERT INTO dw_worker_registration_incarnations(token,namespace,worker_id,status,finished_at,created_at,updated_at) VALUES ($1,$2,'expired-worker','superseded','2000-01-01 00:00:00.000000','2000-01-01 00:00:00.000000','2000-01-01 00:00:00.000000')";
        const AGE: &str = "UPDATE dw_worker_registration_incarnations SET created_at='2000-01-01 00:00:00.000000',updated_at='2000-01-01 00:00:00.000000' WHERE namespace=$1 AND status='active'";
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                sqlx::query(AGE)
                    .bind(namespace)
                    .execute(&pool)
                    .await
                    .unwrap();
                for index in first..first + count {
                    sqlx::query(INSERT)
                        .bind(format!("{index:032x}"))
                        .bind(namespace)
                        .execute(&pool)
                        .await
                        .unwrap();
                }
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query(AGE)
                    .bind(namespace)
                    .execute(&pool)
                    .await
                    .unwrap();
                for index in first..first + count {
                    sqlx::query(INSERT)
                        .bind(format!("{index:032x}"))
                        .bind(namespace)
                        .execute(&pool)
                        .await
                        .unwrap();
                }
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query("UPDATE dw_worker_registration_incarnations SET created_at='2000-01-01 00:00:00.000000',updated_at='2000-01-01 00:00:00.000000' WHERE namespace=? AND status='active'").bind(namespace).execute(&pool).await.unwrap();
                for index in first..first + count {
                    sqlx::query("INSERT INTO dw_worker_registration_incarnations(token,namespace,worker_id,status,finished_at,created_at,updated_at) VALUES (?,?,'expired-worker','superseded','2000-01-01 00:00:00.000000','2000-01-01 00:00:00.000000','2000-01-01 00:00:00.000000')")
                        .bind(format!("{index:032x}")).bind(namespace).execute(&pool).await.unwrap();
                }
                pool.close().await;
            }
        }
    }
}

#[tokio::test]
async fn retirement_cleanup_is_bounded_and_preserves_recent_active_and_foreign_identities() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/namespaces",
            json!({"name":"alpha","retention_days":30})
        )
        .await
        .0,
        StatusCode::CREATED
    );
    registered(&app, "default").await;
    registered(&app, "alpha").await;
    let current = database.incarnations("default", "incarnation-worker").await;
    let foreign = database.incarnations("alpha", "incarnation-worker").await;
    database.seed_expired_incarnations("default", 1, 65).await;
    database.seed_expired_incarnations("alpha", 1000, 2).await;
    let expired_foreign = database.incarnations("alpha", "expired-worker").await;
    let mut registration = definition();
    registration["worker_id"] = json!("cleanup-worker");
    assert_eq!(
        scoped(
            &app,
            "default",
            "POST",
            "/api/worker/register",
            registration.clone()
        )
        .await
        .0,
        StatusCode::CREATED
    );
    assert_eq!(
        database
            .incarnations("default", "expired-worker")
            .await
            .len(),
        1,
        "one pass removes at most 64"
    );
    assert_eq!(
        database.incarnations("default", "incarnation-worker").await,
        current,
        "old active identity remains bound"
    );
    assert_eq!(
        database.incarnations("alpha", "incarnation-worker").await,
        foreign
    );
    assert_eq!(
        database.incarnations("alpha", "expired-worker").await,
        expired_foreign
    );
    assert_eq!(
        scoped(
            &app,
            "default",
            "POST",
            "/api/worker/register",
            registration
        )
        .await
        .0,
        StatusCode::CREATED
    );
    assert!(
        database
            .incarnations("default", "expired-worker")
            .await
            .is_empty()
    );
    let recent = database.incarnations("default", "cleanup-worker").await;
    assert_eq!(
        recent.len(),
        2,
        "recent superseded identity retains the full interval"
    );
    active(&recent);
    assert_eq!(
        database.incarnations("default", "incarnation-worker").await,
        current
    );
    assert_eq!(
        database.incarnations("alpha", "expired-worker").await,
        expired_foreign
    );
    runtime.close().await;
    drop(app);
    database.remove().await;
}

#[derive(Debug, PartialEq, Eq)]
struct Incarnation {
    token: String,
    status: String,
    finished: bool,
    recovered: Option<i64>,
}

fn incarnations<T>(rows: Vec<(String, String, Option<T>, Option<i64>)>) -> Vec<Incarnation> {
    rows.into_iter()
        .map(|(token, status, finished, recovered)| Incarnation {
            token,
            status,
            finished: finished.is_some(),
            recovered,
        })
        .collect()
}

impl TestDatabase {
    async fn incarnations(&self, namespace: &str, worker: &str) -> Vec<Incarnation> {
        const QUERY: &str = "SELECT token,status,finished_at,recovered_workflow_task_count FROM dw_worker_registration_incarnations WHERE namespace=$1 AND worker_id=$2 ORDER BY created_at,token";
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                let rows =
                    sqlx::query_as::<_, (String, String, Option<String>, Option<i64>)>(QUERY)
                        .bind(namespace)
                        .bind(worker)
                        .fetch_all(&pool)
                        .await
                        .unwrap();
                pool.close().await;
                incarnations(rows)
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let rows = sqlx::query_as::<
                    _,
                    (String, String, Option<chrono::NaiveDateTime>, Option<i64>),
                >(QUERY)
                .bind(namespace)
                .bind(worker)
                .fetch_all(&pool)
                .await
                .unwrap();
                pool.close().await;
                incarnations(rows)
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let rows = sqlx::query_as::<_, (String,String,Option<chrono::NaiveDateTime>,Option<i64>)>(
                    "SELECT token,status,finished_at,recovered_workflow_task_count FROM dw_worker_registration_incarnations WHERE namespace=? AND worker_id=? ORDER BY created_at,token")
                    .bind(namespace).bind(worker).fetch_all(&pool).await.unwrap();
                pool.close().await;
                incarnations(rows)
            }
        }
    }
}

async fn scoped(
    app: &Router,
    namespace: &str,
    method: &str,
    path: &str,
    body: Value,
) -> (StatusCode, Value) {
    let worker = path.starts_with("/api/worker/");
    let (status, _, body) = admission::admission_http(
        app,
        method,
        path,
        worker,
        Some("test-token"),
        Some(if worker { "1.20" } else { "2" }),
        Some(namespace),
        body,
    )
    .await;
    (status, body)
}

fn definition() -> Value {
    json!({"worker_id":"incarnation-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[]})
}

async fn registered(app: &Router, namespace: &str) {
    let result = scoped(app, namespace, "POST", "/api/worker/register", definition()).await;
    assert_eq!(result.0, StatusCode::CREATED, "{}", result.1);
    assert!(
        result.1.get("registration_token").is_none(),
        "identity is internal until fencing is qualified"
    );
    assert_ne!(
        result.1["server_capabilities"]["worker_deregistration_fencing"]["supported"],
        json!(true)
    );
}

fn active(rows: &[Incarnation]) -> &Incarnation {
    assert_eq!(rows.iter().filter(|row| row.status == "active").count(), 1);
    let row = rows.iter().find(|row| row.status == "active").unwrap();
    assert_eq!(row.token.len(), 32);
    assert!(
        row.token
            .bytes()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
    );
    assert!(!row.finished);
    assert_eq!(row.recovered, None);
    row
}

#[tokio::test]
async fn incarnation_rotation_survives_restart_and_preserves_other_namespace() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/namespaces",
            json!({"name":"alpha","retention_days":30})
        )
        .await
        .0,
        StatusCode::CREATED
    );
    registered(&app, "default").await;
    registered(&app, "alpha").await;
    let original = database.incarnations("default", "incarnation-worker").await;
    let foreign = database.incarnations("alpha", "incarnation-worker").await;
    let original_token = active(&original).token.clone();
    assert_ne!(original_token, active(&foreign).token);
    let heartbeat = scoped(
        &app,
        "default",
        "POST",
        "/api/worker/heartbeat",
        json!({"worker_id":"incarnation-worker"}),
    )
    .await;
    assert_eq!(heartbeat.0, StatusCode::OK);
    assert_eq!(
        database.incarnations("default", "incarnation-worker").await,
        original
    );
    let mut invalid = definition();
    invalid["supported_workflow_types"] = json!("invalid");
    assert_eq!(
        scoped(&app, "default", "POST", "/api/worker/register", invalid)
            .await
            .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    assert_eq!(
        database.incarnations("default", "incarnation-worker").await,
        original
    );
    registered(&app, "default").await;
    let rotated = database.incarnations("default", "incarnation-worker").await;
    assert_eq!(rotated.len(), 2);
    assert_ne!(active(&rotated).token, original_token);
    let retired = rotated
        .iter()
        .find(|row| row.token == original_token)
        .unwrap();
    assert_eq!(retired.status, "superseded");
    assert!(retired.finished);
    assert_eq!(
        retired.recovered, None,
        "legacy retirement is not a completed fenced receipt"
    );
    assert_eq!(
        database.incarnations("alpha", "incarnation-worker").await,
        foreign
    );
    runtime.close().await;
    drop(app);
    let restarted = database.open().await.unwrap();
    assert_eq!(
        database.incarnations("default", "incarnation-worker").await,
        rotated
    );
    let app = router(restarted.clone());
    assert_eq!(
        request(
            &app,
            "DELETE",
            "/api/worker/registrations/incarnation-worker",
            Value::Null
        )
        .await
        .0,
        StatusCode::OK
    );
    let removed = database.incarnations("default", "incarnation-worker").await;
    assert_eq!(removed.len(), 2);
    assert!(
        removed
            .iter()
            .all(|row| row.status == "superseded" && row.finished && row.recovered.is_none())
    );
    assert_eq!(
        database.incarnations("alpha", "incarnation-worker").await,
        foreign
    );
    assert_eq!(
        request(
            &app,
            "DELETE",
            "/api/worker/registrations/incarnation-worker",
            Value::Null
        )
        .await
        .0,
        StatusCode::NOT_FOUND
    );
    assert_eq!(
        database.incarnations("default", "incarnation-worker").await,
        removed
    );
    restarted.close().await;
    drop(app);
    database.remove().await;
}

#[tokio::test]
async fn independent_registration_pools_commit_one_current_incarnation() {
    let database = TestDatabase::new().await;
    let left = database.open().await.unwrap();
    let right = database.open().await.unwrap();
    let left_app = router(left.clone());
    let right_app = router(right.clone());
    let (first, second) = tokio::join!(
        scoped(
            &left_app,
            "default",
            "POST",
            "/api/worker/register",
            definition()
        ),
        scoped(
            &right_app,
            "default",
            "POST",
            "/api/worker/register",
            definition()
        )
    );
    assert_eq!(first.0, StatusCode::CREATED, "{}", first.1);
    assert_eq!(second.0, StatusCode::CREATED, "{}", second.1);
    let rows = database.incarnations("default", "incarnation-worker").await;
    assert_eq!(rows.len(), 2);
    active(&rows);
    assert_eq!(
        rows.iter()
            .filter(|row| row.status == "superseded" && row.finished)
            .count(),
        1
    );
    assert_ne!(rows[0].token, rows[1].token);
    left.close().await;
    right.close().await;
    drop((left_app, right_app));
    database.remove().await;
}
