//! Bind a known PHP transport job to an existing canonical task and run.
//! The borrowed rows retain their original state. This never authorizes a new
//! claim, deletes a job or replaces a durable deadline with a transport wakeup.
use super::queue::{QueueDecodeError, QueueTaskKind, inspect_queue_command};
use serde::Deserialize;

#[derive(Debug, PartialEq, Eq)]
pub enum QueueBindingError {
    Decode(QueueDecodeError),
    MalformedEnvelope,
    UnsupportedEnvelope,
    InvalidTransportRow,
    InvalidCanonicalState,
    TaskIdentity,
    TaskKind,
    RunIdentity,
    Namespace,
    WorkerQueue,
    TransportQueue,
    TransportConnection,
}

#[derive(Clone, Debug, Deserialize, PartialEq, Eq)]
#[serde(deny_unknown_fields)]
pub struct SourceQueueJob {
    pub id: i64,
    pub queue: String,
    pub payload: String,
    pub attempts: i64,
    pub reserved_at: Option<i64>,
    pub available_at: i64,
    pub created_at: i64,
}

#[derive(Clone, Debug, Deserialize, PartialEq, Eq)]
#[serde(deny_unknown_fields)]
pub struct SourceTask {
    pub id: String,
    pub workflow_run_id: String,
    pub namespace: Option<String>,
    pub task_type: String,
    pub status: String,
    pub connection: Option<String>,
    pub queue: Option<String>,
    pub available_at: Option<String>,
    pub lease_owner: Option<String>,
    pub lease_expires_at: Option<String>,
    pub attempt_count: i64,
}

#[derive(Clone, Debug, Deserialize, PartialEq, Eq)]
#[serde(deny_unknown_fields)]
pub struct SourceRun {
    pub id: String,
    pub workflow_instance_id: String,
    pub workflow_class: String,
    pub workflow_type: String,
    pub namespace: Option<String>,
    pub queue: Option<String>,
    pub status: String,
}

pub struct QueueSourceConfig<'a> {
    /// The logical database queue connection whose jobs table is being read.
    pub connection: &'a str,
    pub default_connection: &'a str,
    pub default_queue: &'a str,
}

