//! Bind a read-only observation and its exact claim to one authenticated poll.
use super::{
    Result, refuse,
    task_delivery_receipts::{Acceptance, OfferClaim, OpenedOffer},
    task_offer_key_usage::IssuanceWindow,
    task_offer_seal::{
        KeyRing, PACKET_SCHEMA, PacketContext, SealError, TaskKind, authority_digest,
    },
    transaction::ClaimTarget,
    worker_sessions::incarnation_digest,
    workflow_poll_requests::{normalized, request_id},
};
use axum::http::StatusCode;
use serde_json::Value;
use sha2::{Digest, Sha256};

pub(super) const OFFER_LIFETIME_MS: i64 = 2000;

pub(super) struct IssuedOffer {
    pub(super) token: String,
    pub(super) target: ClaimTarget,
    pub(super) expires_at_ms: i64,
}

fn seal_error(error: SealError) -> super::RuntimeError {
    match error {
        SealError::Invalid => refuse(StatusCode::CONFLICT, "invalid_task_offer_token"),
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

store_methods! {
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
        let (registration, routing_digest) = claim.validate_offer_scope()?;
        let opened = keys.open(token).map_err(seal_error)?;
        let context = opened.context;
        let scope = claim.scope;
        let registration_digest: [u8; 32] = Sha256::digest(registration.as_bytes()).into();
        if context.namespace != self.namespace.as_ref() || context.worker_id != scope.worker_id
            || context.queue != scope.queue || context.endpoint != TaskKind::from_wire(scope.endpoint)?
            || context.request_id != scope.request_id || context.requested_kinds != claim.requested_kinds()?
            || context.registration_digest != registration_digest || context.routing_digest != routing_digest
        {
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
