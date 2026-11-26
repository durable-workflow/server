//! Server #522: `POST /api/namespaces/{namespace}/retention-plan` previews a
//! proposed retention policy against the current one, bound to the current
//! policy's revision, and writes nothing. `.../retention-plan/apply` applies
//! the planned policy only while the plan still holds.
use super::*;

pub(super) const SCHEMA: &str = "durable-workflow.v2.retention-plan.v1";
const APPLY_SCHEMA: &str = "durable-workflow.v2.retention-apply.v1";

pub(super) fn plan_path(namespace: &str) -> String {
    format!("/api/namespaces/{namespace}/retention-plan")
}

fn apply_path(namespace: &str) -> String {
    format!("/api/namespaces/{namespace}/retention-plan/apply")
}

/// The apply body for a plan of `proposal`, with its two revisions.
fn apply_body(plan: &Value, proposal: Value, confirm: Option<bool>) -> Value {
    let mut body = proposal;
    body["policy_revision"] = plan["policy_revision"].clone();
    body["plan_revision"] = plan["plan_revision"].clone();
    if let Some(confirm) = confirm {
        body["confirm_retroactive_pruning"] = json!(confirm);
    }
    body
}

/// The fingerprint without the namespace policy rows: what an apply must
/// never move.
async fn runs_fingerprint(database: &TestDatabase) -> (Vec<(String, i64)>, Vec<(String, String)>) {
    let (counts, rows) = fingerprint(database).await;
    (counts, rows.into_iter().filter(|(key, _)| key != "default").collect())
}

async fn completed_run(app: &Router, workflow: &str) -> String {
    let started = start(app, workflow).await;
    let task = poll(app, "worker", "workflow").await;
    let complete = json!({"type":"complete_workflow","result":envelope(Payload::Long(1))});
    assert_eq!(finish_task(app, &task, json!([complete])).await.0, StatusCode::OK);
    started["run_id"].as_str().unwrap().to_owned()
}

/// Moves the run's close `days` days into the past.
pub(super) async fn closed_days_ago(database: &TestDatabase, run: &str, days: i64) {
    let at = (chrono::Utc::now() - chrono::Duration::days(days)).format("%Y-%m-%d %H:%M:%S%.6f");
    for table in ["workflow_runs", "workflow_run_summaries"] {
        database.execute(sqlx::AssertSqlSafe(format!("UPDATE {table} SET closed_at='{at}' WHERE id='{run}'"))).await;
    }
}

fn run_ids(entry: &Value) -> Vec<String> {
    entry["examples"].as_array().unwrap().iter().map(|example| example["run_id"].as_str().unwrap().to_owned()).collect()
}

fn exact(entry: &Value, count: i64) {
    assert_eq!([&entry["count"], &entry["lower_bound"], &entry["complete"]], [&json!(count), &json!(count), &json!(true)], "{entry}");
}

/// Every table's row count, then the namespaces' policies and every run's
/// lifecycle columns, `updated_at` included: what a write would move.
pub(super) async fn fingerprint(database: &TestDatabase) -> (Vec<(String, i64)>, Vec<(String, String)>) {
    let mut rows = text_rows(database,
        "SELECT name, retention_mode||'|'||COALESCE(CAST(retention_days AS TEXT),'')||'|'||COALESCE(CAST(updated_at AS TEXT),'') FROM workflow_namespaces ORDER BY name",
        "SELECT name::text, retention_mode||'|'||COALESCE(retention_days::text,'')||'|'||COALESCE(updated_at::text,'') FROM workflow_namespaces ORDER BY name",
        "SELECT CAST(name AS CHAR), CONCAT(retention_mode,'|',COALESCE(CAST(retention_days AS CHAR),''),'|',COALESCE(CAST(updated_at AS CHAR),'')) FROM workflow_namespaces ORDER BY name").await;
    rows.extend(text_rows(database,
        "SELECT id, status||'|'||COALESCE(CAST(closed_at AS TEXT),'')||'|'||COALESCE(CAST(archived_at AS TEXT),'')||'|'||COALESCE(CAST(updated_at AS TEXT),'') FROM workflow_run_summaries ORDER BY id",
        "SELECT id::text, status||'|'||COALESCE(closed_at::text,'')||'|'||COALESCE(archived_at::text,'')||'|'||COALESCE(updated_at::text,'') FROM workflow_run_summaries ORDER BY id",
        "SELECT CAST(id AS CHAR), CONCAT(status,'|',COALESCE(CAST(closed_at AS CHAR),''),'|',COALESCE(CAST(archived_at AS CHAR),''),'|',COALESCE(CAST(updated_at AS CHAR),'')) FROM workflow_run_summaries ORDER BY id").await);
    rows.extend(text_rows(database,
        "SELECT id, status||'|'||COALESCE(CAST(closed_at AS TEXT),'')||'|'||COALESCE(CAST(details_pruned_at AS TEXT),'')||'|'||COALESCE(CAST(updated_at AS TEXT),'') FROM workflow_runs ORDER BY id",
        "SELECT id::text, status||'|'||COALESCE(closed_at::text,'')||'|'||COALESCE(details_pruned_at::text,'')||'|'||COALESCE(updated_at::text,'') FROM workflow_runs ORDER BY id",
        "SELECT CAST(id AS CHAR), CONCAT(status,'|',COALESCE(CAST(closed_at AS CHAR),''),'|',COALESCE(CAST(details_pruned_at AS CHAR),''),'|',COALESCE(CAST(updated_at AS CHAR),'')) FROM workflow_runs ORDER BY id").await);
    (table_counts(database).await, rows)
}

