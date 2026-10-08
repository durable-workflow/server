use durable_workflow::{json, Value, WorkflowContext};
use std::sync::{atomic::{AtomicUsize, Ordering}, Arc, Mutex};

pub async fn execute(ctx: WorkflowContext, effects: Arc<AtomicUsize>, seen: Arc<Mutex<Vec<Value>>>) -> durable_workflow::Result<Value> {
    let identity = ctx.workflow_identity()?;
    let recorded = ctx.side_effect(|| {
        effects.fetch_add(1, Ordering::SeqCst);
        "divergent-definition"
    })?;
    let observe = |boundary| {
        seen.lock().unwrap().push(json!({"workflow_id":identity.workflow_id,"run_id":identity.run_id,"recorded":recorded,"boundary":boundary}));
    };
    observe("ready");
    ctx.wait_signal("settle").await?;
    observe("finish");
    ctx.wait_signal("finish").await?;
    observe("completed");
    Ok(json!({"producer":recorded}))
}
