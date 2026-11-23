//! The native checkpoint DDL and its MySQL-family catalog verification.
use super::{Result, refuse};
use axum::http::StatusCode;
use serde::Deserialize;
use sha2::{Digest, Sha256};
use sqlx::MySqlConnection;
use std::sync::OnceLock;

const ROUTINE_BODY: &str = include_str!("../../migrations/mysql/0021_child_summary_checkpoint_catalog.sql");
const VIEW_SQL: &str = "CREATE SQL SECURITY DEFINER VIEW dw_child_summary_catalog AS SELECT dw_child_summary_catalog() AS catalog;\n";
const VIEW_DEFINITION: &str = "select `dw_child_summary_catalog`() AS `catalog`";

pub(super) const VIEW: &str = "dw_child_summary_catalog";

type Trigger = (String, String, String, String, String, i64, String, i64);
type Routine = (String, String, String, String, String, String, i64);
type View = (String, String, String, String, i64);

#[derive(Clone, Copy, Default)]
pub(super) struct CheckpointCode {
    pub(super) triggers: usize,
    pub(super) routine: bool,
    pub(super) view: bool,
}

impl CheckpointCode {
    pub(super) fn complete(self) -> bool {
        self.routine && self.view && self.triggers == definitions().len()
    }

    pub(super) fn empty(self) -> bool {
        !self.routine && !self.view && self.triggers == 0
    }

    pub(super) fn missing_ddl(self) -> Vec<String> {
        let mut statements = Vec::new();
        if !self.routine {
            statements.push(format!(
                "CREATE FUNCTION dw_child_summary_catalog() RETURNS LONGTEXT CHARACTER SET utf8mb4\nREADS SQL DATA SQL SECURITY DEFINER\n{};\n",
                ROUTINE_BODY.trim_end()
            ));
        }
        if !self.view {
            statements.push(VIEW_SQL.to_owned());
        }
        statements.extend(definitions()[self.triggers..].iter().map(|trigger| trigger.statement()));
        statements
    }
}

#[derive(Deserialize)]
struct ReadOnlyCatalog {
    triggers: Vec<Trigger>,
    routine: Routine,
    view: View,
    routines: i64,
    events: i64,
}

struct Definition {
    name: String,
    table: &'static str,
    event: &'static str,
    action: String,
    hash: String,
}

impl Definition {
    fn statement(&self) -> String {
        format!(
            "CREATE TRIGGER {} AFTER {} ON {} FOR EACH ROW {};\n",
            self.name, self.event, self.table, self.action
        )
    }
}

fn definitions() -> &'static [Definition] {
    static DEFINITIONS: OnceLock<Vec<Definition>> = OnceLock::new();
    DEFINITIONS.get_or_init(|| {
        let tables = [
            ("history", "workflow_history_events", "workflow_run_id"),
            ("wait", "workflow_run_waits", "workflow_run_id"),
            ("call", "workflow_child_calls", "parent_workflow_run_id"),
            ("link", "workflow_links", "parent_workflow_run_id"),
            ("task", "workflow_tasks", "workflow_run_id"),
            ("failure", "workflow_failures", "workflow_run_id"),
            ("signal", "workflow_signal_records", "workflow_run_id"),
            ("current", "workflow_instances", "current_run_id"),
            ("summary", "workflow_run_summaries", "id"),
        ];
        let mut triggers = Vec::new();
        for (name, table, key) in tables {
            let events = [("INSERT", format!("= NEW.{key}")),
                ("UPDATE", format!("IN (OLD.{key}, NEW.{key})")),
                ("DELETE", format!("= OLD.{key}"))];
            for (event, condition) in events {
                let action = format!("UPDATE dw_child_summary_checkpoints SET generation = generation + 1 WHERE run_id {condition}");
                triggers.push(Definition {
                    name: format!("dw_child_summary_{name}_{}", event.to_ascii_lowercase()),
                    table,
                    event,
                    hash: digest(&action),
                    action,
                });
            }
        }
        triggers
    })
}

fn digest(sql: &str) -> String {
    Sha256::digest(sql).iter().map(|byte| format!("{byte:02x}")).collect()
}

pub(super) fn bootstrap_sql() -> &'static str {
    static SQL: OnceLock<String> = OnceLock::new();
    SQL.get_or_init(|| CheckpointCode::default().missing_ddl().concat())
}

fn matches_routine((kind, security, access, output, deterministic, hash, owner): &Routine) -> bool {
    kind == "FUNCTION" && security == "DEFINER" && access == "READS SQL DATA"
        && output == "longtext" && deterministic == "NO"
        && *hash == digest(ROUTINE_BODY.trim_end()) && *owner == 1
}

