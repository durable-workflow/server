//! Application fixture compiled against the selected crates.io SDK.
//! Worker requests use the SDK. Administrative observations use the Server API.
use durable_workflow::{
    json, Client, StickyCacheOptions, Value, Worker, WorkflowHandle, WorkflowResultOptions,
};
use std::{
    env, fs,
    io::{BufRead, BufReader, Write},
    process::{Child, ChildStdin, ChildStdout, Command, Stdio},
    sync::{
        atomic::{AtomicUsize, Ordering},
        Arc, Mutex,
    },
    time::{Duration, Instant},
};

type Result<T> = std::result::Result<T, Box<dyn std::error::Error>>;
const TYPE: &str = "conformance.rust-build-cohort";

fn require(ok: bool, message: &str) -> Result<()> {
    if ok {
        Ok(())
    } else {
        Err(message.into())
    }
}

fn client() -> Result<Client> {
    Ok(Client::builder(env::var("DW_WV_SERVER_URL")?)
        .token(Some(
            env::var("DW_WV_AUTH_TOKEN").unwrap_or_else(|_| "dev-token".into()),
        ))
        .namespace(env::var("DW_WV_NAMESPACE")?)
        .build()?)
}

async fn worker_process(args: &[String]) -> Result<()> {
    let queue = &args[2];
    let id = &args[3];
    let build = &args[4];
    let producer = args[5].clone();
    let effects = Arc::new(AtomicUsize::new(0));
    let seen = Arc::new(Mutex::new(Vec::<Value>::new()));
    let mut worker = Worker::new(client()?, queue)
        .worker_id(id)
        .build_id(build)
        .sticky_cache(
            StickyCacheOptions::new(1)
                .max_history_bytes(1024 * 1024)
                .ttl(Duration::from_secs(60)),
        )?
        .poll_timeout(Duration::from_secs(1));
    let callback_effects = effects.clone();
    let callback_seen = seen.clone();
    worker.register_workflow(TYPE, move |ctx, _| {
        let effects = callback_effects.clone();
        let seen = callback_seen.clone();
        let producer = producer.clone();
        async move {
            let identity = ctx.workflow_identity()?;
            let recorded = ctx.side_effect(|| {
                effects.fetch_add(1, Ordering::SeqCst);
                producer
            })?;
            let observe = |boundary| {
                seen.lock().unwrap().push(json!({"workflow_id":identity.workflow_id,"run_id":identity.run_id,"recorded":recorded,"boundary":boundary}));
            };
            observe("ready");
            ctx.wait_signal("ready").await?;
            observe("finish");
            ctx.wait_signal("finish").await?;
            observe("settle");
            ctx.wait_signal("settle").await?;
            observe("completed");
            Ok(json!({"producer":recorded}))
        }
    });
    worker.declare_workflow_signals(TYPE, &["ready", "finish", "settle"])?;
    let registration = worker.register().await?;
    require(
        registration.registered && registration.worker_id == *id,
        "worker registration was not acknowledged",
    )?;
    println!(
        "{}",
        json!({"registered":true,"pid":std::process::id(),"metrics":worker.sticky_cache_metrics()?})
    );
    std::io::stdout().flush()?;
    for line in std::io::stdin().lock().lines() {
        let command: Value = serde_json::from_str(&line?)?;
        let drained = command["action"] == "drained";
        let stop = command["action"] == "stop" || drained;
        let processed = if drained {
            tokio::time::timeout(
                Duration::from_secs(10),
                worker.run_until(std::future::pending()),
            )
            .await??;
            0
        } else if stop {
            worker.run_until(async {}).await?;
            0
        } else {
            require(command["action"] == "poll", "unknown worker fixture action")?;
            worker.run_once().await?
        };
        println!(
            "{}",
            json!({"pid":std::process::id(),"processed":processed,
            "side_effect_calls":effects.load(Ordering::SeqCst),"callbacks":*seen.lock().unwrap(),
            "metrics":worker.sticky_cache_metrics()?})
        );
        std::io::stdout().flush()?;
        if stop {
            break;
        }
    }
    Ok(())
}

