//! `GET /api/task-queues/{queue}/priority-fairness`, as published PHP 2.11.1's
//! `TaskQueuePriorityFairnessSurface` answers it: each task kind's ready tasks
//! by priority tier and fairness class, and the recent dispatch score of each
//! class.
//!
//! PHP keeps those scores in `InMemoryTaskFairnessState`, which starts empty
//! on every request of its Apache server. So each class reports a score of 0,
//! and the fairness reorder never moves a poll's first candidate: a poll takes
//! the oldest ready task of the most urgent tier, as native's poll does. The
//! reorder still decides which candidate a poll tries next when an earlier
//! one cannot be claimed: [`fairness_order`] is that reorder.
use super::{Result, backend::Backend, schedule_cron::php_compare, store::Store};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// `TaskPriority::DEFAULT`.
const DEFAULT_PRIORITY: i64 = 5;
/// `TaskFairnessKey::DEFAULT_CLASS`.
const DEFAULT_CLASS: &str = "__default__";

/// How many candidates PHP's poll reorders at a time: its candidate limit,
/// `max(10, server.polling.max_tasks_per_poll * 10)`, at the default of one.
pub(super) const FAIRNESS_BATCH: usize = 10;

/// `TaskFairnessScheduler::reorder` with an empty fairness state, as each
/// request of published PHP runs it. Takes each candidate's priority,
/// fairness key and weight in candidate order (priority, then
/// availability), and returns the candidates' indexes in the order a poll
/// tries them. Tiers keep their priority order. Within a tier, classes take
/// turns: the next candidate comes from the class with the fewest of this
/// batch's picks per unit of weight, the earliest-seen class winning a tie,
/// and each class keeps its own candidates' order. A candidate without a
/// fairness key belongs to the shared default class.
pub(super) fn fairness_order(candidates: &[(Option<i64>, Option<String>, Option<i64>)]) -> Vec<usize> {
    let mut tiers: Vec<(i64, Vec<usize>)> = Vec::new();
    for (index, (priority, _, _)) in candidates.iter().enumerate() {
        let priority = priority.unwrap_or(DEFAULT_PRIORITY);
        match tiers.iter_mut().find(|(tier, _)| *tier == priority) {
            Some((_, members)) => members.push(index),
            None => tiers.push((priority, vec![index])),
        }
    }
    tiers.sort_by_key(|(priority, _)| *priority);
    let mut order = Vec::with_capacity(candidates.len());
    for (_, members) in tiers {
        // Each class: its key, its weight (its first candidate's), its
        // remaining candidates and the picks it has had in this batch.
        let mut classes: Vec<(String, i64, std::collections::VecDeque<usize>, i64)> = Vec::new();
        for index in members {
            let (_, key, weight) = &candidates[index];
            let class = key.clone().filter(|key| !key.is_empty()).unwrap_or_else(|| DEFAULT_CLASS.to_owned());
            match classes.iter_mut().find(|(name, ..)| *name == class) {
                Some((_, _, queue, _)) => queue.push_back(index),
                None => classes.push((class, weight.filter(|weight| *weight >= 1).unwrap_or(1), [index].into(), 0)),
            }
        }
        loop {
            let mut best: Option<(usize, f64)> = None;
            for (position, (_, weight, queue, picks)) in classes.iter().enumerate() {
                if queue.is_empty() {
                    continue;
                }
                let score = *picks as f64 / (*weight).max(1) as f64;
                if best.is_none_or(|(_, best_score)| score < best_score) {
                    best = Some((position, score));
                }
            }
            let Some((position, _)) = best else { break };
            let (_, _, queue, picks) = &mut classes[position];
            order.extend(queue.pop_front());
            *picks += 1;
        }
    }
    order
}

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
    #[test]
    fn a_poll_tries_its_candidates_in_the_published_fairness_order() {
        let task = |priority: i64, key: Option<&str>, weight: i64| (Some(priority), key.map(str::to_owned), Some(weight));
        // A skipped default-class task, another default-class task, then a
        // keyed task: the keyed class is tried before the second default task.
        assert_eq!(fairness_order(&[task(5, None, 1), task(5, None, 1), task(5, Some("a"), 1)]), [0, 2, 1]);
        // Two classes alternate; a heavier class takes proportionally more turns.
        assert_eq!(fairness_order(&[task(5, Some("a"), 1), task(5, Some("a"), 1), task(5, Some("b"), 1), task(5, Some("b"), 1)]), [0, 2, 1, 3]);
        assert_eq!(fairness_order(&[task(5, Some("a"), 2), task(5, Some("a"), 2), task(5, Some("a"), 2), task(5, Some("b"), 1), task(5, Some("b"), 1)]),
            [0, 3, 1, 2, 4]);
        // Tiers never mix: every priority-1 task comes before priority 5.
        assert_eq!(fairness_order(&[task(1, Some("a"), 1), task(1, Some("a"), 1), task(5, Some("b"), 1), task(1, Some("b"), 1)]), [0, 3, 1, 2]);
        // One class, or one candidate, keeps its order.
        assert_eq!(fairness_order(&[task(5, Some("a"), 1), task(5, Some("a"), 1)]), [0, 1]);
        assert_eq!(fairness_order(&[]), Vec::<usize>::new());
    }

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
