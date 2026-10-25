use super::*;

// The published Server 2.11.1 API routes native does not serve yet. A port
// that serves a route removes it from this list in the same change, so the
// list in docs/rust-server-port.md cannot drift from the code.
const UNSERVED: &str = include_str!("../fixtures/unserved-php-routes.v2.11.1.txt");

/// Sends every method of every published PHP API route through the native
/// router, with each path parameter filled in, and lists those that answer
/// 501 rust_capability_not_implemented.
#[tokio::test]
async fn unserved_published_routes_match_the_recorded_list() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let table: Value = serde_json::from_str(include_str!("../../src/runtime/php-routes.v2.11.1.json")).unwrap();
    let mut unserved = Vec::new();
    let mut checked = 0;
    for route in table["routes"].as_array().unwrap() {
        let (methods, uri) = (route[0].as_str().unwrap(), route[1].as_str().unwrap());
        if !uri.starts_with("api/") {
            continue;
        }
        for method in methods.split('|').filter(|method| *method != "HEAD") {
            checked += 1;
            let path = format!("/{}", fill_parameters(uri));
            let worker = path.starts_with("/api/worker/");
            let body = if matches!(method, "GET" | "DELETE") { Body::empty() } else { Body::from("{}") };
            let request = Request::builder()
                .method(method)
                .uri(&path)
                .header("authorization", "Bearer test-token")
                .header("accept", "application/json")
                .header("content-type", "application/json")
                .header(
                    if worker { "x-durable-workflow-protocol-version" } else { "x-durable-workflow-control-plane-version" },
                    if worker { "1.20" } else { "2" },
                )
                .body(body)
                .unwrap();
            let response = app.clone().oneshot(request).await.unwrap();
            let status = response.status();
            let bytes = to_bytes(response.into_body(), 8 * 1024 * 1024).await.unwrap();
            if status == StatusCode::NOT_IMPLEMENTED
                && String::from_utf8_lossy(&bytes).contains("rust_capability_not_implemented")
            {
                unserved.push(format!("{method} /{uri}"));
            }
        }
    }
    assert!(checked > 170, "the published route table lists {checked} API route methods");
    let recorded: Vec<&str> = UNSERVED.lines().filter(|line| !line.is_empty() && !line.starts_with('#')).collect();
    let now_served: Vec<&&str> = recorded.iter().filter(|route| !unserved.iter().any(|found| found == **route)).collect();
    let now_unserved: Vec<&String> = unserved.iter().filter(|found| !recorded.contains(&found.as_str())).collect();
    assert!(
        now_served.is_empty() && now_unserved.is_empty(),
        "update rust/tests/fixtures/unserved-php-routes.v2.11.1.txt and the port log. Now served: {now_served:?}. Now unserved: {now_unserved:?}"
    );
    assert_eq!(unserved, recorded, "keep the recorded list in route-table order");
    runtime.close().await;
    database.remove().await;
}

/// Replaces each `{parameter}` in a route with a placeholder value.
fn fill_parameters(uri: &str) -> String {
    let mut path = String::new();
    let mut rest = uri;
    while let Some(open) = rest.find('{') {
        let close = open + rest[open..].find('}').expect("closed route parameter");
        path.push_str(&rest[..open]);
        path.push_str("probe-x");
        rest = &rest[close + 1..];
    }
    path.push_str(rest);
    path
}
