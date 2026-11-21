//! Durable offer acceptance under the ordinary database transition lock.
use super::{
    Result, refuse,
    store::now,
    transaction::{ClaimTarget, Transaction},
    worker_poll_fence::WorkerPollFence,
    workflow_poll_requests::{normalized, request_id},
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

const PREFIX: &str = "dw-native-offer-receipt:";
const RECEIPT_VERSION: u8 = 1;
const REVIEW_AFTER_SECONDS: i64 = 3600;
const SWEEP_BATCH: i64 = 16;

pub(super) struct OfferScope<'a> {
    pub(super) worker_id: &'a str,
    pub(super) queue: &'a str,
    pub(super) endpoint: &'static str,
    pub(super) request_id: &'a str,
    pub(super) kinds: &'a [&'static str],
}

/// Data from a successfully authenticated offer envelope. The HTTP boundary
/// also has to match the sealed routing and eligibility context.
#[derive(Clone)]
pub(super) struct OpenedOffer {
    pub(super) target: ClaimTarget,
    pub(super) registration_digest: String,
    pub(super) key_id: String,
    pub(super) packet_digest: [u8; 32],
    pub(super) expires_at_ms: i64,
}

pub(super) enum Acceptance {
    Claimed(Value),
    Replayed(Value),
    ConflictingOffer,
    Expired,
    LeaseEnded,
    Unclaimable,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct Receipt {
    version: u8,
    binding: [u8; 32],
    packet_digest: [u8; 32],
    key_id: String,
    namespace: String,
    queue: String,
    worker_id: String,
    kind: String,
    task_id: String,
    attempt: i64,
    task: Value,
}

impl Receipt {
    fn decode(text: &str) -> Result<Self> {
        let invalid = || {
            refuse(
                StatusCode::INTERNAL_SERVER_ERROR,
                "invalid_task_offer_receipt",
            )
        };
        let value: Self = serde_json::from_str(text).map_err(|_| invalid())?;
        if value.version != RECEIPT_VERSION {
            return Err(invalid());
        }
        Ok(value)
    }
}

fn identity(
    namespace: &str,
    scope: &OfferScope<'_>,
    registration_digest: &str,
) -> (String, [u8; 32]) {
    // The original request is the serialization boundary. A competing offer,
    // registration or kind set cannot select a different row.
    let poll = json!([
        namespace,
        scope.worker_id,
        scope.queue,
        scope.endpoint,
        scope.request_id
    ]);
    let key: String = Sha256::digest(poll.to_string().as_bytes())[..16]
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect();
    let binding = json!([poll, registration_digest, normalized(scope.kinds)]);
    (
        format!("{PREFIX}{key}"),
        Sha256::digest(binding.to_string().as_bytes()).into(),
    )
}

store_methods! {
    async fn execution_receipt_lease(
        tx: &mut Transaction<'_, DB>, receipt: &Receipt, at: DateTime<Utc>,
    ) -> Result<Option<DateTime<Utc>>> {
        let Some(row) = Self::query("SELECT lease_expires_at FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type=$3 AND id=$4 AND lease_owner=$5 AND attempt_count=$6 AND status='leased' AND lease_expires_at>$7")
            .bind(&receipt.namespace).bind(&receipt.queue).bind(&receipt.kind).bind(&receipt.task_id)
            .bind(&receipt.worker_id).bind(receipt.attempt).bind(DB::bind_time(at))
            .fetch_optional(&mut **tx).await? else { return Ok(None); };
        let lease = DB::instant(&row, "lease_expires_at")?;
        if receipt.kind == "activity" {
            let Some(attempt_id) = receipt.task["activity_attempt_id"].as_str() else {
                return Err(refuse(StatusCode::INTERNAL_SERVER_ERROR, "invalid_task_offer_receipt"));
            };
            let attempt = Self::query("SELECT a.lease_expires_at FROM activity_attempts a JOIN activity_executions e ON e.id=a.activity_execution_id WHERE a.id=$1 AND a.workflow_task_id=$2 AND a.attempt_number=$3 AND a.lease_owner=$4 AND a.status='running' AND a.lease_expires_at>$5 AND e.status='running' AND e.current_attempt_id=a.id")
                .bind(attempt_id).bind(&receipt.task_id).bind(receipt.attempt).bind(&receipt.worker_id)
                .bind(DB::bind_time(at)).fetch_optional(&mut **tx).await?;
            return attempt.map(|row| DB::instant(&row, "lease_expires_at").map(|expires| expires.min(lease))).transpose();
        }
        Ok(Some(lease))
    }

    /// Review a bounded page of old receipts while holding the transition.
    /// An expiration timestamp schedules a review; it cannot evict a live lease.
    async fn sweep_execution_offer_receipts(tx: &mut Transaction<'_, DB>, at: DateTime<Utc>) -> Result<()> {
        let rows = Self::query(DB::OFFER_RECEIPT_REVIEW)
            .bind(at.timestamp()).bind(SWEEP_BATCH).fetch_all(&mut **tx).await?;
        for row in rows {
            let key = DB::string(&row, "key")?;
            let raw = DB::string(&row, "value")?;
            let receipt = Receipt::decode(&raw)?;
            if Self::execution_receipt_lease(tx, &receipt, at).await?.is_some() {
                Self::query(DB::QUERY_CACHE_UPDATE).bind(raw).bind(at.timestamp() + REVIEW_AFTER_SECONDS)
                    .bind(key).execute(&mut **tx).await?;
            } else {
                Self::query(DB::QUERY_CACHE_DELETE).bind(key).execute(&mut **tx).await?;
            }
        }
        Ok(())
    }

    #[allow(clippy::too_many_arguments)]
    pub(super) async fn accept_execution_offer(
        &self, scope: &OfferScope<'_>, offer: &OpenedOffer, fence: &WorkerPollFence, body: &Value,
        protocol: &str, history_page: Option<i64>,
    ) -> Result<Acceptance> {
        let mut tx = self.begin().await?;
        let accepted = self.accept_execution_offer_in(&mut tx, scope, offer, fence, body, protocol, history_page, now()).await?;
        if matches!(accepted, Acceptance::Claimed(_) | Acceptance::Replayed(_)) {
            tx.commit().await?;
        } else {
            tx.rollback().await?;
        }
        Ok(accepted)
    }

    #[allow(clippy::too_many_arguments)]
    async fn accept_execution_offer_in(
        &self, tx: &mut Transaction<'_, DB>, scope: &OfferScope<'_>, offer: &OpenedOffer,
        fence: &WorkerPollFence, body: &Value, protocol: &str, history_page: Option<i64>, at: DateTime<Utc>,
    ) -> Result<Acceptance> {
        let kind = offer.target.kind;
        if !matches!(kind, "workflow" | "activity") || scope.endpoint != kind || !scope.kinds.contains(&kind)
            || super::text(body, "worker_id")? != scope.worker_id
            || super::text(body, "task_queue")? != scope.queue
            || request_id(body).as_deref() != Some(scope.request_id)
        {
            return Err(refuse(StatusCode::CONFLICT, "task_offer_scope_mismatch"));
        }
        let attempt = offer.target.observed_attempt.and_then(|n| n.checked_add(1))
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "task_offer_scope_mismatch"))?;
        let lost = || refuse(StatusCode::CONFLICT, "worker_registration_lost_authority");
        let worker = self.fenced_claim_registration(tx, fence, scope.worker_id).await?.ok_or_else(lost)?;
        let token = DB::optional_string(&worker, "incarnation_token")?.ok_or_else(lost)?;
        if DB::string(&worker, "task_queue")? != scope.queue
            || super::worker_sessions::incarnation_digest(&token) != offer.registration_digest
        {
            return Err(lost());
        }

