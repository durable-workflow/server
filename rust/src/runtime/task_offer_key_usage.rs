//! Durable read-key obligations, serialized with task acceptance and receipt review.
use super::{Result, refuse, store::now, task_offer_seal::KeyRing, transaction::Transaction};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use sqlx::Executor;
use std::{
    sync::{Arc, RwLock},
    time::{Duration, Instant},
};

const PREFIX: &str = "dw-native-offer-key:";
const VERSION: u8 = 1;
const ISSUANCE_WINDOW: Duration = Duration::from_secs(30);
const MAX_DATABASE_CLOCK_OFFSET_MS: u64 = 500;
pub(super) const MAX_OFFER_CLOCK_SKEW_MS: i64 = 1000;
const MAX_OFFER_LIFETIME_MS: i64 = 10_000;

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct KeyUse {
    version: u8,
    id: String,
    fingerprint: [u8; 32],
    read_until_ms: i64,
    receipts: u64,
}

impl KeyUse {
    fn decode(raw: &str, cache_key: &str) -> Result<Self> {
        let state: Self = serde_json::from_str(raw).map_err(|_| invalid_key_usage())?;
        if state.version != VERSION || cache_key != key_name(&state.id) || state.read_until_ms < 0 {
            return Err(invalid_key_usage());
        }
        Ok(state)
    }

    fn expiration(&self) -> i64 {
        if self.receipts != 0 {
            i64::MAX
        } else {
            self.read_until_ms.div_euclid(1000) + 1
        }
    }

    fn required(&self, at: DateTime<Utc>) -> bool {
        self.receipts != 0 || self.read_until_ms >= at.timestamp_millis()
    }
}

fn key_name(id: &str) -> String {
    format!("{PREFIX}{id}")
}

fn invalid_key_usage() -> super::RuntimeError {
    refuse(
        StatusCode::SERVICE_UNAVAILABLE,
        "task_offer_key_usage_unavailable",
    )
}

/// Whether a startup preflight refusal only reports an absent required read
/// key. Startup continues without issuance; any other refusal stays fatal.
pub(super) fn is_missing_read_key(error: &super::RuntimeError) -> bool {
    matches!(
        error,
        super::RuntimeError::Refused {
            reason: "task_offer_required_read_key_missing",
            ..
        }
    )
}

fn missing_key() -> super::RuntimeError {
    refuse(
        StatusCode::SERVICE_UNAVAILABLE,
        "task_offer_required_read_key_missing",
    )
}

fn changed_key() -> super::RuntimeError {
    refuse(
        StatusCode::SERVICE_UNAVAILABLE,
        "task_offer_key_identity_changed",
    )
}

fn unsafe_clock() -> super::RuntimeError {
    refuse(
        StatusCode::SERVICE_UNAVAILABLE,
        "task_offer_clock_unqualified",
    )
}

/// A committed horizon precedes every offer this authority can issue. The
/// monotonic deadline prevents a clock step from extending a replica's window.
pub(super) struct IssuanceWindow {
    key_id: String,
    fingerprint: [u8; 32],
    stop_at_ms: i64,
    monotonic_deadline: Instant,
}

#[derive(Default)]
pub(super) struct OfferWindow(RwLock<Option<Arc<IssuanceWindow>>>);

impl OfferWindow {
    pub(super) fn current(&self) -> Option<Arc<IssuanceWindow>> {
        self.0
            .read()
            .unwrap()
            .as_ref()
            .filter(|window| window.is_open(now()))
            .cloned()
    }

    pub(super) fn replace(&self, window: Option<IssuanceWindow>) {
        *self.0.write().unwrap() = window.map(Arc::new);
    }
}

impl IssuanceWindow {
    fn is_open(&self, at: DateTime<Utc>) -> bool {
        Instant::now() < self.monotonic_deadline && at.timestamp_millis() < self.stop_at_ms
    }