struct Process {
    child: Child,
    input: ChildStdin,
    output: BufReader<ChildStdout>,
    initial: Value,
}

impl Process {
    fn start(queue: &str, worker: &str, build: &str, producer: &str) -> Result<Self> {
        let mut child = Command::new(env::current_exe()?)
            .args(["--worker", queue, worker, build, producer])
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit())
            .spawn()?;
        let input = child.stdin.take().ok_or("worker stdin missing")?;
        let output = BufReader::new(child.stdout.take().ok_or("worker stdout missing")?);
        let mut process = Self {
            child,
            input,
            output,
            initial: Value::Null,
        };
        process.initial = process.read()?;
        require(
            process.initial["registered"] == true
                && process.initial["pid"].as_u64() == Some(process.child.id() as u64),
            "worker did not register in its actual process",
        )?;
        require(
            process.initial["metrics"]["entries"] == 0 && process.initial["metrics"]["hit"] == 0,
            "replacement cache must start empty",
        )?;
        Ok(process)
    }

    fn read(&mut self) -> Result<Value> {
        let mut line = String::new();
        require(
            self.output.read_line(&mut line)? > 0,
            "worker exited before its response",
        )?;
        Ok(serde_json::from_str(&line)?)
    }

    fn request(&mut self, action: &str) -> Result<Value> {
        writeln!(self.input, "{}", json!({"action":action}))?;
        self.input.flush()?;
        self.read()
    }

    fn stop(&mut self) -> Result<Value> {
        let response = self.request("stop")?;
        require(
            self.child.wait()?.success(),
            "worker did not shut down normally",
        )?;
        Ok(response)
    }

    fn kill(&mut self) -> Result<Value> {
        use std::os::unix::process::ExitStatusExt;
        let pid = self.child.id();
        self.child.kill()?;
        let status = self.child.wait()?;
        require(
            status.signal() == Some(9),
            "worker did not die from SIGKILL",
        )?;
        Ok(json!({"pid":pid,"signal":status.signal()}))
    }

    fn drain(&mut self) -> Result<Value> {
        use std::os::unix::process::ExitStatusExt;
        let response = self.request("drained")?;
        let status = self.child.wait()?;
        require(
            status.success() && status.signal().is_none(),
            "drained managed worker did not exit normally",
        )?;
        Ok(
            json!({"pid":self.child.id(),"exit_code":status.code(),"signal":status.signal(),"response":response}),
        )
    }
}

impl Drop for Process {
    fn drop(&mut self) {
        if self.child.try_wait().ok().flatten().is_none() {
            let _ = self.child.kill();
            let _ = self.child.wait();
        }
    }
}

struct Api {
    http: reqwest::Client,
    base: String,
    namespace: String,
    token: String,
}

impl Api {
    fn new() -> Result<Self> {
        Ok(Self {
            http: reqwest::Client::builder()
                .timeout(Duration::from_secs(10))
                .build()?,
            base: env::var("DW_WV_SERVER_URL")?.trim_end_matches('/').into(),
            namespace: env::var("DW_WV_NAMESPACE")?,
            token: env::var("DW_WV_AUTH_TOKEN").unwrap_or_else(|_| "dev-token".into()),
        })
    }
    async fn request(
        &self,
        method: reqwest::Method,
        path: &str,
        body: Option<Value>,
    ) -> Result<Value> {
        let mut request = self
            .http
            .request(method.clone(), format!("{}{path}", self.base))
            .bearer_auth(&self.token)
            .header("X-Namespace", &self.namespace)
            .header("X-Durable-Workflow-Control-Plane-Version", "2");
        if let Some(body) = body {
            request = request.json(&body);
        }
        let response = request.send().await?;
        let status = response.status();
        let value: Value = response.json().await?;
        require(
            status.is_success(),
            &format!("{method} {path} returned {status}: {value}"),
        )?;
        Ok(value)
    }
    async fn get(&self, path: &str) -> Result<Value> {
        self.request(reqwest::Method::GET, path, None).await
    }
    async fn promote(&self, queue: &str, build: &str) -> Result<Value> {
        self.control(queue, build, "promote").await
    }
    async fn control(&self, queue: &str, build: &str, action: &str) -> Result<Value> {
        self.request(
            reqwest::Method::POST,
            &format!("/api/task-queues/{queue}/build-ids/{action}"),
            Some(json!({"build_id":build})),
        )
        .await
    }
    async fn show(&self, handle: &WorkflowHandle) -> Result<Value> {
        let value = self
            .get(&format!(
                "/api/workflows/{}/runs/{}",
                handle.workflow_id,
                handle.run_id.as_deref().ok_or("missing SDK run ID")?
            ))
            .await?;
        require(
            value["run_id"].as_str() == handle.run_id.as_deref(),
            "API replaced the original run identity",
        )?;
        Ok(value)
    }
    async fn history(&self, handle: &WorkflowHandle) -> Result<Value> {
        self.get(&format!(
            "/api/workflows/{}/runs/{}/history",
            handle.workflow_id,
            handle.run_id.as_deref().ok_or("missing SDK run ID")?
        ))
        .await
    }
    async fn debug(&self, handle: &WorkflowHandle) -> Result<Value> {
        self.get(&format!(
            "/api/workflows/{}/runs/{}/debug",
            handle.workflow_id,
            handle.run_id.as_deref().ok_or("missing SDK run ID")?
        ))
        .await
    }
}