        let (key, binding) = identity(self.namespace.as_ref(), scope, &offer.registration_digest);
        if let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(&key).fetch_optional(&mut **tx).await? {
            let receipt = Receipt::decode(&DB::string(&row, "value")?)?;
            if receipt.binding != binding || receipt.packet_digest != offer.packet_digest
                || receipt.key_id != offer.key_id || receipt.kind != kind
                || receipt.task_id != offer.target.task_id || receipt.attempt != attempt
            {
                return Ok(Acceptance::ConflictingOffer);
            }
            let Some(expires) = Self::execution_receipt_lease(tx, &receipt, at).await? else {
                return Ok(Acceptance::LeaseEnded);
            };
            let mut task = receipt.task;
            task["lease_expires_at"] = json!(super::cooperative::timestamp(expires));
            return Ok(Acceptance::Replayed(task));
        }
        if offer.expires_at_ms <= at.timestamp_millis() {
            // This also distinguishes an offer whose accepted receipt aged
            // out from an offer that never advanced the task's attempt.
            let task = Self::query("SELECT attempt_count FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type=$3 AND id=$4")
                .bind(self.namespace.as_ref()).bind(scope.queue).bind(kind).bind(&offer.target.task_id)
                .fetch_optional(&mut **tx).await?;
            if let Some(task) = task
                && Some(DB::number(&task, "attempt_count")?) == offer.target.observed_attempt
            {
                return Ok(Acceptance::Expired);
            }
            return Ok(Acceptance::Unclaimable);
        }

