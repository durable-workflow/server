use super::*;
use super::retention::{closed_run, ns_request, object, registered, upload};

async fn fetch(router: &Router, namespace: &str, reference: &Value) -> (StatusCode, Value) {
    let (status, _, bytes) = request(router, "GET",
        &format!("/api/external-payloads/v1/{}", reference["reference_id"].as_str().unwrap()),
        "worker-token", namespace, Some(reference), Body::empty()).await;
    if status == StatusCode::OK {
        assert_eq!(digest(&bytes), reference["sha256"].as_str().unwrap());
        assert_eq!(bytes.len() as i64, reference["size_bytes"].as_i64().unwrap());
        (status, Value::Null)
    } else {
        (status, serde_json::from_slice(&bytes).unwrap())
    }
}

async fn prune(router: &Router, run: &str) -> Value {
    let (status, body) = ns_request(router, "POST", "/api/system/retention/pass", "default", json!({"run_ids":[run]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["results"][0]["outcome"], "pruned", "{body}");
    body["results"][0].clone()
}

async fn binding(database: &TestDatabase, reference: &Value) -> Value {
    let rows = text_rows(database,
        "SELECT id,storage_binding FROM runtime_external_payloads ORDER BY id",
        "SELECT id,storage_binding FROM runtime_external_payloads ORDER BY id",
        "SELECT id,storage_binding FROM runtime_external_payloads ORDER BY id").await;
    let stored = &rows.iter().find(|(id,_)| id==reference["reference_id"].as_str().unwrap()).unwrap().1;
    serde_json::from_str(stored).unwrap()
}

async fn replace_binding(database: &TestDatabase, reference: &Value, value: &Value) {
    let id = reference["reference_id"].as_str().unwrap();
    let encoded = (!value.is_null()).then(|| value.to_string());
    match database {
        TestDatabase::Sqlite(directory) => {
            let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", directory.path().join("runtime.sqlite").display())).await.unwrap();
            sqlx::query("UPDATE runtime_external_payloads SET storage_binding=$1 WHERE id=$2")
                .bind(encoded).bind(id).execute(&pool).await.unwrap();
            pool.close().await;
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
            sqlx::query("UPDATE runtime_external_payloads SET storage_binding=$1 WHERE id=$2")
                .bind(encoded).bind(id).execute(&pool).await.unwrap();
            pool.close().await;
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
            sqlx::query("UPDATE runtime_external_payloads SET storage_binding=? WHERE id=?")
                .bind(encoded).bind(id).execute(&pool).await.unwrap();
            pool.close().await;
        }
    }
}

#[tokio::test]
async fn legacy_rows_pin_before_a_policy_move_and_returning_to_the_old_root_keeps_generations() {
    let temporary = tempfile::tempdir().unwrap();
    let original = temporary.path().join("original");
    let replacement = temporary.path().join("replacement");
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &original).await;
    let first = upload(&app, "default", "legacy original bytes").await;
    let original_binding = binding(&database, &first).await;
    replace_binding(&database, &first, &Value::Null).await;
    assert_eq!(policy(&app, "default", &replacement, true).await, StatusCode::OK);
    assert_eq!(binding(&database, &first).await, original_binding);
    let second = upload(&app, "default", "replacement generation").await;
    let second_binding = binding(&database, &second).await;
    assert_eq!(policy(&app, "default", &original, true).await, StatusCode::OK);
    let third = upload(&app, "default", "new generation at original root").await;
    let third_binding = binding(&database, &third).await;
    assert_eq!(third_binding["root_uri"], original_binding["root_uri"]);
    assert!(third_binding["generation"].as_i64().unwrap() > second_binding["generation"].as_i64().unwrap());
    assert_eq!(binding(&database, &first).await, original_binding);
    assert_eq!(binding(&database, &second).await, second_binding);
    for reference in [&first, &second, &third] {
        assert_eq!(fetch(&app, "default", reference).await.0, StatusCode::OK);
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_missing_legacy_root_refuses_policy_replacement_without_recreating_or_rebinding_it() {
    let temporary = tempfile::tempdir().unwrap();
    let original = temporary.path().join("original");
    let offline = temporary.path().join("offline");
    let replacement = temporary.path().join("replacement");
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &original).await;
    let reference = upload(&app, "default", "legacy unavailable bytes").await;
    let original_binding = binding(&database, &reference).await;
    replace_binding(&database, &reference, &Value::Null).await;
    std::fs::rename(&original, &offline).unwrap();
    assert_eq!(policy(&app, "default", &replacement, true).await, StatusCode::CONFLICT);
    assert!(!original.exists());
    assert!(!replacement.exists());
    let (status, body) = ns_request(&app, "GET", "/api/namespaces/default", "default", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["external_payload_storage"]["config"]["uri"], format!("file://{}", original.display()));
    std::fs::rename(&offline, &original).unwrap();
    assert_eq!(policy(&app, "default", &replacement, true).await, StatusCode::OK);
    assert_eq!(binding(&database, &reference).await, original_binding);
    assert_eq!(fetch(&app, "default", &reference).await.0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn disabling_new_offloads_keeps_retained_reads_and_pruning_available() {
    let temporary = tempfile::tempdir().unwrap();
    let root = temporary.path().join("objects");
    let (database, runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let reference = upload(&router, "default", "retained after disabling").await;
    let run = closed_run(&router, "default", "disabled-retained", &reference).await;
    let (status, body) = ns_request(&router, "PUT", "/api/namespaces/default/external-storage", "default",
        json!({"driver":"local", "enabled":false})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["external_payload_storage"]["config"]["uri"], format!("file://{}", root.display()));
    assert_eq!(fetch(&router, "default", &reference).await.0, StatusCode::OK);
    let later = closed_run(&router, "default", "disabled-existing-input", &reference).await;
    let new_bytes = b"a new upload must remain disabled";
    let (status, _, _) = request(&router, "POST", "/api/external-payloads/v1", "worker-token", "default",
        Some(&metadata(new_bytes)), Body::from(new_bytes.as_slice())).await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE);
    assert_eq!(prune(&router, &run).await["external_payloads_deleted"], 0);
    assert_eq!(prune(&router, &later).await["external_payloads_deleted"], 1);
    assert!(!object(&root, &reference).exists());
    assert_eq!(registered(&database, &reference).await, 0);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn moving_the_write_policy_keeps_both_generations_bound_across_cold_read_and_mixed_prune() {
    let temporary = tempfile::tempdir().unwrap();
    let original = temporary.path().join("original");
    let replacement = temporary.path().join("replacement");
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &original).await;
    let first = upload(&app, "default", "original input").await;
    let (status, started) = ns_request(&app, "POST", "/api/workflows", "default", json!({"workflow_id":"mixed-policy",
        "workflow_type":"echo","task_queue":"mixed-policy","input":{"codec":"avro","external_payload":first}})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    assert_eq!(policy(&app, "default", &replacement, true).await, StatusCode::OK);
    assert_eq!(fetch(&app, "default", &first).await.0, StatusCode::OK);
    let original_binding = binding(&database, &first).await;
    let second = upload(&app, "default", "replacement result").await;
    assert!(object(&original, &first).exists());
    assert!(object(&replacement, &second).exists());
    assert!(!object(&replacement, &first).exists());
    assert!(!object(&original, &second).exists());
    let second_binding = binding(&database, &second).await;
    assert_eq!(binding(&database, &first).await, original_binding);
    assert_eq!(original_binding["namespace"], "default");
    assert_eq!(second_binding["namespace"], "default");
    assert!(second_binding["generation"].as_i64().unwrap()>original_binding["generation"].as_i64().unwrap());
    assert_ne!(second_binding["root_uri"], original_binding["root_uri"]);
    let (status, body) = json_request(&app, "POST", "/api/worker/register", "worker-token",
        json!({"worker_id":"policy-worker","task_queue":"mixed-policy","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, polled) = json_request(&app, "POST", "/api/worker/workflow-tasks/poll", "worker-token",
        json!({"worker_id":"policy-worker","task_queue":"mixed-policy","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task=&polled["task"];
    let (status, body) = json_request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete",task["task_id"].as_str().unwrap()),
        "worker-token",json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "commands":[{"type":"complete_workflow","result":{"codec":"avro","external_payload":second}}]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(policy(&app, "default", &replacement, false).await, StatusCode::OK);
    runtime.close().await;
    let cold = database.open().await.unwrap().with_role_tokens(Some("worker-token".into()),Some("operator-token".into()),Some("admin-token".into()));
    let cold_app=router(cold.clone());
    assert_eq!(fetch(&cold_app, "default", &first).await.0, StatusCode::OK);
    assert_eq!(fetch(&cold_app, "default", &second).await.0, StatusCode::OK);
    assert_eq!(prune(&cold_app,started["run_id"].as_str().unwrap()).await["external_payloads_deleted"],2);
    assert!(!object(&original,&first).exists());
    assert!(!object(&replacement,&second).exists());
    assert_eq!(registered(&database,&first).await,0);
    assert_eq!(registered(&database,&second).await,0);
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn mixed_generation_namespace_delete_keeps_the_other_namespace_owner_of_the_same_uri() {
    let temporary=tempfile::tempdir().unwrap();
    let original=temporary.path().join("original");
    let replacement=temporary.path().join("replacement");
    let (database,runtime,app)=setup(ExternalPayloadConfig::default(),&original).await;
    for name in ["mixed-delete","peer"] {
        assert_eq!(ns_request(&app,"POST","/api/namespaces","default",json!({"name":name})).await.0,StatusCode::CREATED);
        assert_eq!(policy(&app,name,&original,true).await,StatusCode::OK);
    }
    let first=upload(&app,"mixed-delete","shared original bytes").await;
    closed_run(&app,"mixed-delete","mixed-delete-a",&first).await;
    let peer=upload(&app,"peer","shared original bytes").await;
    assert_eq!(policy(&app,"mixed-delete",&replacement,true).await,StatusCode::OK);
    let second=upload(&app,"mixed-delete","replacement unclaimed bytes").await;
    assert_eq!(policy(&app,"mixed-delete",&replacement,false).await,StatusCode::OK);
    let (status,body)=ns_request(&app,"DELETE","/api/namespaces/mixed-delete","default",Value::Null).await;
    assert_eq!(status,StatusCode::OK,"{body}");
    assert!(object(&original,&peer).exists());
    assert!(!object(&replacement,&second).exists());
    assert_eq!(registered(&database,&first).await,0);
    assert_eq!(registered(&database,&second).await,0);
    assert_eq!(registered(&database,&peer).await,1);
    assert_eq!(fetch(&app,"peer",&peer).await.0,StatusCode::OK);
    assert_eq!(ns_request(&app,"DELETE","/api/namespaces/peer","default",Value::Null).await.0,StatusCode::OK);
    assert!(!object(&original,&peer).exists());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_binding_from_another_namespace_is_refused_even_for_the_same_physical_uri() {
    let temporary=tempfile::tempdir().unwrap();
    let root=temporary.path().join("shared");
    let (database,runtime,app)=setup(ExternalPayloadConfig::default(),&root).await;
    assert_eq!(ns_request(&app,"POST","/api/namespaces","default",json!({"name":"peer"})).await.0,StatusCode::CREATED);
    assert_eq!(policy(&app,"peer",&root,true).await,StatusCode::OK);
    let mine=upload(&app,"default","same physical bytes").await;
    let theirs=upload(&app,"peer","same physical bytes").await;
    assert_eq!(fetch(&app,"peer",&mine).await.0,StatusCode::NOT_FOUND);
    assert_eq!(fetch(&app,"default",&theirs).await.0,StatusCode::NOT_FOUND);
    let mine_binding=binding(&database,&mine).await;
    let foreign_binding=binding(&database,&theirs).await;
    replace_binding(&database,&mine,&foreign_binding).await;
    let (status,body)=fetch(&app,"default",&mine).await;
    assert_eq!(status,StatusCode::CONFLICT,"{body}");
    assert_eq!(body["reason"],"external_payload_storage_binding_unavailable");
    assert_eq!(body["retryable"],false);
    assert!(!body.to_string().contains(root.to_str().unwrap()));
    assert_eq!(fetch(&app,"peer",&theirs).await.0,StatusCode::OK);
    replace_binding(&database,&mine,&mine_binding).await;
    assert_eq!(fetch(&app,"default",&mine).await.0,StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}