async fn drive(
    api: &Api,
    process: &mut Process,
    handle: &WorkflowHandle,
    status: &str,
    boundary: &str,
) -> Result<Value> {
    let deadline = Instant::now() + Duration::from_secs(30);
    loop {
        let observation = process.request("poll")?;
        let description = api.show(handle).await?;
        let delivered = observation["processed"].as_u64().unwrap_or(0) > 0
            && observation["callbacks"]
                .as_array()
                .and_then(|values| values.last())
                .is_some_and(|value| {
                    value["workflow_id"] == handle.workflow_id
                        && value["run_id"].as_str() == handle.run_id.as_deref()
                        && value["boundary"] == boundary
                });
        if delivered && description["status"] == status {
            return Ok(observation);
        }
        require(
            Instant::now() < deadline,
            &format!(
                "workflow {} did not reach {status}: {description}",
                handle.workflow_id
            ),
        )?;
        tokio::time::sleep(Duration::from_millis(100)).await;
    }
}

fn events(history: &Value) -> Result<&Vec<Value>> {
    history["events"]
        .as_array()
        .ok_or_else(|| "history response lacks events".into())
}

fn event_count(history: &Value, kind: &str) -> Result<usize> {
    Ok(events(history)?
        .iter()
        .filter(|event| event["event_type"] == kind)
        .count())
}

fn identity(handle: &WorkflowHandle) -> Value {
    json!({"workflow_id":handle.workflow_id,"run_id":handle.run_id})
}

fn no_compatible(value: &Value) -> bool {
    value["compatibility_status"] == "no_compatible_worker"
}

fn cohort<'a>(rollout: &'a Value, build: &str) -> Result<&'a Value> {
    rollout["build_ids"]
        .as_array()
        .and_then(|rows| rows.iter().find(|row| row["build_id"] == build))
        .ok_or_else(|| "build cohort is absent from rollout visibility".into())
}