fn matches_view((security, option, updatable, hash, owner): &View) -> bool {
    security == "DEFINER" && option == "NONE" && matches!(updatable.as_str(), "NO" | "")
        && *hash == digest(VIEW_DEFINITION) && *owner == 1
}

fn matches_triggers(rows: &[Trigger]) -> bool {
    let Some(prefix) = definitions().get(..rows.len()) else { return false; };
    rows.iter().all(|(name, table, event, timing, orientation, order, hash, owner)| {
        prefix.iter().any(|expected| {
            name == &expected.name && table == expected.table && event == expected.event
                && timing == "AFTER" && orientation == "ROW" && *order == 1
                && hash == &expected.hash && *owner == 1
        })
    })
}

pub(super) fn mismatch() -> super::super::RuntimeError {
    refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")
}

pub(super) async fn direct(
    connection: &mut MySqlConnection,
    marked: bool,
    view_present: bool,
    observer_definer: Option<&str>,
) -> Result<CheckpointCode> {
    let routines: Vec<(String, String, String, String, String, String, String, i64)> = sqlx::query_as(
        "SELECT ROUTINE_NAME,ROUTINE_TYPE,SECURITY_TYPE,SQL_DATA_ACCESS,COALESCE(DTD_IDENTIFIER,''),IS_DETERMINISTIC,
         COALESCE(SHA2(TRIM(TRAILING ';' FROM TRIM(ROUTINE_DEFINITION)),256),''),
         CAST(BINARY DEFINER=BINARY COALESCE(?,CURRENT_USER()) AS SIGNED)
         FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() LIMIT 2")
        .bind(observer_definer)
        .fetch_all(&mut *connection).await?;
    let triggers: Vec<Trigger> = sqlx::query_as(
        "SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_ORIENTATION,
         CAST(ACTION_ORDER AS SIGNED), SHA2(TRIM(TRAILING ';' FROM TRIM(ACTION_STATEMENT)),256),
         CAST(BINARY DEFINER=BINARY COALESCE(?,CURRENT_USER()) AS SIGNED)
         FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME LIMIT 28")
        .bind(observer_definer)
        .fetch_all(&mut *connection).await?;
    let events: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()")
        .fetch_one(&mut *connection).await?;
    if events != 0 || !marked && (view_present || !routines.is_empty() || !triggers.is_empty()) {
        return Err(refuse(StatusCode::CONFLICT, "existing_database_requires_qualified_takeover"));
    }
    let routine = match routines.as_slice() {
        [] => false,
        [(name, kind, security, access, output, deterministic, hash, owner)] if name == VIEW => {
            if !matches_routine(&(kind.clone(), security.clone(), access.clone(), output.clone(),
                deterministic.clone(), hash.clone(), *owner)) {
                return Err(mismatch());
            }
            true
        }
        _ => return Err(mismatch()),
    };
    if view_present {
        let view: Option<View> = sqlx::query_as(
            "SELECT SECURITY_TYPE,CHECK_OPTION,IS_UPDATABLE,SHA2(VIEW_DEFINITION,256),
             CAST(BINARY DEFINER=BINARY COALESCE(?,CURRENT_USER()) AS SIGNED)
             FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dw_child_summary_catalog'")
            .bind(observer_definer)
            .fetch_optional(connection).await?;
        if !routine || !view.as_ref().is_some_and(matches_view) {
            return Err(mismatch());
        }
    }
    if !matches_triggers(&triggers) || (!triggers.is_empty() && !view_present) {
        return Err(mismatch());
    }
    Ok(CheckpointCode { triggers: triggers.len(), routine, view: view_present })
}

/// MySQL hides trigger definitions from SELECT-only accounts. This fixed
/// definer query returns only catalog metadata and definition digests.
pub(super) async fn read_only(connection: &mut MySqlConnection) -> Result<()> {
    let json: String = sqlx::query_scalar("SELECT catalog FROM dw_child_summary_catalog")
        .fetch_one(connection).await?;
    let catalog: ReadOnlyCatalog = serde_json::from_str(&json)?;
    if catalog.routines != 1 || catalog.events != 0 || catalog.triggers.len() != definitions().len()
        || !matches_routine(&catalog.routine) || !matches_view(&catalog.view)
        || !matches_triggers(&catalog.triggers)
    {
        return Err(mismatch());
    }
    Ok(())
}

#[cfg(test)]
pub(super) fn first_trigger() -> String {
    definitions()[0].statement()
}

#[cfg(test)]
pub(super) fn trigger_count() -> usize {
    definitions().len()
}
