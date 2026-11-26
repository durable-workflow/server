//! Bind a read-only observation and its exact claim to one authenticated poll.
use super::{
    QueryTaskConfig, Result, refuse,
    task_delivery_receipts::{Acceptance, OfferClaim, OfferScope, OpenedOffer},
    task_offer_key_usage::{IssuanceWindow, OfferWindow},
    task_offer_seal::{
        KeyRing, OpenedToken, PACKET_SCHEMA, PacketContext, SealError, TaskKind, authority_digest,
    },
    transaction::ClaimTarget,
    worker_sessions::incarnation_digest,
    workflow_poll_requests::{normalized, request_id},
};
use axum::http::StatusCode;
use serde_json::Value;
use sha2::{Digest, Sha256};

pub(super) const OFFER_LIFETIME_MS: i64 = 2000;
pub(super) const DELIVERY_PROTOCOL: &str = "offer-accept.v1";
pub(super) const DELIVERY_SCHEMA: &str = "durable-workflow.task-delivery.v1";
pub(super) const DELIVERY_HEADER: &str = "x-durable-workflow-task-delivery";

pub(super) struct IncomingOffer<'a> {
    pub(super) scope: OfferScope<'a>,
    pub(super) body: &'a Value,
    pub(super) protocol: &'a str,
    pub(super) page: Option<i64>,
    pub(super) timeout: u64,
    pub(super) packet: Option<&'a str>,
}

pub(super) enum Delivery {
    Offer(Option<IssuedOffer>),
    Acceptance(Acceptance),
}

pub(super) fn descriptor() -> Value {
    serde_json::json!({
        "schema": DELIVERY_SCHEMA, "supported": true, "minimum_protocol_version": "1.20",
        "worker_capability": "task_delivery_offers", "header": "X-Durable-Workflow-Task-Delivery",
        "version": DELIVERY_PROTOCOL, "endpoint_suffix": "/offers",
        "acceptance_timeout_seconds": OFFER_LIFETIME_MS / 1000,
        "unaccepted_offer_reserves_task": false
    })
}

fn lost_worker_authority() -> super::RuntimeError {
    refuse(StatusCode::CONFLICT, "worker_registration_lost_authority")
}

pub(super) fn unavailable() -> super::RuntimeError {
    refuse(
        StatusCode::SERVICE_UNAVAILABLE,
        "task_offer_issuance_unavailable",
    )
}

pub(super) struct IssuedOffer {
    pub(super) token: String,
    pub(super) target: ClaimTarget,
    pub(super) expires_at_ms: i64,
}

/// A packet sealed under a key this replica lacks while storage still
/// requires that key. Retryable and not definitive: the claim may exist and
/// a replica holding the key can still replay it.
pub(super) fn read_key_unavailable() -> super::RuntimeError {
    refuse(
        StatusCode::SERVICE_UNAVAILABLE,
        "task_offer_read_key_unavailable",
    )
}

fn seal_error(error: SealError) -> super::RuntimeError {
    match error {
        SealError::Invalid | SealError::UnknownKey => {
            refuse(StatusCode::CONFLICT, "invalid_task_offer_token")
        }
        SealError::Nonce => refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "task_offer_nonce_unavailable",
        ),
    }
}

fn scope_mismatch() -> super::RuntimeError {
    refuse(StatusCode::CONFLICT, "task_offer_scope_mismatch")
}

impl OfferClaim<'_> {
    fn validate_offer_scope(&self) -> Result<(&str, [u8; 32])> {
        let scope = self.scope;
        if super::text(self.body, "worker_id")? != scope.worker_id
            || super::text(self.body, "task_queue")? != scope.queue
            || request_id(self.body).as_deref() != Some(scope.request_id)
        {
            return Err(scope_mismatch());
        }
        let token = super::text(self.body, "registration_token")?;
        if !self.fence.owns_offer_registration(token) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "worker_registration_lost_authority",
            ));
        }
        let route = authority_digest(
            "poll_route",
            &(
                self.fence.offer_routing_digest()?,
                self.protocol,
                self.history_page,
                super::laravel_rules::normalize(&self.body["build_id"]),
                super::laravel_rules::normalize(&self.body["accept_history_encoding"]),
            ),
        )?;
        Ok((token, route))
    }

    fn requested_kinds(&self) -> Result<Vec<TaskKind>> {
        normalized(self.scope.kinds)
            .into_iter()
            .map(TaskKind::from_wire)
            .collect()
    }
}

