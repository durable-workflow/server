use super::*;

// Caller-indexed Nexus operation reads list the namespace's service calls of
// one workflow, or one run, newest first in PHP's row shape. Native writes no
// service calls yet, so the rows are seeded; the empty case is the shared
// `nexus-operations` fixture's.
#[tokio::test]
async fn nexus_operation_reads_list_seeded_calls_newest_first() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    namespaces::create(&app, "callers").await;
    // Test-owned literals only; no request input reaches this SQL.
    for (id, namespace, run, accepted) in [
        ("call-old", "default", "run-a", "2026-10-25 06:00:00"),
        ("call-new", "default", "run-b", "2026-10-25 06:00:05"),
        ("call-other", "callers", "run-a", "2026-10-25 06:00:09"),
    ] {
        database.execute(sqlx::AssertSqlSafe(format!(
            "INSERT INTO workflow_service_calls(id,endpoint_name,service_name,operation_name,caller_namespace,caller_workflow_instance_id,caller_workflow_run_id,status,operation_mode,outcome_metadata,metadata,accepted_at,created_at,updated_at) \
             VALUES ('{id}','billing','invoices','charge','{namespace}','caller-wf','{run}','completed','sync','{{}}','{{\"service_call_attempts\":[{{\"attempt\":1}},\"skip\"]}}','{accepted}','{accepted}','{accepted}')"
        ))).await;
    }
    let (status, all) = request(&app, "GET", "/api/workflows/caller-wf/nexus-operations", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{all}");
    assert_eq!((all["caller_namespace"].clone(), all["count"].clone(), all["limit"].clone()), (json!("default"), json!(2), json!(200)), "{all}");
    let ids: Vec<_> = all["nexus_operations"].as_array().unwrap().iter().map(|call| call["service_call_id"].clone()).collect();
    assert_eq!(ids, [json!("call-new"), json!("call-old")], "newest first, this namespace only");
    let newest = &all["nexus_operations"][0];
    assert_eq!(newest["outcome_metadata"], json!([]), "an empty map renders as PHP's []");
    assert_eq!((newest["retry_attempt_count"].clone(), newest["service_call_attempts"].clone()), (json!(1), json!([{"attempt":1}])));
    assert_eq!(newest["accepted_at"], json!("2026-10-25T06:00:05+00:00"));
    let (status, limited) = request(&app, "GET", "/api/workflows/caller-wf/nexus-operations?limit=1", Value::Null).await;
    assert_eq!((status, limited["count"].clone(), limited["limit"].clone()), (StatusCode::OK, json!(1), json!(1)), "{limited}");
    let (status, run) = request(&app, "GET", "/api/workflows/caller-wf/runs/run-a/nexus-operations", Value::Null).await;
    assert_eq!((status, run["workflow_run_id"].clone(), run["count"].clone()), (StatusCode::OK, json!("run-a"), json!(1)), "{run}");
    assert_eq!(run["nexus_operations"][0]["service_call_id"], "call-old");
    let (status, other) = namespaces::scoped(&app, "callers", "GET", "/api/workflows/caller-wf/nexus-operations", Value::Null).await;
    assert_eq!((status, other["count"].clone()), (StatusCode::OK, json!(1)), "{other}");
    assert_eq!(other["nexus_operations"][0]["service_call_id"], "call-other");
}
