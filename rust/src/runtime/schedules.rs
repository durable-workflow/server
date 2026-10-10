//! Bounded UTC interval schedule definitions; broader policies remain explicit.
use super::{Result, envelope, refuse, store::reject_fields, text};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::Value;

pub(super) struct Definition {
    pub(super) schedule_id: String,
    pub(super) spec: Value,
    pub(super) action: Value,
    pub(super) max_runs: Option<i64>,
    pub(super) next_fire: DateTime<Utc>,
}

pub(super) fn period(spec: &Value) -> Result<i64> {
    reject_fields(spec, &["intervals", "timezone"])?;
    if spec["timezone"] != "UTC"
        || spec["intervals"]
            .as_array()
            .is_none_or(|items| items.len() != 1)
    {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_schedule_spec",
        ));
    }
    let interval = &spec["intervals"][0];
    reject_fields(interval, &["every"])?;
    match interval["every"].as_str() {
        Some("P1D") => Ok(86400),
        Some("PT2S") => Ok(2),
        _ => Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_schedule_interval",
        )),
    }
}

pub(super) fn next(spec: &Value, after: DateTime<Utc>) -> Result<DateTime<Utc>> {
    let seconds = period(spec)?;
    // The installed UTC interval calculator anchors at midnight. These two
    // periods divide a UTC day, so the epoch boundary has the same phase.
    let timestamp = after
        .timestamp()
        .div_euclid(seconds)
        .checked_add(1)
        .and_then(|tick| tick.checked_mul(seconds))
        .and_then(|seconds| DateTime::from_timestamp(seconds, 0))
        .ok_or_else(|| {
            refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "schedule_timestamp_overflow",
            )
        })?;
    Ok(timestamp)
}

pub(super) fn definition(body: &Value, at: DateTime<Utc>) -> Result<Definition> {
    reject_fields(
        body,
        &[
            "schedule_id",
            "spec",
            "action",
            "overlap_policy",
            "jitter_seconds",
            "max_runs",
        ],
    )?;
    let schedule_id = text(body, "schedule_id")?;
    if schedule_id.len() > 128
        || !schedule_id
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"._:-".contains(&byte))
    {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_schedule_id",
        ));
    }
    if body["overlap_policy"] != "skip" || body["jitter_seconds"].as_i64() != Some(0) {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_schedule_policy",
        ));
    }
    let max_runs = match body.get("max_runs").filter(|value| !value.is_null()) {
        None => None,
        Some(value) if value.as_i64() == Some(1) => Some(1),
        Some(_) => {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "unsupported_schedule_quota",
            ));
        }
    };
    let action = &body["action"];
    reject_fields(
        action,
        &[
            "workflow_type",
            "task_queue",
            "input",
            "execution_timeout_seconds",
            "run_timeout_seconds",
        ],
    )?;
    text(action, "workflow_type")?;
    text(action, "task_queue")?;
    envelope(action, "input")?;
    for (field, expected) in [
        ("execution_timeout_seconds", 3600),
        ("run_timeout_seconds", 600),
    ] {
        if action[field].as_i64() != Some(expected) {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "unsupported_schedule_action_timeout",
            ));
        }
    }
    Ok(Definition {
        schedule_id: schedule_id.to_owned(),
        spec: body["spec"].clone(),
        action: action.clone(),
        max_runs,
        next_fire: next(&body["spec"], at)?,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn utc_interval_preserves_phase_and_never_returns_the_current_boundary() {
        for every in ["PT2S", "P1D"] {
            let spec = json!({"intervals":[{"every":every}],"timezone":"UTC"});
            let period = period(&spec).unwrap();
            for instant in [
                "2026-10-10T00:00:00Z",
                "2026-10-10T00:00:00.000001Z",
                "2026-10-10T23:59:59.999999Z",
            ] {
                let now = DateTime::parse_from_rfc3339(instant)
                    .unwrap()
                    .with_timezone(&Utc);
                let next = next(&spec, now).unwrap();
                assert!(next > now);
                assert_eq!(next.timestamp().rem_euclid(period), 0);
                assert!(next - now <= chrono::Duration::seconds(period));
            }
        }
    }

    #[test]
    fn unqualified_calendar_offset_and_timezone_rules_are_refused() {
        for spec in [
            json!({"intervals":[{"every":"P1M"}],"timezone":"UTC"}),
            json!({"intervals":[{"every":"PT2S","offset":"PT1S"}],"timezone":"UTC"}),
            json!({"intervals":[{"every":"PT2S"}],"timezone":"Europe/Budapest"}),
            json!({"cron_expressions":["* * * * *"],"timezone":"UTC"}),
        ] {
            assert!(period(&spec).is_err());
        }
    }
}
