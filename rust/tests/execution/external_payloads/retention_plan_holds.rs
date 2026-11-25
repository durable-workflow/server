//! Server #522: a retention plan counts the runs an online backup hold would
//! keep, from database reads alone. It fetches no payload object.
use super::retention::{closed_run, ns_request, object, registered, upload};
use super::*;
use crate::retention_plan::{closed_days_ago, fingerprint, plan_path};

const OWNER: &str = "7f1c3a1e-0000-4000-8000-0000000000c3";
const HOLD: &str = "/api/system/external-payload-backup-hold";

#[tokio::test]
async fn a_plan_counts_runs_an_online_backup_keeps_without_touching_storage() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    // Only the oldest run holds its object. The other eligible run shares an
    // object with a recent run the proposal keeps, so its prune deletes none.
    let sole = upload(&router, "default", "plan sole").await;
    let held = closed_run(&router, "default", "plan-backup-sole", &sole).await;
    let shared = upload(&router, "default", "plan shared").await;
    let sharing = closed_run(&router, "default", "plan-backup-shared", &shared).await;
    closed_run(&router, "default", "plan-backup-recent", &shared).await;
    closed_days_ago(&database, &held, 12).await;
    closed_days_ago(&database, &sharing, 11).await;
    let path = plan_path("default");
    let plan = || ns_request(&router, "POST", &path, "default", json!({"retention_days": 7}));

    let (status, body) = plan().await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["changes"]["newly_eligible"]["count"], 2, "{body}");
    assert_eq!([&body["hold_inspection"]["backup_hold_active"], &body["exclusions"]["backup_hold"]["count"]], [&json!(false), &json!(0)], "{body}");

    let (status, acquired) = ns_request(&router, "POST", &format!("{HOLD}/acquire"), "default", json!({"owner": OWNER, "ttl": 600})).await;
    assert_eq!(status, StatusCode::OK, "{acquired}");
    let before = fingerprint(&database).await;
    // With the storage root gone, any object read would fail; the plan reads none.
    let offline = temp.path().join("offline");
    std::fs::rename(&root, &offline).unwrap();
    let (status, body) = plan().await;
    std::fs::rename(&offline, &root).unwrap();
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["hold_inspection"]["backup_hold_active"], true, "{body}");
    let backup = &body["exclusions"]["backup_hold"];
    assert_eq!([&backup["count"], &backup["complete"]], [&json!(1), &json!(true)], "{backup}");
    assert_eq!(backup["examples"][0]["run_id"], held.as_str(), "{backup}");
    assert_eq!(body["exclusions"]["external_payload_cleanup_unavailable"]["count"], 0, "{body}");
    assert_eq!(fingerprint(&database).await, before);
    assert!(object(&root, &sole).exists());
    assert_eq!(registered(&database, &sole).await, 1);

    let (status, released) = ns_request(&router, "POST", &format!("{HOLD}/release"), "default", json!({"owner": OWNER})).await;
    assert_eq!(status, StatusCode::OK, "{released}");
    let (_, body) = plan().await;
    assert_eq!([&body["hold_inspection"]["backup_hold_active"], &body["exclusions"]["backup_hold"]["count"]], [&json!(false), &json!(0)], "{body}");
}
