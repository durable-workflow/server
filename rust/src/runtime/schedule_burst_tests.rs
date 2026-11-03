//! A burst of due schedules larger than one maintenance batch (#959).
//!
//! The schedules guide says native Server starts up to 100 due schedules per
//! pass, ordered by `next_fire_at`, so a burst of `N` due schedules fires
//! within `ceil(N / 100)` passes, in due order. One pass here is one
//! `fire_due_schedules` call, as one maintenance tick makes.
use super::{schedules, store::Store};
use crate::codec::{Value as Payload, ValueCodec};
use chrono::{DateTime, Utc};
use serde_json::json;

const BURST: usize = 250;
const BATCH: usize = 100;

fn stored(time: DateTime<Utc>) -> String {
    time.format("%Y-%m-%d %H:%M:%S%.6f").to_string()
}

#[tokio::test]
async fn a_burst_larger_than_one_batch_fires_once_each_in_due_order_within_ceil_n_over_100_passes() {
    let directory = tempfile::tempdir().unwrap();
    let file = directory.path().join("burst.sqlite");
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(file.to_str().unwrap()).await.unwrap());
    let input = ValueCodec::new()
        .unwrap()
        .encode(&Payload::Array(vec![Payload::Long(1)]))
        .unwrap();
    // A yearly cron: no schedule can come due a second time during the test.
    for index in 0..BURST {
        let definition = schedules::definition(&json!({"schedule_id":format!("burst-{index:03}"),
            "spec":{"cron_expressions":["0 0 1 1 *"],"timezone":"UTC"},
            "action":{"workflow_type":"echo","task_queue":"burst","input":{"codec":"avro","blob":input}},
            "overlap_policy":"skip","jitter_seconds":0}))
        .unwrap();
        store.create_schedule(definition, json!({}), json!({})).await.unwrap();
    }
    // Every occurrence is already due, in the reverse of creation order, so
    // an id order (creation order) would give a different sequence.
    let base = DateTime::from_timestamp(super::store::now().timestamp() - 600, 0).unwrap();
    let mut due_order = Vec::new();
    for position in 0..BURST {
        let schedule = format!("burst-{:03}", BURST - 1 - position);
        let occurrence = stored(base + chrono::Duration::seconds(position as i64));
        sqlx::query("UPDATE workflow_schedules SET next_fire_at=? WHERE schedule_id=?")
            .bind(&occurrence)
            .bind(&schedule)
            .execute(&store.pool)
            .await
            .unwrap();
        due_order.push((schedule, occurrence));
    }
    let mut fired: Vec<String> = Vec::new();
    let mut passes = 0;
    while fired.len() < BURST {
        passes += 1;
        assert!(passes <= BURST.div_ceil(BATCH), "the burst needed more than ceil(N / 100) passes");
        store.fire_due_schedules().await.unwrap();
        let now_fired: Vec<String> = sqlx::query_scalar(
            "SELECT schedule_id FROM workflow_schedules WHERE fires_count>0 ORDER BY schedule_id",
        )
        .fetch_all(&store.pool)
        .await
        .unwrap();
        let mut newly: Vec<String> = now_fired.into_iter().filter(|id| !fired.contains(id)).collect();
        // This pass fired exactly the next batch in due order.
        let start = fired.len();
        let mut expected: Vec<String> = due_order[start..(start + BATCH).min(BURST)]
            .iter()
            .map(|(schedule, _)| schedule.clone())
            .collect();
        newly.sort();
        expected.sort();
        assert_eq!(newly, expected, "pass {passes} fired the next batch in next_fire_at order");
        // Keep the due-order sequence, not the sorted one, for the next slice.
        fired.extend(due_order[start..start + newly.len()].iter().map(|(schedule, _)| schedule.clone()));
    }
    assert_eq!(passes, BURST.div_ceil(BATCH));
    // A further pass fires nothing: no occurrence is replayed.
    store.fire_due_schedules().await.unwrap();
    let fires: Vec<i64> = sqlx::query_scalar("SELECT fires_count FROM workflow_schedules")
        .fetch_all(&store.pool)
        .await
        .unwrap();
    assert_eq!(fires.len(), BURST);
    assert!(fires.iter().all(|count| *count == 1), "every schedule fired exactly once");
    let runs: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM workflow_runs")
        .fetch_one(&store.pool)
        .await
        .unwrap();
    assert_eq!(runs, BURST as i64);
    // Each schedule's single trigger admitted its own stored occurrence.
    for (schedule, occurrence) in &due_order {
        let triggered: Vec<String> = sqlx::query_scalar(
            "SELECT e.occurrence_at_utc FROM workflow_schedule_history_events e JOIN workflow_schedules s ON s.id=e.workflow_schedule_id WHERE s.schedule_id=? AND e.event_type='ScheduleTriggered'",
        )
        .bind(schedule)
        .fetch_all(&store.pool)
        .await
        .unwrap();
        assert_eq!(&triggered, &[occurrence.clone()], "{schedule} triggered once at its occurrence");
    }
    store.close().await;
}
