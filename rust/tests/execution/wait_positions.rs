//! Stored wait positions are chronological over every retained wait of a run,
//! as Workflow #941's projector stores them, and a transition writes only the
//! waits whose position moved (Server #783).
use super::run_observation_signals::{claim, finish, register_signal_worker, signal};
use super::*;

const RUN: &str = "wait-positions";

/// Log position writes. SQLite and PostgreSQL log every write of the column,
/// including a rewrite of the same value; MySQL has no column-scoped update
/// trigger, so it logs only writes that change the value.
async fn log_position_writes(database: &TestDatabase) {
    database.execute("CREATE TABLE wait_position_writes (wait_id VARCHAR(191) NOT NULL, old_position BIGINT NOT NULL, new_position BIGINT NOT NULL)").await;
    match database {
        TestDatabase::Sqlite(_) => {
            database.execute("CREATE TRIGGER log_wait_position AFTER UPDATE OF position ON workflow_run_waits BEGIN INSERT INTO wait_position_writes VALUES (new.wait_id, old.position, new.position); END").await;
        }
        TestDatabase::Postgres { .. } => {
            database.execute("CREATE FUNCTION log_wait_position() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN INSERT INTO wait_position_writes VALUES (NEW.wait_id, OLD.position, NEW.position); RETURN NEW; END $$").await;
            database.execute("CREATE TRIGGER log_wait_position AFTER UPDATE OF position ON workflow_run_waits FOR EACH ROW EXECUTE FUNCTION log_wait_position()").await;
        }
        TestDatabase::MySql { .. } => {
            database.execute("CREATE TRIGGER log_wait_position AFTER UPDATE ON workflow_run_waits FOR EACH ROW INSERT INTO wait_position_writes SELECT NEW.wait_id, OLD.position, NEW.position FROM DUAL WHERE NOT (OLD.position <=> NEW.position)").await;
        }
    }
}

/// Logged position writes as `(wait_id, "old>new")`, sorted by wait id.
async fn position_writes(database: &TestDatabase) -> Vec<(String, String)> {
    sorted(text_rows(database,
        "SELECT wait_id, old_position||'>'||new_position FROM wait_position_writes",
        "SELECT wait_id, old_position::text||'>'||new_position::text FROM wait_position_writes",
        "SELECT CAST(wait_id AS CHAR), CAST(CONCAT(old_position,'>',new_position) AS CHAR) FROM wait_position_writes").await)
}

/// The run's waits as `(wait_id, "position:status")`, sorted by wait id.
async fn waits(database: &TestDatabase) -> Vec<(String, String)> {
    sorted(text_rows(database,
        "SELECT wait_id, position||':'||status FROM workflow_run_waits WHERE workflow_instance_id='wait-positions'",
        "SELECT wait_id, position::text||':'||status FROM workflow_run_waits WHERE workflow_instance_id='wait-positions'",
        "SELECT CAST(wait_id AS CHAR), CAST(CONCAT(position,':',status) AS CHAR) FROM workflow_run_waits WHERE workflow_instance_id='wait-positions'").await)
}

/// The run's waits as `(wait_id, kind)`, sorted by wait id.
async fn kinds(database: &TestDatabase) -> Vec<(String, String)> {
    sorted(text_rows(database,
        "SELECT wait_id, kind FROM workflow_run_waits WHERE workflow_instance_id='wait-positions'",
        "SELECT wait_id, kind FROM workflow_run_waits WHERE workflow_instance_id='wait-positions'",
        "SELECT CAST(wait_id AS CHAR), CAST(kind AS CHAR) FROM workflow_run_waits WHERE workflow_instance_id='wait-positions'").await)
}

fn sorted(mut rows: Vec<(String, String)>) -> Vec<(String, String)> {
    rows.sort();
    rows
}

fn expected(rows: &[(&str, &str)]) -> Vec<(String, String)> {
    sorted(rows.iter().map(|(id, value)| ((*id).to_owned(), (*value).to_owned())).collect())
}