    pub(super) fn permits(&self, ring: &KeyRing, at: DateTime<Utc>, expires_at_ms: i64) -> bool {
        let (id, fingerprint) = ring.issuer_identity();
        let time = at.timestamp_millis();
        id == self.key_id
            && fingerprint == self.fingerprint
            && self.is_open(at)
            && expires_at_ms > time
            && expires_at_ms.saturating_sub(time) <= MAX_OFFER_LIFETIME_MS
    }
}

store_methods! {
    pub(super) async fn database_utc_now<'e, E>(executor: E) -> Result<DateTime<Utc>>
    where E: Executor<'e, Database = DB>,
    {
        let row = Self::query(DB::DATABASE_UTC_CLOCK).fetch_one(executor).await?;
        DB::instant(&row, "database_time")
    }

    pub(super) async fn checked_offer_clock<'e, E>(executor: E) -> Result<DateTime<Utc>>
    where E: Executor<'e, Database = DB>,
    {
        let wall_before = now().timestamp_millis();
        let started = Instant::now();
        let at = Self::database_utc_now(executor).await?;
        let wall_after = now().timestamp_millis();
        let database = at.timestamp_millis();
        if started.elapsed() > Duration::from_millis(MAX_DATABASE_CLOCK_OFFSET_MS)
            || database.abs_diff(wall_before) > MAX_DATABASE_CLOCK_OFFSET_MS
            || database.abs_diff(wall_after) > MAX_DATABASE_CLOCK_OFFSET_MS
        {
            return Err(unsafe_clock());
        }
        Ok(at)
    }

    async fn offer_key_in(tx: &mut Transaction<'_, DB>, id: &str) -> Result<Option<KeyUse>> {
        let name = key_name(id);
        let row = Self::query(DB::QUERY_CACHE_GET).bind(&name).fetch_optional(&mut **tx).await?;
        row.map(|row| KeyUse::decode(&DB::string(&row, "value")?, &name)).transpose()
    }

    async fn save_offer_key(tx: &mut Transaction<'_, DB>, key: &KeyUse) -> Result<()> {
        Self::query(DB::QUERY_CACHE_PUT).bind(key_name(&key.id)).bind(serde_json::to_string(key)?)
            .bind(key.expiration()).execute(&mut **tx).await?;
        Ok(())
    }

    async fn check_offer_key_ring_in(
        tx: &mut Transaction<'_, DB>, ring: Option<&KeyRing>, at: DateTime<Utc>,
    ) -> Result<()> {
        // A reused key ID is checked first, so a missing read key cannot
        // hide it from the startup preflight.
        if let Some(ring) = ring {
            for id in ring.key_ids() {
                if let Some(existing) = Self::offer_key_in(tx, id).await?
                    && ring.key_fingerprint(id) != Some(existing.fingerprint)
                {
                    return Err(changed_key());
                }
            }
        }
        let required = Self::query(DB::OFFER_REQUIRED_KEYS)
            .bind(at.timestamp()).fetch_all(&mut **tx).await?;
        // Five indexed rows cannot be satisfied by a four-slot ring.
        // Refuse even if some are within the timestamp rounding margin.
        if required.len() > 4 {
            return Err(missing_key());
        }
        for row in required {
            let name = DB::string(&row, "key")?;
            let key = KeyUse::decode(&DB::string(&row, "value")?, &name)?;
            if key.required(at) {
                let fingerprint = ring.and_then(|ring| ring.key_fingerprint(&key.id)).ok_or_else(missing_key)?;
                if fingerprint != key.fingerprint {
                    return Err(changed_key());
                }
            }
        }
        Ok(())
    }

    /// Startup checks all durable obligations before activating a configured
    /// issuer. A missing required read key is reported as
    /// `task_offer_required_read_key_missing`; the runtime then starts without
    /// an issuance window and refuses only packets that need the absent key.
    /// A key ID reused for different material remains a startup refusal.
    pub(super) async fn preflight_task_offer_keys(&self, ring: Option<&KeyRing>) -> Result<()> {
        let mut tx = self.begin().await?;
        let at = Self::database_utc_now(&mut *tx).await?;
        Self::check_offer_key_ring_in(&mut tx, ring, at).await?;
        tx.rollback().await?;
        Ok(())
    }

    /// Whether storage still requires the read key with this ID: a committed
    /// issuance horizon has not elapsed or a retained receipt references it.
    pub(super) async fn offer_read_key_required(&self, id: &str) -> Result<bool> {
        let name = key_name(id);
        let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(&name).fetch_optional(&self.pool).await? else {
            return Ok(false);
        };
        let key = KeyUse::decode(&DB::string(&row, "value")?, &name)?;
        let at = Self::database_utc_now(&self.pool).await?;
        Ok(key.required(at))
    }

    pub(super) async fn task_offer_read_time(&self, ring: &KeyRing) -> Result<DateTime<Utc>> {
        let mut read = self.begin_repeatable_read().await?;
        let at = Self::checked_offer_clock(&mut *read).await?;
        Self::check_offer_key_ring_in(&mut read, Some(ring), at).await?;
        read.commit().await?;
        Ok(at)
    }

    pub(super) async fn reserve_task_offer_issuance(&self, ring: &KeyRing) -> Result<IssuanceWindow> {
        let mut tx = self.begin().await?;
        let started = Instant::now();
        let at = Self::checked_offer_clock(&mut *tx).await?;
        let window = Self::reserve_task_offer_issuance_in(&mut tx, ring, at, started).await?;
        tx.commit().await?;
        Ok(window)
    }

    async fn reserve_task_offer_issuance_in(
        tx: &mut Transaction<'_, DB>, ring: &KeyRing, at: DateTime<Utc>, started: Instant,
    ) -> Result<IssuanceWindow> {
        Self::check_offer_key_ring_in(tx, Some(ring), at).await?;
        let (id, fingerprint) = ring.issuer_identity();
        let stop_at_ms = at.timestamp_millis() + ISSUANCE_WINDOW.as_millis() as i64;
        let read_until_ms = stop_at_ms + MAX_OFFER_LIFETIME_MS + MAX_OFFER_CLOCK_SKEW_MS;
        let mut key = Self::offer_key_in(tx, id).await?.unwrap_or(KeyUse {
            version: VERSION, id: id.to_owned(), fingerprint, read_until_ms: 0, receipts: 0,
        });
        key.read_until_ms = key.read_until_ms.max(read_until_ms);
        Self::save_offer_key(tx, &key).await?;
        Ok(IssuanceWindow {
            key_id: id.to_owned(), fingerprint, stop_at_ms, monotonic_deadline: started + ISSUANCE_WINDOW,
        })
    }

    pub(super) async fn retain_offer_receipt_key(tx: &mut Transaction<'_, DB>, id: &str) -> Result<()> {
        let mut key = Self::offer_key_in(tx, id).await?.ok_or_else(invalid_key_usage)?;
        key.receipts = key.receipts.checked_add(1).ok_or_else(invalid_key_usage)?;
        Self::save_offer_key(tx, &key).await
    }

    pub(super) async fn release_offer_receipt_key(tx: &mut Transaction<'_, DB>, id: &str) -> Result<()> {
        let mut key = Self::offer_key_in(tx, id).await?.ok_or_else(invalid_key_usage)?;
        key.receipts = key.receipts.checked_sub(1).ok_or_else(invalid_key_usage)?;
        Self::save_offer_key(tx, &key).await
    }
}