async fn qualify_drain_resume(client: &Client, api: &Api) -> Result<Value> {
    let suffix = durable_workflow::Uuid::new_v4().simple().to_string();
    let queue = format!("rust-drain-{suffix}");
    let v1_build = format!("drain-v1-{suffix}");
    let v2_build = format!("drain-v2-{suffix}");
    let v1_id = format!("drain-worker-v1-{suffix}");
    let v2_id = format!("drain-worker-v2-{suffix}");
    let mut v1 = Process::start(&queue, &v1_id, &v1_build, "original-drain-v1")?;
    let mut v2 = Process::start(&queue, &v2_id, &v2_build, "incompatible-v2")?;
    let initial = v1.initial.clone();
    let workers = api.get(&format!("/api/workers?task_queue={queue}")).await?;
    api.promote(&queue, &v1_build).await?;
    let old = client
        .start_workflow(TYPE, &queue, &format!("rust-drained-{suffix}"), json!([]))
        .await?;
    let first = drive(api, &mut v1, &old, "waiting", "ready").await?;
    require(
        first["side_effect_calls"] == 1,
        "drain run never executed its side-effect producer",
    )?;
    let promotion = api.promote(&queue, &v2_build).await?;
    old.signal("ready", json!([])).await?;
    let before = api
        .get(&format!("/api/task-queues/{queue}/build-ids"))
        .await?;
    require(
        cohort(&before, &v1_build)?["pending_workflow_tasks"]["ready_count"]
            .as_u64()
            .unwrap_or(0)
            > 0,
        "drain exercise has no queued runnable task",
    )?;
    let drain = api.control(&queue, &v1_build, "drain").await?;
    let repeat_started = Instant::now();
    tokio::time::sleep(Duration::from_millis(1100)).await;
    let duplicate_drain = api.control(&queue, &v1_build, "drain").await?;
    let repeated_after_ms = repeat_started.elapsed().as_millis();
    require(
        drain["drain_intent"] == "draining"
            && !drain["drained_at"].is_null()
            && duplicate_drain["drained_at"] == drain["drained_at"],
        "drain lost its original identity/timestamp",
    )?;
    let mut drained_polls = Vec::new();
    let mut incompatible_polls = Vec::new();
    for _ in 0..2 {
        let poll = v1.request("poll")?;
        require(
            poll["processed"] == 0
                && poll["callbacks"] == first["callbacks"]
                && poll["side_effect_calls"] == 1,
            "drained worker executed queued work",
        )?;
        drained_polls.push(poll);
        let wrong = v2.request("poll")?;
        require(
            wrong["processed"] == 0,
            "promoted incompatible worker stole drained work",
        )?;
        incompatible_polls.push(wrong);
    }
    let blocked_rollout = api
        .get(&format!("/api/task-queues/{queue}/build-ids"))
        .await?;
    let blocked_entry = cohort(&blocked_rollout, &v1_build)?;
    require(
        blocked_entry["drain_intent"] == "draining"
            && blocked_entry["active_worker_count"] == 0
            && blocked_entry["draining_worker_count"] == 1
            && blocked_entry["pending_workflow_tasks"]["ready_count"]
                .as_u64()
                .unwrap_or(0)
                > 0
            && blocked_entry["pending_workflow_tasks"]["leased_count"] == 0,
        "drained cohort/backlog is not visible or a worker leased its task",
    )?;
    let blocked_show = api.show(&old).await?;
    let blocked_debug = api.debug(&old).await?;
    let blocked_history = api.history(&old).await?;
    require(
        blocked_show["compatibility"] == v1_build
            && blocked_show["status"] == "waiting"
            && event_count(&blocked_history, "SignalReceived")? == 1
            && event_count(&blocked_history, "SignalApplied")? == 0
            && event_count(&blocked_history, "SideEffectRecorded")? == 1
            && event_count(&blocked_history, "WorkflowCompleted")? == 0,
        "drain changed the run or consumed/duplicated its recorded work",
    )?;
    let shutdown = v1.drain()?;
    require(
        shutdown["response"]["side_effect_calls"] == 1
            && shutdown["response"]["callbacks"] == first["callbacks"],
        "managed shutdown executed the queued task",
    )?;
    let absent_rollout = api
        .get(&format!("/api/task-queues/{queue}/build-ids"))
        .await?;
    require(
        cohort(&absent_rollout, &v1_build)?["active_worker_count"] == 0,
        "drained worker remained active after its normal exit",
    )?;
    let resume = api.control(&queue, &v1_build, "resume").await?;
    let duplicate_resume = api.control(&queue, &v1_build, "resume").await?;
    require(
        resume["drain_intent"] == "active"
            && duplicate_resume["drain_intent"] == "active"
            && resume["drained_at"].is_null(),
        "resume did not restore the cohort",
    )?;
    let resumed_rollout = api
        .get(&format!("/api/task-queues/{queue}/build-ids"))
        .await?;
    let resumed_debug = api.debug(&old).await?;
    require(
        cohort(&resumed_rollout, &v1_build)?["drain_intent"] == "active"
            && cohort(&resumed_rollout, &v1_build)?["active_worker_count"] == 0,
        "resume hid worker absence or left the build drained",
    )?;
    let wrong = v2.request("poll")?;
    require(
        wrong["processed"] == 0,
        "resume routed the original run to promoted v2",
    )?;
    incompatible_polls.push(wrong);
    let mut replacement =
        Process::start(&queue, &v1_id, &v1_build, "resumed-producer-must-not-run")?;
    let replacement_initial = replacement.initial.clone();
    require(
        replacement_initial["pid"] != shutdown["pid"],
        "resume did not start a new worker process",
    )?;
    let restored_workers = api.get(&format!("/api/workers?task_queue={queue}")).await?;
    let resumed = drive(api, &mut replacement, &old, "waiting", "finish").await?;
    let recovered_debug = api.debug(&old).await?;
    require(
        resumed["side_effect_calls"] == 0,
        "resumed worker repeated the recorded producer",
    )?;
    for signal in ["finish", "settle"] {
        old.signal(signal, json!([])).await?;
    }
    let completed = drive(api, &mut replacement, &old, "completed", "completed").await?;
    let result = old.result(WorkflowResultOptions::default()).await?;
    let history = api.history(&old).await?;
    let final_show = api.show(&old).await?;
    let completed_debug = api.debug(&old).await?;
    require(
        result == json!({"producer":"original-drain-v1"})
            && completed["side_effect_calls"] == 0
            && final_show["compatibility"] == v1_build
            && event_count(&history, "SideEffectRecorded")? == 1
            && event_count(&history, "WorkflowCompleted")? == 1,
        "resumed result/build or completion history changed",
    )?;
    replacement.stop()?;
    v2.stop()?;
    Ok(
        json!({"original":identity(&old),"v1_build":v1_build,"v2_build":v2_build,
        "v1_worker_id":v1_id,"v2_worker_id":v2_id,"workers":workers,"initial":initial,"first":first,
        "promotion":promotion,"before":before,"drain":drain,"duplicate_drain":duplicate_drain,"repeated_after_ms":repeated_after_ms,
        "drained_polls":drained_polls,"incompatible_polls":incompatible_polls,
        "blocked_rollout":blocked_rollout,"blocked_show":blocked_show,"blocked_history":blocked_history,"blocked_debug":blocked_debug,
        "shutdown":shutdown,"absent_rollout":absent_rollout,"resume":resume,"duplicate_resume":duplicate_resume,
        "resumed_rollout":resumed_rollout,"resumed_debug":resumed_debug,"recovered_debug":recovered_debug,"completed_debug":completed_debug,
        "replacement":replacement_initial,"restored_workers":restored_workers,
        "resumed":resumed,"completed":completed,"result":result,"history":history,"show":final_show}),
    )
}

