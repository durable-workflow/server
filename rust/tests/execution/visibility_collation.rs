use super::*;

// Published Server 2.11.7 compares visibility query text in the database:
// MySQL by the column collation, utf8mb4_unicode_ci (case- and
// accent-insensitive), and SQLite and PostgreSQL exactly. The
// visibility-collation fixture records the same comparisons on PHP.

fn encoded(query: &str) -> String {
    query.bytes().map(|byte| if byte.is_ascii_alphanumeric() { (byte as char).to_string() } else { format!("%{byte:02X}") }).collect()
}

async fn matched(app: &Router, query: &str) -> Vec<String> {
    let (status, listed) = request(app, "GET", &format!("/api/workflows?page_size=50&query={}", encoded(query)), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{query}: {listed}");
    let mut ids: Vec<String> = listed["workflows"].as_array().unwrap().iter()
        .map(|row| row["workflow_id"].as_str().unwrap().to_owned()).collect();
    ids.sort();
    ids
}

#[tokio::test]
async fn query_text_compares_as_the_backend_s_php_collation() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (name, kind) in [("Region", "keyword"), ("Note", "text")] {
        let (status, body) = request(&app, "POST", "/api/search-attributes", json!({"name": name, "type": kind})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
    for (id, workflow_type, region, note) in [("vc-a", "Alpha", "eu", "Hello"), ("vc-b", "alpha", "EU", "hello"),
        ("vc-c", "ALPHA", "éu", "héllo"), ("vc-d", "Beta", "us", "world")] {
        let (status, body) = request(&app, "POST", "/api/workflows", json!({"workflow_id": id, "workflow_type": workflow_type,
            "task_queue": "test", "input": envelope(Payload::Array(vec![])),
            "search_attributes": {"Region": region, "Note": note}})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
    let collated = std::env::var_os("DW_EXECUTION_MYSQL_URL").is_some();
    let cases: [(&str, &[&str], &[&str]); 7] = [
        ("Region = \"eu\"", &["vc-a"], &["vc-a", "vc-b", "vc-c"]),
        ("Region != \"eu\"", &["vc-b", "vc-c", "vc-d"], &["vc-d"]),
        ("Region IN (\"EU\")", &["vc-b"], &["vc-a", "vc-b", "vc-c"]),
        ("Note = \"HELLO\"", &[], &["vc-a", "vc-b", "vc-c"]),
        ("WorkflowType = \"alpha\"", &["vc-b"], &["vc-a", "vc-b", "vc-c"]),
        ("WorkflowType != \"Alpha\"", &["vc-b", "vc-c", "vc-d"], &["vc-d"]),
        ("WorkflowId = \"VC-A\"", &[], &["vc-a"]),
    ];
    for (query, exact, mysql) in cases {
        let expected = if collated { mysql } else { exact };
        assert_eq!(matched(&app, query).await, expected, "{query}");
    }
    runtime.close().await;
    database.remove().await;
}