fn authenticate_packet(
    opened: OpenedToken,
    scope: &OfferScope<'_>,
    namespace: &str,
    registration: &str,
) -> Result<OpenedToken> {
    let context = &opened.context;
    let registration_digest: [u8; 32] = Sha256::digest(registration.as_bytes()).into();
    let requested_kinds = normalized(scope.kinds)
        .into_iter()
        .map(TaskKind::from_wire)
        .collect::<Result<Vec<_>>>()?;
    if context.namespace != namespace
        || context.worker_id != scope.worker_id
        || context.queue != scope.queue
        || context.endpoint != TaskKind::from_wire(scope.endpoint)?
        || context.request_id != scope.request_id
        || context.requested_kinds != requested_kinds
        || context.registration_digest != registration_digest
    {
        return Err(scope_mismatch());
    }
    Ok(opened)
}

store_methods! {
    /// Open a packet. A frame naming a key this replica does not hold is
    /// refused as retryable while storage still requires that read key.
    async fn open_offer_packet(&self, keys: &KeyRing, token: &str) -> Result<OpenedToken> {
        match keys.open(token) {
            Err(SealError::UnknownKey) => {
                let required = match KeyRing::frame_key_id(token) {
                    Some(id) => self.offer_read_key_required(id).await?,
                    None => false,
                };
                Err(if required { read_key_unavailable() } else { seal_error(SealError::Invalid) })
            }
            opened => opened.map_err(seal_error),
        }
    }

    pub(super) async fn dispatch_task_offer(
        &self, request: &IncomingOffer<'_>, keys: &KeyRing, windows: &OfferWindow, config: &QueryTaskConfig,
    ) -> Result<Delivery> {
        use super::{poll_capacity::Pool, worker_poll_fence::PollAdmission};
        let scope = &request.scope;
        let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(request.timeout);
        let probe = self.poll_capacity.probe().await;
        let fence = match self.admit_worker_poll(scope.worker_id, scope.queue).await? {
            PollAdmission::Fenced(fence) => fence,
            _ => {
                if let Some(packet) = request.packet {
                    let opened = self.open_offer_packet(keys, packet).await?;
                    authenticate_packet(opened, scope, self.namespace.as_ref(), super::text(request.body, "registration_token")?)?;
                }
                return Err(lost_worker_authority());
            }
        };
        drop(probe);
        let claim = OfferClaim { scope, fence: &fence, body: request.body, protocol: request.protocol,
            history_page: request.page, query_config: config };
        if let Some(packet) = request.packet {
            let _probe = self.poll_capacity.probe().await;
            return self.accept_sealed_task_offer(&claim, keys, packet).await.map(Delivery::Acceptance);
        }

        let (pool, kind) = match scope.endpoint {
            "query" => (Pool::QueryTask, "query_task"),
            "activity" => (Pool::Worker, "activity_task"),
            "update_validation" => (Pool::Worker, "update_validation_task"),
            _ => (Pool::Worker, "workflow_task"),
        };
        let mut slot = None;
        let mut pace = super::poll_readiness::ReadPace::new();
        loop {
            let probe = self.poll_capacity.probe().await;
            let pressure = self.storage_admission.snapshot();
            if !pressure.accepts_new_work() {
                return Err(super::RuntimeError::Protocol {
                    status: StatusCode::SERVICE_UNAVAILABLE,
                    response: super::storage_admission::refusal(&pressure, super::storage_admission::Admission::Poll, Some(scope.request_id)),
                });
            }
            let wake = super::poll_wakes::any(self.wake.notified(), self.poll_wakes.others.notified());
            let window = windows.current().ok_or_else(unavailable)?;
            match self.issue_task_offer(&claim, keys, &window).await {
                Ok(Some(offer)) => return Ok(Delivery::Offer(Some(offer))),
                Err(error) if super::worker_poll_fence::is_stale_claim(&error) => return Err(lost_worker_authority()),
                Err(error) => return Err(error),
                Ok(None) => {}
            }
            if tokio::time::Instant::now() >= deadline {
                return Ok(Delivery::Offer(None));
            }
            self.poll_capacity.hold(&mut slot, pool, &self.namespace, scope.queue, kind)?;
            drop(probe);
            pace.wait(wake, deadline, &self.poll_wakes).await;
        }
    }


    pub(super) async fn issue_task_offer(
        &self, claim: &OfferClaim<'_>, keys: &KeyRing, window: &IssuanceWindow,
    ) -> Result<Option<IssuedOffer>> {
        let (registration, routing_digest) = claim.validate_offer_scope()?;
        let scope = claim.scope;
        let endpoint = TaskKind::from_wire(scope.endpoint)?;
        let mut order = normalized(scope.kinds);
        if order.len() == 2 {
            let first = self.peek_task_kind_cursor(scope.queue).await?;
            order.sort_by_key(|kind| *kind != first);
        }
        for kind in order {
            let target = match kind {
                "query" => self.peek_query_offer(scope.worker_id, scope.queue, claim.query_config, claim.fence).await?,
                "update_validation" => self.peek_validation_offer(scope.worker_id, scope.queue, claim.fence).await?,
                _ => self.peek_execution_offer(scope.worker_id, scope.queue, kind, claim.protocol, claim.fence).await?,
            };
            let Some(target) = target else { continue; };
            let at = self.task_offer_read_time(keys).await?;
            let expires_at_ms = at.timestamp_millis() + OFFER_LIFETIME_MS;
            if !window.permits(keys, at, expires_at_ms) {
                return Err(refuse(StatusCode::SERVICE_UNAVAILABLE, "task_offer_issuance_unavailable"));
            }
            let context = PacketContext {
                schema: PACKET_SCHEMA.to_owned(),
                namespace: self.namespace.to_string(),
                worker_id: scope.worker_id.to_owned(),
                queue: scope.queue.to_owned(),
                endpoint,
                request_id: scope.request_id.to_owned(),
                requested_kinds: claim.requested_kinds()?,
                registration_digest: Sha256::digest(registration.as_bytes()).into(),
                routing_digest,
                eligibility_digest: target.eligibility_digest.ok_or_else(scope_mismatch)?,
                task_id: target.task_id.clone(),
                task_kind: TaskKind::from_wire(target.kind)?,
                observed_attempt: target.observed_attempt.ok_or_else(scope_mismatch)?,
                expires_at_ms,
            };
            let token = keys.seal(&context).map_err(seal_error)?;
            return Ok(Some(IssuedOffer { token, target, expires_at_ms }));
        }
        Ok(None)
    }

    pub(super) async fn accept_sealed_task_offer(
        &self, claim: &OfferClaim<'_>, keys: &KeyRing, token: &str,
    ) -> Result<Acceptance> {
        // Establish the original signed scope before a stale registration can
        // produce a definitive refusal for that logical poll.
        let registration = super::text(claim.body, "registration_token")?;
        let opened = self.open_offer_packet(keys, token).await?;
        let opened = authenticate_packet(opened, claim.scope, self.namespace.as_ref(), registration)?;
        let context = opened.context;
        let (_, routing_digest) = claim.validate_offer_scope()?;
        if context.routing_digest != routing_digest {
            return Err(scope_mismatch());
        }
        let offer = OpenedOffer {
            target: ClaimTarget { task_id: context.task_id, kind: context.task_kind.as_wire(),
                observed_attempt: Some(context.observed_attempt), eligibility_digest: Some(context.eligibility_digest) },
            registration_digest: incarnation_digest(registration),
            key_id: opened.key_id, packet_digest: opened.packet_digest, expires_at_ms: context.expires_at_ms,
        };
        self.accept_task_offer(&offer, claim).await
    }
}