async fn qualify() -> Result<Value> {
    let client = client()?;
    let api = Api::new()?;
    let drain_resume = qualify_drain_resume(&client, &api).await?;
    let suffix = durable_workflow::Uuid::new_v4().simple().to_string();
    let queue = format!("rust-build-{suffix}");
    let v1_build = format!("rust-v1-{suffix}");
    let v2_build = format!("rust-v2-{suffix}");
    let v1_id = format!("rust-worker-v1-{suffix}");
    let v2_id = format!("rust-worker-v2-{suffix}");
    let mut v1 = Process::start(&queue, &v1_id, &v1_build, "original-v1")?;
    let mut v2 = Process::start(&queue, &v2_id, &v2_build, "original-v2")?;
    let workers = api.get(&format!("/api/workers?task_queue={queue}")).await?;
    let rows = workers["workers"]
        .as_array()
        .or_else(|| workers.as_array())
        .ok_or("worker list has no rows")?;
    for (id, build) in [(&v1_id, &v1_build), (&v2_id, &v2_build)] {
        let sdk_identity = format!("durable-workflow-rust/{}", env::var("DW_RUST_SDK_VERSION")?);
        require(
            rows.iter().any(|row| {
                row["worker_id"] == *id
                    && row["build_id"] == *build
                    && row["runtime"] == "rust"
                    && row["sdk_version"] == sdk_identity
            }),
            "actual Rust worker/build/SDK identity missing from API",
        )?;
    }
    api.promote(&queue, &v1_build).await?;
    let old = client
        .start_workflow(TYPE, &queue, &format!("rust-old-{suffix}"), json!([]))
        .await?;
    require(
        api.show(&old).await?["compatibility"] == v1_build,
        "old run was not pinned at start",
    )?;
    let wrong_old = v2.request("poll")?;
    require(
        wrong_old["processed"] == 0,
        "v2 executed a v1-pinned workflow",
    )?;
    let first = drive(&api, &mut v1, &old, "waiting", "ready").await?;
    require(
        first["side_effect_calls"] == 1,
        "v1 did not execute the actual workflow callback",
    )?;

    let evictor = client
        .start_workflow(TYPE, &queue, &format!("rust-evictor-{suffix}"), json!([]))
        .await?;
    let eviction = drive(&api, &mut v1, &evictor, "waiting", "ready").await?;
    require(
        eviction["metrics"]["eviction"].as_u64().unwrap_or(0) >= 1,
        "capacity-one cache did not evict the original history",
    )?;
    for signal in ["ready", "finish", "settle"] {
        evictor.signal(signal, json!([])).await?;
    }
    drive(&api, &mut v1, &evictor, "completed", "completed").await?;
    old.signal("ready", json!([])).await?;
    require(
        v2.request("poll")?["processed"] == 0,
        "v2 executed old run after cache eviction",
    )?;
    let replay = drive(&api, &mut v1, &old, "waiting", "finish").await?;
    require(
        replay["side_effect_calls"] == 2
            && replay["metrics"]["forced_cold_replay"]
                .as_u64()
                .unwrap_or(0)
                > 0,
        "cache eviction repeated an effect or did not prove cold replay",
    )?;

    let promotion = api.promote(&queue, &v2_build).await?;
    let new = client
        .start_workflow(TYPE, &queue, &format!("rust-new-{suffix}"), json!([]))
        .await?;
    require(
        api.show(&new).await?["compatibility"] == v2_build,
        "new run was not pinned to promoted v2",
    )?;
    require(
        api.show(&old).await?["compatibility"] == v1_build,
        "promotion changed the old run pin",
    )?;
    let wrong_new = v1.request("poll")?;
    require(wrong_new["processed"] == 0, "v1 executed promoted v2 work")?;
    drive(&api, &mut v2, &new, "waiting", "ready").await?;
    for signal in ["ready", "finish", "settle"] {
        new.signal(signal, json!([])).await?;
    }
    let new_completed = drive(&api, &mut v2, &new, "completed", "completed").await?;
    require(
        new_completed["side_effect_calls"] == 1,
        "v2 completion repeated its side effect",
    )?;
    require(
        new.result(WorkflowResultOptions::default()).await? == json!({"producer":"original-v2"}),
        "new run result does not belong to v2",
    )?;
    require(
        event_count(&api.history(&new).await?, "WorkflowCompleted")? == 1,
        "new run did not complete exactly once",
    )?;

    v1.stop()?;
    old.signal("finish", json!([])).await?;
    let deadline = Instant::now() + Duration::from_secs(60);
    let mut polls = Vec::new();
    let (blocked, blocked_builds) = loop {
        let poll = v2.request("poll")?;
        require(
            poll["processed"] == 0,
            "v2 stole work with no compatible worker",
        )?;
        polls.push(poll);
        let show = api.show(&old).await?;
        let builds = api
            .get(&format!("/api/task-queues/{queue}/build-ids"))
            .await?;
        if no_compatible(&show) {
            break (show, builds);
        }
        require(
            Instant::now() < deadline,
            &format!("public surfaces did not diagnose missing v1: {show}; {builds}"),
        )?;
        tokio::time::sleep(Duration::from_millis(200)).await;
    };
    require(
        blocked["compatibility"] == v1_build,
        "no-compatible diagnosis changed the original pin",
    )?;
    let mut resumed = Process::start(&queue, &v1_id, &v1_build, "replacement-must-not-produce")?;
    let resumed_initial = resumed.initial.clone();
    let resumed_poll = drive(&api, &mut resumed, &old, "waiting", "settle").await?;
    require(
        resumed_poll["side_effect_calls"] == 0 && resumed_poll["metrics"]["entries"] == 1,
        "cold successor repeated an effect or did not reach its waiting cache boundary",
    )?;
    let killed = resumed.kill()?;
    old.signal("settle", json!([])).await?;
    require(
        v2.request("poll")?["processed"] == 0,
        "v2 stole old work after SIGKILL",
    )?;
    let mut replacement = Process::start(
        &queue,
        &v1_id,
        &v1_build,
        "second-replacement-must-not-produce",
    )?;
    let replacement_initial = replacement.initial.clone();
    require(
        replacement_initial["pid"] != killed["pid"],
        "replacement reused the killed process",
    )?;
    let completed = drive(&api, &mut replacement, &old, "completed", "completed").await?;
    let result = old.result(WorkflowResultOptions::default()).await?;
    require(result == json!({"producer":"original-v1"}) && completed["side_effect_calls"] == 0 && completed["metrics"]["entries"] == 0, "cold completion lost the recorded result, repeated its producer or retained a terminal entry")?;
    let history = api.history(&old).await?;
    require(
        event_count(&history, "SideEffectRecorded")? == 1
            && event_count(&history, "WorkflowCompleted")? == 1,
        "original history repeats a side effect or terminal completion",
    )?;
    let final_show = api.show(&old).await?;
    require(
        final_show["compatibility"] == v1_build,
        "cold replay changed the original build pin",
    )?;
    replacement.stop()?;
    v2.stop()?;

    Ok(
        json!({"schema":"durable-workflow.conformance.rust-worker-versioning","version":1,"outcome":"pass",
        "sdk_version":env::var("DW_RUST_SDK_VERSION")?,"worker_execution":"managed_rust_sdk_workers",
        "local_product_source_checkouts_used":false,"namespace":api.namespace,"task_queue":queue,
        "cells":{
            "drain_resume":drain_resume,
            "registration_build_ids":{"workers":workers,"v1_build":v1_build,"v2_build":v2_build,"v1_worker_id":v1_id,"v2_worker_id":v2_id},
            "pinned_delivery_and_promotion":{"old":identity(&old),"new":identity(&new),"v1_build":v1_build,"v2_build":v2_build,"wrong_old_poll":wrong_old,"wrong_new_poll":wrong_new,"first_v1_poll":first,"new_completed":new_completed,"promotion":promotion},
            "cache_eviction_replay":{"original":identity(&old),"evictor":identity(&evictor),"eviction":eviction,"cold_replay":replay},
            "no_compatible_worker":{"original":identity(&old),"show":blocked,"builds":blocked_builds,"incompatible_polls":polls},
            "sigkill_cold_replay":{"original":identity(&old),"first_replacement":resumed_initial,"resumed":resumed_poll,"killed":killed,"replacement":replacement_initial,"completed":completed,"result":result,"history":history,"show":final_show}
        }}),
    )
}

#[tokio::main]
async fn main() -> Result<()> {
    let args: Vec<String> = env::args().collect();
    if args.get(1).map(String::as_str) == Some("--start") {
        let queue = args.get(2).ok_or("task queue is required")?;
        let workflow_id = args.get(3).ok_or("workflow ID is required")?;
        let handle = client()?
            .start_workflow(TYPE, queue, workflow_id, json!([workflow_id]))
            .await?;
        println!("{}", identity(&handle));
        return Ok(());
    }
    if args.get(1).map(String::as_str) == Some("--worker") {
        return worker_process(&args).await;
    }
    let report = args.get(1).ok_or("result path is required")?;
    match qualify().await {
        Ok(value) => {
            fs::write(report, serde_json::to_vec_pretty(&value)?)?;
            Ok(())
        }
        Err(error) => {
            fs::write(
                report,
                serde_json::to_vec_pretty(
                    &json!({"schema":"durable-workflow.conformance.rust-worker-versioning","version":1,"outcome":"fail","error":error.to_string()}),
                )?,
            )?;
            Err(error)
        }
    }
}