#[cfg(test)]
pub(super) fn fixture_ring(keys: &[(&str, u8)]) -> KeyRing {
    use base64::{Engine, engine::general_purpose::STANDARD};
    let keys: Vec<_> = keys
        .iter()
        .map(|(id, byte)| {
            serde_json::json!({
                "id": id, "key_base64": STANDARD.encode([*byte; 32])
            })
        })
        .collect();
    KeyRing::parse(
        &serde_json::json!({
            "schema": "durable-workflow.task-delivery-seal-keys.v1", "keys": keys
        })
        .to_string(),
    )
    .unwrap()
}

#[cfg(test)]
store_methods! {
    pub(super) async fn reserve_fixture_offer_key(&self) {
        self.reserve_task_offer_issuance(&fixture_ring(&[("fixture-key", 7)]))
            .await.unwrap();
    }

    pub(super) async fn qualify_task_offer_key_usage(&self) {
        use super::store::Store;
        let old = fixture_ring(&[("first", 1)]);
        let new = fixture_ring(&[("second", 2), ("first", 1)]);
        let trimmed = fixture_ring(&[("second", 2)]);
        let replaced = fixture_ring(&[("second", 2), ("first", 3)]);
        self.preflight_task_offer_keys(None).await.unwrap();
        let replica = Store::<DB>::new(self.pool.clone());
        let at = Self::database_utc_now(&self.pool).await.unwrap();
        let mut tx = self.begin().await.unwrap();
        let window = Self::reserve_task_offer_issuance_in(&mut tx, &old, at, Instant::now()).await.unwrap();
        tx.commit().await.unwrap();
        assert!(window.permits(&old, at, at.timestamp_millis() + MAX_OFFER_LIFETIME_MS));
        assert!(!window.permits(&new, at, at.timestamp_millis() + MAX_OFFER_LIFETIME_MS));
        assert!(!window.permits(&old, at, at.timestamp_millis() + MAX_OFFER_LIFETIME_MS + 1));
        let stop = at + chrono::Duration::from_std(ISSUANCE_WINDOW).unwrap();
        assert!(!window.permits(&old, stop, stop.timestamp_millis() + 1));

        assert!(matches!(replica.preflight_task_offer_keys(None).await,
            Err(super::RuntimeError::Refused { reason: "task_offer_required_read_key_missing", .. })));
        assert!(matches!(replica.reserve_task_offer_issuance(&trimmed).await,
            Err(super::RuntimeError::Refused { reason: "task_offer_required_read_key_missing", .. })));
        assert!(matches!(replica.preflight_task_offer_keys(Some(&replaced)).await,
            Err(super::RuntimeError::Refused { reason: "task_offer_key_identity_changed", .. })));
        replica.preflight_task_offer_keys(Some(&new)).await.unwrap();

        let far = stop + chrono::Duration::milliseconds(MAX_OFFER_LIFETIME_MS + MAX_OFFER_CLOCK_SKEW_MS + 1);
        let mut tx = replica.begin().await.unwrap();
        Self::retain_offer_receipt_key(&mut tx, "first").await.unwrap();
        assert!(matches!(Self::check_offer_key_ring_in(&mut tx, Some(&trimmed), far).await,
            Err(super::RuntimeError::Refused { reason: "task_offer_required_read_key_missing", .. })));
        tx.rollback().await.unwrap();

        let mut tx = replica.begin().await.unwrap();
        Self::check_offer_key_ring_in(&mut tx, Some(&trimmed), far).await.unwrap();
        Self::retain_offer_receipt_key(&mut tx, "first").await.unwrap();
        tx.commit().await.unwrap();

        let mut tx = self.begin().await.unwrap();
        assert!(matches!(Self::check_offer_key_ring_in(&mut tx, Some(&trimmed), far).await,
            Err(super::RuntimeError::Refused { reason: "task_offer_required_read_key_missing", .. })));
        Self::release_offer_receipt_key(&mut tx, "first").await.unwrap();
        Self::check_offer_key_ring_in(&mut tx, Some(&trimmed), far).await.unwrap();
        assert!(matches!(Self::check_offer_key_ring_in(&mut tx, Some(&replaced), far).await,
            Err(super::RuntimeError::Refused { reason: "task_offer_key_identity_changed", .. })));
        // Advancing the simulated deadline also isolates later cases using
        // this backend fixture's same database and independent namespace.
        let mut expired = Self::offer_key_in(&mut tx, "first").await.unwrap().unwrap();
        expired.read_until_ms = 0;
        Self::save_offer_key(&mut tx, &expired).await.unwrap();
        tx.commit().await.unwrap();
    }
}