        // Offer acceptance owns its receipt. Do not read or create legacy poll
        // request bindings for the same string ID.
        let mut claim = body.clone();
        if let Some(fields) = claim.as_object_mut() {
            fields.remove("poll_request_id");
        }
        tx.claim_only = Some(offer.target.clone());
        let task = self.claim_with_registration(tx, &claim, kind, protocol, history_page, None, Some(worker)).await?;
        tx.claim_only = None;
        let Some(task) = task else { return Ok(Acceptance::Unclaimable); };
        let receipt = Receipt {
            version: RECEIPT_VERSION, binding, packet_digest: offer.packet_digest,
            key_id: offer.key_id.clone(), namespace: self.namespace.to_string(), queue: scope.queue.to_owned(),
            worker_id: scope.worker_id.to_owned(), kind: kind.to_owned(), task_id: offer.target.task_id.clone(),
            attempt, task: task.clone(),
        };
        Self::sweep_execution_offer_receipts(tx, at).await?;
        Self::query(DB::QUERY_CACHE_INSERT).bind(key).bind(serde_json::to_string(&receipt)?)
            .bind(at.timestamp() + REVIEW_AFTER_SECONDS).execute(&mut **tx).await?;
        Ok(Acceptance::Claimed(task))
    }
}

#[cfg(test)]
store_methods! {
    pub(super) async fn qualify_execution_offer_receipts(&self) {
        use super::{QueryTaskConfig, store::Store, worker_poll_fence::PollAdmission};
        use crate::codec::{Value as Payload, ValueCodec};
        use std::sync::Arc;
        use tokio::sync::Semaphore;

        let input = json!({"codec":"avro", "blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
        for kind in ["workflow", "activity"] {
            let namespace = format!("receipt-proof-{kind}");
            let store = self.in_namespace(&namespace);
            let registration = store.register(json!({"worker_id":"worker","task_queue":"received","runtime":"php",
                "supported_workflow_types":["receipt"],"supported_activity_types":["receipt"]}),
                &QueryTaskConfig::default(), Some("1.20")).await.unwrap();
            for name in ["first", "second"] {
                let workflow_id = format!("offer-receipt-{kind}-{name}");
                store.start(json!({"workflow_id":workflow_id,"workflow_type":"receipt","task_queue":"received","input":input}),
                    Default::default(), None, None).await.unwrap();
                if kind == "activity" {
                    let answer = store.poll(json!({"worker_id":"worker","task_queue":"received","timeout_seconds":0}), "workflow", "1.20").await.unwrap();
                    let task = &answer["task"];
                    store.complete_or_checkpoint(task["task_id"].as_str().unwrap(), json!({"lease_owner":"worker",
                        "workflow_task_attempt":task["workflow_task_attempt"],
                        "commands":[{"type":"schedule_activity","activity_type":"receipt","arguments":input}]}),
                        Arc::new(Semaphore::new(1)), Default::default(), None,
                        super::carried_polls::Config { protocol: Some("1.20"), pages: Default::default(), default_wait: 30 },
                        Default::default(), None, None).await.unwrap();
                }
            }
            let PollAdmission::Fenced(fence) = store.admit_worker_poll("worker", "received").await.unwrap()
            else { panic!("the registered receipt worker must be fenced"); };
            let kinds = [kind];
            let scope = OfferScope { worker_id: "worker", queue: "received", endpoint: kind, request_id: "original", kinds: &kinds };
            let body = json!({"worker_id":"worker","task_queue":"received","poll_request_id":"original","timeout_seconds":0});
            let target = store.peek_execution_offer("worker", "received", kind, "1.20", &fence).await.unwrap().unwrap();
            let task_id = target.task_id.clone();
            let at = now();
            let offer = OpenedOffer {
                target, registration_digest: super::worker_sessions::incarnation_digest(registration["registration_token"].as_str().unwrap()),
                key_id: "fixture-key".to_owned(), packet_digest: [7; 32], expires_at_ms: at.timestamp_millis() + 10000,
            };
            let (key, _) = identity(&namespace, &scope, &offer.registration_digest);
            let mut tx = store.begin().await.unwrap();
            assert!(matches!(store.accept_execution_offer_in(&mut tx, &scope, &offer, &fence, &body, "1.20", None,
                at + chrono::Duration::seconds(11)).await.unwrap(), Acceptance::Expired));
            tx.rollback().await.unwrap();
            let mut tx = store.begin().await.unwrap();
            assert!(matches!(store.accept_execution_offer_in(&mut tx, &scope, &offer, &fence, &body, "1.20", None, at)
                .await.unwrap(), Acceptance::Claimed(_)));
            tx.rollback().await.unwrap();
            assert!(Self::query(DB::QUERY_CACHE_GET).bind(&key).fetch_optional(&store.pool).await.unwrap().is_none());
            let before = Self::query("SELECT status,attempt_count FROM workflow_tasks WHERE id=$1")
                .bind(&task_id).fetch_one(&store.pool).await.unwrap();
            assert_eq!(DB::string(&before, "status").unwrap(), "ready");
            assert_eq!(DB::number(&before, "attempt_count").unwrap(), 0);

            // Independent Store queues still serialize on the backend lock.
            let replica = Store::<DB>::new(store.pool.clone()).in_namespace(&namespace);
            let (a,b) = tokio::join!(
                store.accept_execution_offer(&scope, &offer, &fence, &body, "1.20", None),
                replica.accept_execution_offer(&scope, &offer, &fence, &body, "1.20", None),
            );
            let (claimed, replayed) = match (a.unwrap(), b.unwrap()) {
                (Acceptance::Claimed(task), Acceptance::Replayed(replayed)) |
                (Acceptance::Replayed(replayed), Acceptance::Claimed(task)) => (task, replayed),
                _ => panic!("one attempt commits and the concurrent acceptance replays it"),
            };
            assert_eq!(claimed["task_id"], task_id.as_str());
            assert_eq!(replayed["task_id"], task_id.as_str());
            let attempts = Self::query("SELECT attempt_count FROM workflow_tasks WHERE id=$1")
                .bind(&task_id).fetch_one(&store.pool).await.unwrap();
            assert_eq!(DB::number(&attempts, "attempt_count").unwrap(), 1);
            if kind == "activity" {
                let started = Self::scalar("SELECT COUNT(*) FROM workflow_history_events WHERE workflow_task_id=$1 AND event_type='ActivityStarted'")
                    .bind(&task_id).fetch_one(&store.pool).await.unwrap();
                assert_eq!(started, 1);
            }

            let other = Self::query("SELECT id FROM workflow_tasks WHERE namespace=$1 AND task_type=$2 AND id<>$3 AND status='ready'")
                .bind(&namespace).bind(kind).bind(&task_id).fetch_one(&store.pool).await.unwrap();
            let other_id = DB::string(&other, "id").unwrap();
            let mut competing = offer.clone();
            competing.target.task_id = other_id.clone();
            competing.packet_digest = [8; 32];
            assert!(matches!(replica.accept_execution_offer(&scope, &competing, &fence, &body, "1.20", None).await.unwrap(),
                Acceptance::ConflictingOffer));

            let renewed = at + chrono::Duration::hours(2);
            Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
                .bind(DB::bind_time(renewed)).bind(&task_id).execute(&store.pool).await.unwrap();
            if kind == "activity" {
                Self::query("UPDATE activity_attempts SET lease_expires_at=$1 WHERE id=$2")
                    .bind(DB::bind_time(renewed)).bind(claimed["activity_attempt_id"].as_str().unwrap())
                    .execute(&store.pool).await.unwrap();
            }
            let later = at + chrono::Duration::seconds(REVIEW_AFTER_SECONDS + 1);
            let mut tx = replica.begin().await.unwrap();
            Self::sweep_execution_offer_receipts(&mut tx, later).await.unwrap();
            let Acceptance::Replayed(replay) = replica.accept_execution_offer_in(&mut tx, &scope, &offer, &fence, &body,
                "1.20", None, later).await.unwrap() else { panic!("a renewed lease keeps its receipt"); };
            assert_eq!(replay["task_id"], task_id.as_str());
            let expires = DateTime::parse_from_rfc3339(replay["lease_expires_at"].as_str().unwrap()).unwrap().timestamp();
            assert_eq!(expires, renewed.timestamp());
            let row = Self::query(DB::QUERY_CACHE_GET).bind(&key).fetch_one(&mut *tx).await.unwrap();
            let receipt = Receipt::decode(&DB::string(&row, "value").unwrap()).unwrap();
            assert_eq!(receipt.key_id, offer.key_id);
            assert_eq!(receipt.attempt, 1);
            assert!(DB::number(&row, "expiration").unwrap() > later.timestamp());
            tx.commit().await.unwrap();

            let mut tx = store.begin().await.unwrap();
            let after_lease = renewed + chrono::Duration::seconds(2);
            assert!(matches!(store.accept_execution_offer_in(&mut tx, &scope, &offer, &fence, &body, "1.20", None, after_lease)
                .await.unwrap(), Acceptance::LeaseEnded));
            Self::sweep_execution_offer_receipts(&mut tx, after_lease).await.unwrap();
            assert!(Self::query(DB::QUERY_CACHE_GET).bind(&key).fetch_optional(&mut *tx).await.unwrap().is_none());
            assert!(matches!(store.accept_execution_offer_in(&mut tx, &scope, &offer, &fence, &body, "1.20", None, after_lease)
                .await.unwrap(), Acceptance::Unclaimable));
            tx.commit().await.unwrap();
            let other = Self::query("SELECT status,attempt_count FROM workflow_tasks WHERE id=$1")
                .bind(&other_id).fetch_one(&store.pool).await.unwrap();
            assert_eq!(DB::string(&other, "status").unwrap(), "ready");
            assert_eq!(DB::number(&other, "attempt_count").unwrap(), 0);
        }
    }
}
