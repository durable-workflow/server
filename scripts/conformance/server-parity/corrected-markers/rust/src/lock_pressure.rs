//! The published PHP SQLite reference can refuse a worker operation with its
//! documented HTTP 503 `backend_lock_pressure` reply and `retryable: true`
//! after its transaction failed, so nothing was committed. Only that refusal is
//! retried, within 15 retries and 30 seconds of waiting, as the PHP probes'
//! transport does (`../lock-pressure-retry.php`). Every other outcome is
//! returned unchanged.
use durable_workflow::{Error, Result};
use serde_json::Value;
use std::{future::Future, time::Duration};

const MAX_RETRIES: u32 = 15;
const MAX_WAIT: Duration = Duration::from_secs(30);

fn delay_for(status: u16, body: &str, retries: u32, waited: Duration) -> Option<Duration> {
    if status != 503 || retries >= MAX_RETRIES {
        return None;
    }
    let body: Value = serde_json::from_str(body).ok()?;
    if body.get("reason")?.as_str()? != "backend_lock_pressure"
        || body.get("retryable") != Some(&Value::Bool(true))
    {
        return None;
    }
    let seconds = match body.get("retry_after_seconds") {
        None => 1.0,
        Some(value) => value.as_f64()?,
    };
    let delay = Duration::from_secs_f64(seconds.clamp(0.1, 5.0));
    (waited + delay <= MAX_WAIT).then_some(delay)
}

/// Runs `call`, repeating it only after the documented lock-pressure refusal.
pub async fn retrying<T, F, Fut>(what: &str, mut call: F) -> Result<T>
where
    F: FnMut() -> Fut,
    Fut: Future<Output = Result<T>>,
{
    let (mut retries, mut waited) = (0, Duration::ZERO);
    loop {
        let error = match call().await {
            Err(error) => error,
            done => return done,
        };
        let delay = match &error {
            Error::Http { status, body } => delay_for(status.as_u16(), body, retries, waited),
            _ => None,
        };
        let Some(delay) = delay else {
            return Err(error);
        };
        eprintln!("{what}: retrying backend_lock_pressure refusal after {delay:?}");
        tokio::time::sleep(delay).await;
        waited += delay;
        retries += 1;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const REFUSAL: &str = r#"{"reason":"backend_lock_pressure","operation":"destroy","outcome":"deferred","retryable":true,"retry_after_seconds":1}"#;

    #[test]
    fn documented_refusal_waits_for_the_advertised_interval() {
        assert_eq!(delay_for(503, REFUSAL, 0, Duration::ZERO), Some(Duration::from_secs(1)));
        let long = REFUSAL.replace(r#""retry_after_seconds":1"#, r#""retry_after_seconds":60"#);
        assert_eq!(delay_for(503, &long, 0, Duration::ZERO), Some(Duration::from_secs(5)));
        let absent = REFUSAL.replace(r#","retry_after_seconds":1"#, "");
        assert_eq!(delay_for(503, &absent, 0, Duration::ZERO), Some(Duration::from_secs(1)));
    }

    #[test]
    fn other_statuses_reasons_and_bodies_are_returned() {
        for (status, body) in [
            (409, REFUSAL.to_owned()),
            (500, REFUSAL.to_owned()),
            (503, REFUSAL.replace("backend_lock_pressure", "backend_unavailable")),
            (503, REFUSAL.replace(r#""retryable":true"#, r#""retryable":false"#)),
            (503, REFUSAL.replace(r#","retryable":true"#, "")),
            (503, REFUSAL.replace(r#""retry_after_seconds":1"#, r#""retry_after_seconds":"soon""#)),
            (503, "not json".to_owned()),
        ] {
            assert_eq!(delay_for(status, &body, 0, Duration::ZERO), None, "{status} {body}");
        }
    }

    #[test]
    fn retries_stop_at_the_attempt_and_wait_budgets() {
        assert_eq!(delay_for(503, REFUSAL, 15, Duration::ZERO), None);
        assert_eq!(delay_for(503, REFUSAL, 2, Duration::from_millis(29_500)), None);
        assert_eq!(delay_for(503, REFUSAL, 14, Duration::from_secs(29)), Some(Duration::from_secs(1)));
    }
}