async fn set_wait(database: &TestDatabase, wait_id: &str, opened_at: &str, position: i64) {
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_run_waits SET opened_at='{opened_at}',position={position} WHERE workflow_instance_id='{RUN}' AND wait_id='{wait_id}'"
    ))).await;
}

#[tokio::test]
async fn wait_positions_are_chronological_and_written_only_when_a_wait_moves() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_signal_worker(&app, "default").await;
    let run = namespaces::start_scoped(&app, "default", RUN).await;
    let task = claim(&app, "default").await;
    let opened = finish(&app, "default", &task, json!([
        {"type":"start_timer","delay_seconds":3600},
        {"type":"start_timer","delay_seconds":7200},
        {"type":"open_signal_wait","signal_name":"payload"}])).await;
    assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
    let opened = kinds(&database).await;
    let timers: Vec<&str> = opened.iter().filter(|(_, kind)| kind == "timer").map(|(id, _)| id.as_str()).collect();
    let signals: Vec<&str> = opened.iter().filter(|(_, kind)| kind == "signal").map(|(id, _)| id.as_str()).collect();
    assert_eq!((timers.len(), signals.len(), opened.len()), (2, 1, 3), "{opened:?}");
    let (first_signal, early_timer, late_timer) = (signals[0].to_owned(), timers[0].to_owned(), timers[1].to_owned());
    // Retained clocks that run against wait-id order, stored in chronological
    // order: the later timer id opened first and the earlier one last.
    set_wait(&database, &late_timer, "2026-01-01 00:00:01", 0).await;
    set_wait(&database, &first_signal, "2026-01-01 00:00:02", 1).await;
    set_wait(&database, &early_timer, "2026-01-01 00:00:03", 2).await;
    log_position_writes(&database).await;

    // Resolving a wait moves no wait: the open-wait ordinal would have moved
    // the last timer from 2 to 1 and rewritten the first in place.
    let received = signal(&app, "default", &run).await;
    assert_eq!(received.0, StatusCode::ACCEPTED, "{}", received.1);
    let task = claim(&app, "default").await;
    assert_eq!(
        waits(&database).await,
        expected(&[(&late_timer, "0:open"), (&first_signal, "1:resolved"), (&early_timer, "2:open")])
    );
    assert_eq!(position_writes(&database).await, Vec::new());

    // A new wait opens after every retained wait and is the only one that
    // may be written.
    let reopened = finish(&app, "default", &task, json!([{"type":"open_signal_wait","signal_name":"payload"}])).await;
    assert_eq!(reopened.0, StatusCode::OK, "{}", reopened.1);
    let current = kinds(&database).await;
    let second_signal = current.iter().find(|(id, kind)| kind == "signal" && *id != first_signal).map(|(id, _)| id.clone()).unwrap();
    assert_eq!(
        waits(&database).await,
        expected(&[(&late_timer, "0:open"), (&first_signal, "1:resolved"), (&early_timer, "2:open"), (&second_signal, "3:open")])
    );
    let writes = position_writes(&database).await;
    assert!(writes.iter().all(|(id, _)| *id == second_signal), "{writes:?}");

    // Positions stored by another projector converge on the chronological
    // ordinal, and only those rows are written.
    set_wait(&database, &late_timer, "2026-01-01 00:00:01", 9).await;
    set_wait(&database, &early_timer, "2026-01-01 00:00:03", 7).await;
    database.execute("DELETE FROM wait_position_writes").await;
    let received = signal(&app, "default", &run).await;
    assert_eq!(received.0, StatusCode::ACCEPTED, "{}", received.1);
    let task = claim(&app, "default").await;
    let closed = finish(&app, "default", &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(closed.0, StatusCode::OK, "{}", closed.1);
    let positions: Vec<(String, String)> = waits(&database)
        .await
        .into_iter()
        .map(|(id, value)| (id, value.split(':').next().unwrap().to_owned()))
        .collect();
    assert_eq!(
        positions,
        expected(&[(&late_timer, "0"), (&first_signal, "1"), (&early_timer, "2"), (&second_signal, "3")])
    );
    assert_eq!(
        position_writes(&database).await,
        expected(&[(&early_timer, "7>2"), (&late_timer, "9>0")])
    );
}