#[derive(Debug)]
pub struct QueueTaskBinding<'a> {
    pub kind: QueueTaskKind,
    pub job: &'a SourceQueueJob,
    pub task: &'a SourceTask,
    pub run: &'a SourceRun,
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct Envelope {
    uuid: String,
    display_name: String,
    job: String,
    max_tries: Option<u32>,
    max_exceptions: Option<u32>,
    fail_on_timeout: bool,
    count_crashes_as_exceptions: bool,
    backoff: Option<serde_json::Value>,
    timeout: Option<i64>,
    retry_until: Option<i64>,
    delete_when_missing_models: bool,
    data: Command,
    created_at: i64,
    delay: Option<i64>,
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
struct Command {
    command_name: String,
    command: String,
    batch_id: Option<String>,
}

fn identifier(value: &str) -> bool {
    !value.is_empty() && value.chars().count() <= 255 && !value.contains('\0')
}

pub fn bind_queue_job<'a>(
    job: &'a SourceQueueJob,
    task: &'a SourceTask,
    run: &'a SourceRun,
    config: &QueueSourceConfig<'_>,
) -> Result<QueueTaskBinding<'a>, QueueBindingError> {
    // Check the outer body before allocating/unescaping its PHP command.
    if job.payload.len() > 128 * 1024 {
        return Err(QueueBindingError::MalformedEnvelope);
    }
    let envelope: Envelope =
        serde_json::from_str(&job.payload).map_err(|_| QueueBindingError::MalformedEnvelope)?;
    let command = inspect_queue_command(envelope.data.command.as_bytes())
        .map_err(QueueBindingError::Decode)?;
    let (class, kind, tries) = match command.kind {
        QueueTaskKind::Workflow => ("Workflow\\V2\\Jobs\\RunWorkflowTask", "workflow", 1),
        QueueTaskKind::Activity => ("Workflow\\V2\\Jobs\\RunActivityTask", "activity", 1),
        QueueTaskKind::Timer => ("Workflow\\V2\\Jobs\\RunTimerTask", "timer", 5),
    };
    if envelope.job != "Illuminate\\Queue\\CallQueuedHandler@call"
        || envelope.display_name != class
        || envelope.data.command_name != class
        || !identifier(&envelope.uuid)
        || envelope.max_tries != Some(tries)
        || envelope.max_exceptions.is_some()
        || envelope.fail_on_timeout
        || envelope.count_crashes_as_exceptions
        || envelope.backoff.is_some()
        || envelope.timeout.is_some()
        || envelope.retry_until.is_some()
        || envelope.delete_when_missing_models
        || envelope.data.batch_id.is_some()
        || envelope.created_at < 0
        || envelope.delay.is_some_and(|delay| delay < 0)
    {
        return Err(QueueBindingError::UnsupportedEnvelope);
    }
    if job.id <= 0
        || !(0..=255).contains(&job.attempts)
        || job.reserved_at.is_some_and(|time| time < 0)
        || job.available_at < 0
        || job.created_at < 0
    {
        return Err(QueueBindingError::InvalidTransportRow);
    }
    if task.id != command.task_id {
        return Err(QueueBindingError::TaskIdentity);
    }
    if task.task_type != kind {
        return Err(QueueBindingError::TaskKind);
    }
    if task.workflow_run_id != run.id {
        return Err(QueueBindingError::RunIdentity);
    }
    let namespace = task
        .namespace
        .as_deref()
        .ok_or(QueueBindingError::Namespace)?;
    if !identifier(namespace) || run.namespace.as_deref() != Some(namespace) {
        return Err(QueueBindingError::Namespace);
    }
    if !identifier(&run.id)
        || !identifier(&run.workflow_instance_id)
        || !identifier(&run.workflow_class)
        || !identifier(&run.workflow_type)
        || !identifier(&run.status)
        || !identifier(&task.status)
        || task.attempt_count < 0
    {
        return Err(QueueBindingError::InvalidCanonicalState);
    }
    // Workflow/timer tasks return to the parent's SDK queue. An activity may
    // target a different SDK queue. Neither is Laravel's transport queue.
    if kind != "activity"
        && (task.queue.as_deref().is_none_or(|queue| !identifier(queue)) || task.queue != run.queue)
    {
        return Err(QueueBindingError::WorkerQueue);
    }
    if !identifier(config.default_queue)
        || command.queue.unwrap_or(config.default_queue) != job.queue
    {
        return Err(QueueBindingError::TransportQueue);
    }
    if !identifier(config.connection)
        || !identifier(config.default_connection)
        || command.connection.unwrap_or(config.default_connection) != config.connection
    {
        return Err(QueueBindingError::TransportConnection);
    }
    Ok(QueueTaskBinding {
        kind: command.kind,
        job,
        task,
        run,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(Deserialize)]
    struct Source {
        jobs: Vec<SourceQueueJob>,
        tasks: Vec<SourceTask>,
        runs: Vec<SourceRun>,
    }
    fn source() -> Source {
        serde_json::from_str(include_str!(
            "../../../../tests/Fixtures/ServerUpgrade/php-2.8.2-pending-queue.json"
        ))
        .unwrap()
    }
    fn original(source: &Source) -> (&SourceQueueJob, &SourceTask, &SourceRun) {
        let job = &source.jobs[0];
        let task = source
            .tasks
            .iter()
            .find(|task| task.task_type == "timer")
            .unwrap();
        let run = source
            .runs
            .iter()
            .find(|run| run.id == task.workflow_run_id)
            .unwrap();
        (job, task, run)
    }
    fn config() -> QueueSourceConfig<'static> {
        QueueSourceConfig {
            connection: "database",
            default_connection: "database",
            default_queue: "default",
        }
    }

    #[test]
    fn actual_pending_job_binds_its_original_task_without_rebasing_or_replacing_queues() {
        let source = source();
        let (job, task, run) = original(&source);
        let bound = bind_queue_job(job, task, run, &config()).unwrap();
        assert_eq!(bound.kind, QueueTaskKind::Timer);
        assert!(
            std::ptr::eq(bound.task, task)
                && std::ptr::eq(bound.run, run)
                && std::ptr::eq(bound.job, job)
        );
        assert_eq!(bound.job.queue, "default");
        assert_eq!(bound.task.queue.as_deref(), Some("upgrade-source"));
        assert_eq!(
            bound.task.available_at.as_deref(),
            Some("2026-10-15 08:16:21.566122")
        );
        assert_eq!(bound.job.available_at, 1792052182);
        assert_eq!(bound.task.attempt_count, 0);
        assert_eq!(bound.task.status, "ready");
        let leased = source
            .tasks
            .iter()
            .find(|task| task.task_type == "activity")
            .unwrap();
        assert_eq!(
            leased.lease_expires_at.as_deref(),
            Some("2026-10-15 08:11:21.169593")
        );
        assert_eq!(leased.attempt_count, 1);
    }

    #[test]
    fn wrong_task_kind_run_namespace_and_worker_queue_refuse_the_original_job() {
        let source = source();
        let (job, original, run) = original(&source);
        let mut task = original.clone();
        task.id.push('x');
        assert!(matches!(
            bind_queue_job(job, &task, run, &config()),
            Err(QueueBindingError::TaskIdentity)
        ));
        task = original.clone();
        task.task_type = "activity".into();
        assert!(matches!(
            bind_queue_job(job, &task, run, &config()),
            Err(QueueBindingError::TaskKind)
        ));
        task = original.clone();
        task.workflow_run_id.push('x');
        assert!(matches!(
            bind_queue_job(job, &task, run, &config()),
            Err(QueueBindingError::RunIdentity)
        ));
        task = original.clone();
        task.namespace = Some("foreign".into());
        assert!(matches!(
            bind_queue_job(job, &task, run, &config()),
            Err(QueueBindingError::Namespace)
        ));
        task = original.clone();
        task.queue = Some("foreign".into());
        assert!(matches!(
            bind_queue_job(job, &task, run, &config()),
            Err(QueueBindingError::WorkerQueue)
        ));
    }

    #[test]
    fn outer_handler_names_policy_and_transport_scope_cannot_fabricate_a_binding() {
        let source = source();
        let (original, task, run) = original(&source);
        for (key, value) in [
            ("job", serde_json::json!("Unknown@call")),
            ("displayName", serde_json::json!("Unknown")),
            ("maxTries", serde_json::json!(99)),
            ("backoff", serde_json::json!([1, 2])),
            ("unexpected", serde_json::json!(true)),
        ] {
            let mut body: serde_json::Value = serde_json::from_str(&original.payload).unwrap();
            body[key] = value;
            let mut job = original.clone();
            job.payload = body.to_string();
            assert!(bind_queue_job(&job, task, run, &config()).is_err(), "{key}");
        }
        let mut job = original.clone();
        job.queue = "foreign".into();
        assert!(matches!(
            bind_queue_job(&job, task, run, &config()),
            Err(QueueBindingError::TransportQueue)
        ));
        let other = QueueSourceConfig {
            connection: "redis",
            ..config()
        };
        assert!(matches!(
            bind_queue_job(original, task, run, &other),
            Err(QueueBindingError::TransportConnection)
        ));
    }

    #[test]
    fn duplicate_json_fields_and_oversized_outer_bodies_are_refused() {
        let source = source();
        let (original, task, run) = original(&source);
        let mut job = original.clone();
        job.payload = original
            .payload
            .replacen("{", "{\"job\":\"Unknown@call\",", 1);
        assert!(matches!(
            bind_queue_job(&job, task, run, &config()),
            Err(QueueBindingError::MalformedEnvelope)
        ));
        job.payload = original
            .payload
            .replace("\"data\":{", "\"data\":{\"command\":\"N;\",");
        assert!(matches!(
            bind_queue_job(&job, task, run, &config()),
            Err(QueueBindingError::MalformedEnvelope)
        ));
        job.payload = "x".repeat(128 * 1024 + 1);
        assert!(matches!(
            bind_queue_job(&job, task, run, &config()),
            Err(QueueBindingError::MalformedEnvelope)
        ));
    }

    #[test]
    fn absent_canonical_identity_and_namespace_are_not_filled_from_transport_metadata() {
        let source = source();
        let (job, original_task, original_run) = original(&source);
        let mut task = original_task.clone();
        let mut run = original_run.clone();
        task.workflow_run_id.clear();
        run.id.clear();
        assert!(matches!(
            bind_queue_job(job, &task, &run, &config()),
            Err(QueueBindingError::InvalidCanonicalState)
        ));
        task = original_task.clone();
        task.namespace = None;
        assert!(matches!(
            bind_queue_job(job, &task, original_run, &config()),
            Err(QueueBindingError::Namespace)
        ));
        task = original_task.clone();
        task.attempt_count = -1;
        assert!(matches!(
            bind_queue_job(job, &task, original_run, &config()),
            Err(QueueBindingError::InvalidCanonicalState)
        ));
    }
}