// Shortening, lengthening and `forever` against a 30-day policy, with an
// archived run and an open run excluded and counted.
#[tokio::test]
async fn a_plan_compares_the_proposed_policy_with_the_current_one() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let old = completed_run(&app, "plan-old").await;
    let recent = completed_run(&app, "plan-recent").await;
    let fresh = completed_run(&app, "plan-fresh").await;
    let archived = completed_run(&app, "plan-archived").await;
    let (status, body) = request(&app, "POST", "/api/workflows/plan-archived/archive", json!({"reason":"keep"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    start(&app, "plan-open").await;
    for (run, days) in [(&old, 40), (&recent, 10), (&fresh, 3), (&archived, 10)] {
        closed_days_ago(&database, run, days).await;
    }
    let before = fingerprint(&database).await;

    // Shortening 30 days to 7: the 10-day-old run becomes eligible.
    let (status, plan) = request(&app, "POST", &plan_path("default"), json!({"retention_days": 7})).await;
    assert_eq!(status, StatusCode::OK, "{plan}");
    assert_eq!([&plan["schema"], &plan["namespace"], &plan["read_only"], &plan["complete"]],
        [&json!(SCHEMA), &json!("default"), &json!(true), &json!(true)], "{plan}");
    assert_eq!(plan["incomplete"], json!([]));
    assert!(plan["scope"].as_str().unwrap().contains("not an immutable deletion set"), "{plan}");
    assert_eq!([&plan["current_policy"]["retention_mode"], &plan["current_policy"]["retention_days"]], [&json!("bounded"), &json!(30)]);
    assert_eq!([&plan["proposed_policy"]["retention_mode"], &plan["proposed_policy"]["retention_days"]], [&json!("bounded"), &json!(7)]);
    assert!(plan["proposed_policy"]["cutoff"].as_str().unwrap() > plan["current_policy"]["cutoff"].as_str().unwrap(), "{plan}");
    let revision = plan["policy_revision"].as_str().unwrap().to_owned();
    assert!(revision.starts_with("sha256:") && revision.len() == 71, "{revision}");
    let changes = &plan["changes"];
    exact(&changes["newly_eligible"], 1);
    assert_eq!(run_ids(&changes["newly_eligible"]), [recent.clone()]);
    let example = &changes["newly_eligible"]["examples"][0];
    assert_eq!([&example["workflow_id"], &example["workflow_type"], &example["status"]], [&json!("plan-recent"), &json!("echo"), &json!("completed")], "{example}");
    exact(&changes["no_longer_eligible"], 0);
    exact(&changes["unchanged_eligible"], 1);
    assert_eq!(run_ids(&changes["unchanged_eligible"]), [old.clone()]);
    exact(&changes["unchanged_retained"], 1);
    assert_eq!(run_ids(&changes["unchanged_retained"]), [fresh.clone()]);
    let exclusions = &plan["exclusions"];
    exact(&exclusions["archived"], 1);
    exact(&exclusions["forever"], 0);
    exact(&exclusions["not_terminal"], 1);
    for hold in ["detached_activity_hold", "external_payload_cleanup_unavailable", "backup_hold"] {
        exact(&exclusions[hold], 0);
    }
    assert_eq!([&plan["hold_inspection"]["inspected"], &plan["hold_inspection"]["complete"], &plan["hold_inspection"]["backup_hold_active"]],
        [&json!(2), &json!(true), &json!(false)], "{plan}");

    // Lengthening to 60 days: the 40-day-old run is kept again.
    let (status, plan) = request(&app, "POST", &plan_path("default"), json!({"retention_days": 60})).await;
    assert_eq!(status, StatusCode::OK, "{plan}");
    assert_eq!(plan["policy_revision"], revision.as_str(), "the same current policy keeps its revision");
    let changes = &plan["changes"];
    exact(&changes["newly_eligible"], 0);
    exact(&changes["no_longer_eligible"], 1);
    assert_eq!(run_ids(&changes["no_longer_eligible"]), [old.clone()]);
    exact(&changes["unchanged_eligible"], 0);
    exact(&changes["unchanged_retained"], 2);
    assert_eq!(run_ids(&changes["unchanged_retained"]), [recent.clone(), fresh.clone()]);
    exact(&plan["exclusions"]["archived"], 0);

    // `forever` keeps every closed run, archived or not, whatever its age.
    let (status, plan) = request(&app, "POST", &plan_path("default"), json!({"retention_mode": "forever"})).await;
    assert_eq!(status, StatusCode::OK, "{plan}");
    assert_eq!(plan["proposed_policy"], json!({"retention_mode":"forever","retention_days":null,"cutoff":null}));
    exact(&plan["changes"]["no_longer_eligible"], 1);
    exact(&plan["changes"]["unchanged_retained"], 2);
    exact(&plan["exclusions"]["forever"], 3);
    exact(&plan["exclusions"]["archived"], 1);
    assert_eq!(plan["hold_inspection"]["inspected"], 0);

    // No fields propose the current policy: nothing changes.
    let (status, plan) = request(&app, "POST", &plan_path("DEFAULT"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{plan}");
    assert_eq!(plan["proposed_policy"], plan["current_policy"].as_object().map(|policy| {
        let mut policy = policy.clone();
        policy.remove("updated_at");
        Value::Object(policy)
    }).unwrap());
    exact(&plan["changes"]["newly_eligible"], 0);
    exact(&plan["changes"]["no_longer_eligible"], 0);

    // The namespace update's own rules refuse an invalid proposal.
    for invalid in [json!({"retention_mode":"forever","retention_days":5}), json!({"retention_days":0}),
        json!({"retention_days":366}), json!({"retention_days":null}), json!({"retention_mode":"never"})] {
        let (status, body) = request(&app, "POST", &plan_path("default"), invalid.clone()).await;
        assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("invalid_namespace_definition")), "{invalid}: {body}");
    }
    let (status, body) = request(&app, "POST", &plan_path("default"), json!({"scan_limit":0,"hold_scan_limit":"x"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"]["scan_limit"], json!(["The scan limit field must be at least 1."]));
    assert_eq!(body["errors"]["hold_scan_limit"], json!(["The hold scan limit field must be an integer."]));
    let (status, body) = request(&app, "POST", &plan_path("missing"), json!({"retention_days": 7})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("namespace_not_found")), "{body}");

    // None of it wrote anything.
    assert_eq!(fingerprint(&database).await, before);

    // Changing the policy changes the revision the plan is bound to.
    let (status, updated) = request(&app, "PUT", "/api/namespaces/default", json!({"retention_days": 14})).await;
    assert_eq!(status, StatusCode::OK, "{updated}");
    let (_, plan) = request(&app, "POST", &plan_path("default"), json!({"retention_days": 7})).await;
    assert_eq!(plan["current_policy"]["retention_days"], 14, "{plan}");
    assert_ne!(plan["policy_revision"], revision.as_str());
    runtime.close().await;
    database.remove().await;
}

// Holds are inspected run by run up to their own bound, and every count that
// reaches its bound is reported as incomplete, never as exact.
#[tokio::test]
async fn a_plan_counts_holds_and_flags_an_incomplete_scan_without_writing() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let detached = completed_run(&app, "plan-detached").await;
    let unsettled = completed_run(&app, "plan-unsettled").await;
    let plain = completed_run(&app, "plan-plain").await;
    for (run, days) in [(&detached, 12), (&unsettled, 11), (&plain, 10)] {
        closed_days_ago(&database, run, days).await;
    }
    // An activity scheduled under the abandon policy with no terminal event,
    // and a reference in PHP's stored format, which native cannot settle.
    database.execute(sqlx::AssertSqlSafe(format!(
        "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload) VALUES ('01JPLANDETACHED00000000000','{detached}',1000,'ActivityScheduled','{{\"sequence\":1000,\"activity_execution_id\":\"plan-activity\",\"activity\":{{\"id\":\"plan-activity\",\"cancellation_policy\":\"abandon\"}}}}')"))).await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_runs SET output='dw-external-payload:v1:e30=' WHERE id='{unsettled}'"))).await;
    let before = fingerprint(&database).await;

    let (status, plan) = request(&app, "POST", &plan_path("default"), json!({"retention_days": 7})).await;
    assert_eq!(status, StatusCode::OK, "{plan}");
    assert_eq!(plan["complete"], true, "{plan}");
    exact(&plan["changes"]["newly_eligible"], 3);
    assert_eq!(run_ids(&plan["changes"]["newly_eligible"]), [detached.clone(), unsettled.clone(), plain.clone()]);
    let exclusions = &plan["exclusions"];
    exact(&exclusions["detached_activity_hold"], 1);
    assert_eq!(run_ids(&exclusions["detached_activity_hold"]), [detached.clone()]);
    exact(&exclusions["external_payload_cleanup_unavailable"], 1);
    assert_eq!(run_ids(&exclusions["external_payload_cleanup_unavailable"]), [unsettled.clone()]);
    exact(&exclusions["backup_hold"], 0);
    assert_eq!(plan["hold_inspection"]["inspected"], 3);

    // With bounds of one, each count holds only what was seen.
    let (status, plan) = request(&app, "POST", &plan_path("default"),
        json!({"retention_days": 7, "scan_limit": 1, "hold_scan_limit": 1})).await;
    assert_eq!(status, StatusCode::OK, "{plan}");
    assert_eq!([&plan["complete"], &plan["scan_limit"], &plan["hold_scan_limit"]], [&json!(false), &json!(1), &json!(1)], "{plan}");
    let newly = &plan["changes"]["newly_eligible"];
    assert_eq!([&newly["count"], &newly["lower_bound"], &newly["complete"]], [&Value::Null, &json!(1), &json!(false)], "{newly}");
    // Examples keep their own fixed cap.
    assert_eq!(run_ids(newly).len(), 3);
    let held = &plan["exclusions"]["detached_activity_hold"];
    assert_eq!([&held["count"], &held["lower_bound"], &held["complete"]], [&Value::Null, &json!(1), &json!(false)], "{held}");
    let unseen = &plan["exclusions"]["external_payload_cleanup_unavailable"];
    assert_eq!([&unseen["count"], &unseen["lower_bound"], &unseen["complete"]], [&Value::Null, &json!(0), &json!(false)], "{unseen}");
    assert_eq!([&plan["hold_inspection"]["inspected"], &plan["hold_inspection"]["complete"]], [&json!(1), &json!(false)]);
    let incomplete = plan["incomplete"].as_array().unwrap();
    assert!(incomplete.contains(&json!({"field":"changes.newly_eligible","reason":"scan_limit_reached"})), "{plan}");
    assert!(incomplete.contains(&json!({"field":"exclusions.detached_activity_hold","reason":"hold_scan_limit_reached"})), "{plan}");
    assert!(!incomplete.iter().any(|entry| entry["field"] == "changes.unchanged_retained"), "{plan}");

    // The plan read the runs and wrote nothing: no policy, no marks, no prune.
    assert_eq!(fingerprint(&database).await, before);
    runtime.close().await;
    database.remove().await;
}

// The apply refuses an unconfirmed retroactive policy, a proposal other than
// the planned one and a plan made stale by any namespace change, and applies
// the planned policy without touching a run.
#[tokio::test]
async fn a_guarded_apply_applies_only_the_planned_policy() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let old = completed_run(&app, "apply-old").await;
    let recent = completed_run(&app, "apply-recent").await;
    let archived = completed_run(&app, "apply-archived").await;
    let (status, body) = request(&app, "POST", "/api/workflows/apply-archived/archive", json!({"reason":"keep"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    for (run, days) in [(&old, 40), (&recent, 10), (&archived, 10)] {
        closed_days_ago(&database, run, days).await;
    }
    let untouched = runs_fingerprint(&database).await;
    let before = fingerprint(&database).await;

    // Shortening 30 days to 7 is retroactive: the plan says so, and the
    // apply needs the confirmation.
    let shorten = json!({"retention_days": 7});
    let (status, plan) = request(&app, "POST", &plan_path("default"), shorten.clone()).await;
    assert_eq!(status, StatusCode::OK, "{plan}");
    assert_eq!(plan["retroactive"], true, "{plan}");
    let plan_revision = plan["plan_revision"].as_str().unwrap().to_owned();
    assert!(plan_revision.starts_with("sha256:") && plan_revision.len() == 71 && plan_revision != plan["policy_revision"], "{plan}");
    for confirm in [None, Some(false)] {
        let (status, body) = request(&app, "POST", &apply_path("default"), apply_body(&plan, shorten.clone(), confirm)).await;
        assert_eq!((status, body["reason"].clone(), body["retroactive"].clone()),
            (StatusCode::UNPROCESSABLE_ENTITY, json!("retention_pruning_confirmation_required"), json!(true)), "{body}");
    }
    // A proposal other than the planned one, under the same revisions.
    let (status, body) = request(&app, "POST", &apply_path("default"), apply_body(&plan, json!({"retention_days": 8}), Some(true))).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("retention_plan_revision_mismatch")), "{body}");
    let (status, body) = request(&app, "POST", &apply_path("default"), apply_body(&plan, json!({"retention_mode": "forever"}), Some(true))).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("retention_plan_revision_mismatch")), "{body}");
    // An unknown revision is stale, and the refusal names the current one.
    let mut stale = apply_body(&plan, shorten.clone(), Some(true));
    stale["policy_revision"] = json!("sha256:0");
    let (status, body) = request(&app, "POST", &apply_path("default"), stale).await;
    assert_eq!((status, body["reason"].clone(), body["policy_revision"].clone()),
        (StatusCode::CONFLICT, json!("retention_policy_revision_stale"), plan["policy_revision"].clone()), "{body}");
    // Both revisions are required; the proposal follows the update's rules.
    let (status, body) = request(&app, "POST", &apply_path("default"), shorten.clone()).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"]["policy_revision"], json!(["The policy revision field is required."]));
    assert_eq!(body["errors"]["plan_revision"], json!(["The plan revision field is required."]));
    let (status, body) = request(&app, "POST", &apply_path("default"), apply_body(&plan, json!({"retention_days": 0}), Some(true))).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("invalid_namespace_definition")), "{body}");
    let (status, body) = request(&app, "POST", &apply_path("missing"), apply_body(&plan, shorten.clone(), Some(true))).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("namespace_not_found")), "{body}");
    // Every refusal left everything as it was.
    assert_eq!(fingerprint(&database).await, before);

    // A concurrent policy change through the unchanged namespace update,
    // which still activates at once, makes the plan stale.
    let (status, updated) = request(&app, "PUT", "/api/namespaces/default", json!({"retention_days": 14})).await;
    assert_eq!((status, updated["retention_days"].clone()), (StatusCode::OK, json!(14)), "{updated}");
    let (status, body) = request(&app, "POST", &apply_path("default"), apply_body(&plan, shorten.clone(), Some(true))).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("retention_policy_revision_stale")), "{body}");
    assert_ne!(body["policy_revision"], plan["policy_revision"], "{body}");

    // A fresh plan against 14 days applies with the confirmation.
    let (_, plan) = request(&app, "POST", &plan_path("DEFAULT"), shorten.clone()).await;
    let (status, applied) = request(&app, "POST", &apply_path("DEFAULT"), apply_body(&plan, shorten.clone(), Some(true))).await;
    assert_eq!(status, StatusCode::OK, "{applied}");
    assert_eq!([&applied["schema"], &applied["namespace"], &applied["retroactive"], &applied["confirmed_retroactive_pruning"]],
        [&json!(APPLY_SCHEMA), &json!("default"), &json!(true), &json!(true)], "{applied}");
    assert_eq!(applied["previous_policy_revision"], plan["policy_revision"]);
    assert_eq!([&applied["previous_policy"]["retention_days"], &applied["policy"]["retention_days"]], [&json!(14), &json!(7)], "{applied}");
    assert_eq!(applied["policy"]["updated_at"], applied["applied_at"]);
    assert!(applied["scope"].as_str().unwrap().contains("not the set that will be deleted"), "{applied}");
    let (_, described) = request(&app, "GET", "/api/namespaces/default", Value::Null).await;
    assert_eq!([&described["retention_mode"], &described["retention_days"]], [&json!("bounded"), &json!(7)], "{described}");
    // The new revision is the one a new plan is bound to; the old plan can
    // not be applied twice.
    let (_, replanned) = request(&app, "POST", &plan_path("default"), Value::Null).await;
    assert_eq!(replanned["policy_revision"], applied["policy_revision"], "{replanned}");
    let (status, body) = request(&app, "POST", &apply_path("default"), apply_body(&plan, shorten.clone(), Some(true))).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("retention_policy_revision_stale")), "{body}");

    // Lengthening keeps more and needs no confirmation.
    let lengthen = json!({"retention_days": 60});
    let (_, plan) = request(&app, "POST", &plan_path("default"), lengthen.clone()).await;
    assert_eq!(plan["retroactive"], false, "{plan}");
    let (status, applied) = request(&app, "POST", &apply_path("default"), apply_body(&plan, lengthen, None)).await;
    assert_eq!((status, applied["retroactive"].clone(), applied["confirmed_retroactive_pruning"].clone()),
        (StatusCode::OK, json!(false), json!(false)), "{applied}");

    // `forever`, then back to a bounded policy, which is retroactive again.
    let (_, plan) = request(&app, "POST", &plan_path("default"), json!({"retention_mode": "forever"})).await;
    let (status, applied) = request(&app, "POST", &apply_path("default"), apply_body(&plan, json!({"retention_mode": "forever"}), None)).await;
    assert_eq!((status, applied["policy"]["retention_mode"].clone(), applied["policy"]["retention_days"].clone()),
        (StatusCode::OK, json!("forever"), Value::Null), "{applied}");
    let bounded = json!({"retention_mode": "bounded", "retention_days": 365});
    let (_, plan) = request(&app, "POST", &plan_path("default"), bounded.clone()).await;
    assert_eq!(plan["retroactive"], true, "{plan}");
    let (status, body) = request(&app, "POST", &apply_path("default"), apply_body(&plan, bounded.clone(), None)).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("retention_pruning_confirmation_required")), "{body}");
    let (status, applied) = request(&app, "POST", &apply_path("default"), apply_body(&plan, bounded, Some(true))).await;
    assert_eq!((status, applied["policy"]["retention_days"].clone()), (StatusCode::OK, json!(365)), "{applied}");

    // Applying changed only the namespace's policy: no run was marked,
    // archived, unarchived or pruned, and no row was added or removed.
    assert_eq!(runs_fingerprint(&database).await, untouched);
    runtime.close().await;
    database.remove().await;
}

