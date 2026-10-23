//! `GET /api/task-queues/{queue}/priority-fairness`, as published PHP 2.11.1's
//! `TaskQueuePriorityFairnessSurface` answers it: each task kind's ready tasks
//! by priority tier and fairness class, and the recent dispatch score of each
//! class.
//!
//! PHP keeps those scores in `InMemoryTaskFairnessState`, which starts empty
//! on every request of its Apache server. So each class reports a score of 0,
//! and the fairness reorder never moves a poll's first candidate: a poll takes
//! the oldest ready task of the most urgent tier, as native's poll does.
use super::{Result, backend::Backend, schedule_cron::php_compare, store::Store};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// `TaskPriority::DEFAULT`.
const DEFAULT_PRIORITY: i64 = 5;
/// `TaskFairnessKey::DEFAULT_CLASS`.
const DEFAULT_CLASS: &str = "__default__";

/// One fairness class in a priority tier.
struct Class {
    key: String,
    fairness_key: Option<String>,
    count: i64,
    weight: i64,
}

/// `surfaceFor`: one task kind's ready tasks, in the rows' order.
fn surface(rows: &[(Option<i64>, Option<String>, Option<i64>)]) -> Value {
    let mut tiers: Vec<(i64, Vec<Class>)> = Vec::new();
    let mut seen: Vec<String> = Vec::new();
    for (priority, fairness_key, weight) in rows {
        let priority = priority.unwrap_or(DEFAULT_PRIORITY);
        let fairness_key = fairness_key.clone().filter(|key| !key.is_empty());
        let key = fairness_key.clone().unwrap_or_else(|| DEFAULT_CLASS.to_owned());
        if !seen.contains(&key) {
            seen.push(key.clone());
        }
        let index = match tiers.iter().position(|(tier, _)| *tier == priority) {
            Some(index) => index,
            None => {
                tiers.push((priority, Vec::new()));
                tiers.len() - 1
            }
        };
        let classes = &mut tiers[index].1;
        match classes.iter_mut().find(|class| class.key == key) {
            Some(class) => class.count += 1,
            // The class keeps its first task's weight.
            None => classes.push(Class { key, fairness_key, count: 1, weight: weight.filter(|weight| *weight >= 1).unwrap_or(1) }),
        }
    }
    // `ksort`, for the tiers and for each tier's classes.
    tiers.sort_by_key(|(priority, _)| *priority);
    let tiers: Vec<Value> = tiers
        .into_iter()
        .map(|(priority, mut classes)| {
            classes.sort_by(|left, right| php_compare(&left.key, &right.key));
            let count: i64 = classes.iter().map(|class| class.count).sum();
            let classes: Vec<Value> = classes
                .iter()
                .map(|class| json!({"fairness_key":class.fairness_key,"count":class.count,"fairness_weight":class.weight}))
                .collect();
            json!({"priority":priority,"count":count,"classes":classes})
        })
        .collect();
    let recent: Vec<Value> = seen
        .iter()
        .map(|key| json!({"fairness_key":(key != DEFAULT_CLASS).then_some(key),"score":0}))
        .collect();
    json!({"ready_tasks":rows.len(),"priority_tiers":tiers,"recent_dispatch":recent})
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    pub(crate) async fn priority_fairness(&self, queue: &str) -> Result<Value> {
        let mut surfaces = Vec::with_capacity(2);
        for kind in ["workflow", "activity"] {
            let rows = Self::query("SELECT priority,fairness_key,fairness_weight FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type=$3 AND status='ready' ORDER BY id")
                .bind(self.namespace.as_ref()).bind(queue).bind(kind).fetch_all(&self.pool).await?;
            let rows = rows
                .iter()
                .map(|row| Ok((DB::optional_number(row, "priority")?, DB::optional_string(row, "fairness_key")?, DB::optional_number(row, "fairness_weight")?)))
                .collect::<Result<Vec<_>>>()?;
            surfaces.push(surface(&rows));
        }
        let activity = surfaces.pop();
        let workflow = surfaces.pop();
        Ok(json!({"namespace":self.namespace.as_ref(),"queue":queue,"workflow_task":workflow,"activity_task":activity}))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn tiers_and_classes_follow_php_s_snapshot() {
        let row = |priority: i64, key: Option<&str>, weight: i64| (Some(priority), key.map(str::to_owned), Some(weight));
        let rows = [row(5, Some("tenant-a"), 1), row(5, Some("tenant-a"), 3), row(5, Some("tenant-b"), 2), row(5, None, 1),
            row(1, Some("urgent"), 1), row(9, Some(""), 1)];
        assert_eq!(surface(&rows), json!({"ready_tasks":6,"priority_tiers":[
            {"priority":1,"count":1,"classes":[{"fairness_key":"urgent","count":1,"fairness_weight":1}]},
            {"priority":5,"count":4,"classes":[{"fairness_key":null,"count":1,"fairness_weight":1},
                {"fairness_key":"tenant-a","count":2,"fairness_weight":1},{"fairness_key":"tenant-b","count":1,"fairness_weight":2}]},
            {"priority":9,"count":1,"classes":[{"fairness_key":null,"count":1,"fairness_weight":1}]}],
            "recent_dispatch":[{"fairness_key":"tenant-a","score":0},{"fairness_key":"tenant-b","score":0},
                {"fairness_key":null,"score":0},{"fairness_key":"urgent","score":0}]}));
        assert_eq!(surface(&[]), json!({"ready_tasks":0,"priority_tiers":[],"recent_dispatch":[]}));
    }
}