// Planning or applying a retention change needs an admin.
#[tokio::test]
async fn only_an_admin_plans_a_retention_change() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    );
    let app = router(runtime.clone());
    for (token, expected) in [("worker-token", StatusCode::FORBIDDEN), ("operator-token", StatusCode::FORBIDDEN), ("admin-token", StatusCode::OK)] {
        let (status, _, body) = admission::admission_http(&app, "POST", &plan_path("default"), false, Some(token), Some("2"),
            Some("default"), json!({"retention_days": 7})).await;
        assert_eq!(status, expected, "{token}: {body}");
    }
    // The apply route: an admin reaches validation, no one else gets past
    // the role check.
    for (token, expected) in [("worker-token", StatusCode::FORBIDDEN), ("operator-token", StatusCode::FORBIDDEN),
        ("admin-token", StatusCode::UNPROCESSABLE_ENTITY)] {
        let (status, _, body) = admission::admission_http(&app, "POST", &apply_path("default"), false, Some(token), Some("2"),
            Some("default"), json!({"retention_days": 7})).await;
        assert_eq!(status, expected, "{token}: {body}");
    }
    let (status, _, body) = admission::admission_http(&app, "POST", &plan_path("default"), false, Some("admin-token"), None,
        Some("default"), json!({})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::BAD_REQUEST, json!("missing_control_plane_version")), "{body}");
    runtime.close().await;
    database.remove().await;
}
