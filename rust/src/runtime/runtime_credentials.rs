//! Database runtime credentials, as published PHP Server stores, manages
//! and authenticates them (`RuntimeCredential`, `RuntimeCredentialController`
//! and `ConfiguredAuthProvider`). A credential is a hashed bearer token for a
//! principal with worker or operator roles, scoped to one namespace (its
//! tenant).
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

use super::{
    Result, RuntimeError,
    backend::Backend,
    laravel_rules::{Rule, Rules, failure, normalize, validate},
    store::{Store, now},
};

/// An authenticated caller: PHP's `App\Auth\Principal`.
#[derive(Clone, Debug, PartialEq)]
pub(crate) struct Principal {
    pub(crate) subject: String,
    pub(crate) roles: Vec<String>,
    pub(crate) method: &'static str,
    pub(crate) tenant: Option<String>,
    pub(crate) claims: Value,
    pub(crate) legacy_full_access: bool,
}

impl Principal {
    /// `Principal::role`.
    pub(crate) fn role(role: &str, method: &'static str, legacy_full_access: bool) -> Self {
        Self {
            subject: format!("role:{role}"),
            roles: vec![role.to_owned()],
            method,
            tenant: None,
            claims: json!({}),
            legacy_full_access,
        }
    }

    pub(crate) fn primary_role(&self) -> Option<&str> {
        self.roles.first().map(String::as_str)
    }

    /// `ConfiguredAuthProvider::authorize`: a role the route allows, within
    /// the principal's tenant.
    pub(crate) fn allows(&self, allowed: &[&str], requested_namespace: &str, target_namespace: Option<&str>, namespace_family: bool) -> bool {
        self.legacy_full_access
            || (self.tenant_allows(requested_namespace, target_namespace, namespace_family)
                && self.roles.iter().any(|role| allowed.contains(&role.as_str())))
    }

    /// `ConfiguredAuthProvider::tenantAllows`.
    fn tenant_allows(&self, requested: &str, target: Option<&str>, namespace_family: bool) -> bool {
        let Some(tenant) = self.tenant.as_deref().map(str::trim).filter(|tenant| !tenant.is_empty()) else {
            return true;
        };
        let tenant = tenant.to_lowercase();
        let requested = requested.trim().to_lowercase();
        if requested.is_empty() || requested != tenant {
            return false;
        }
        let target = target.map(|target| target.trim().to_lowercase()).filter(|target| !target.is_empty());
        if target.as_ref().is_some_and(|target| *target != tenant) {
            return false;
        }
        !namespace_family || target.is_some()
    }
}

/// `RuntimeCredential::hashToken`.
fn hash_token(token: &str) -> String {
    Sha256::digest(token.as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// `RuntimeCredential::prefixFor`: the first 12 bytes.
fn prefix(token: &str) -> String {
    let mut end = token.len().min(12);
    while !token.is_char_boundary(end) {
        end -= 1;
    }
    token[..end].to_owned()
}

fn tenant_format(text: &str) -> bool {
    !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"._-".contains(&byte))
}

const CREDENTIAL_RULES: &Rules = &[
    ("token", &[Rule::Required, Rule::String, Rule::Min(32), Rule::Max(512)]),
    ("name", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
    ("subject", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("roles", &[Rule::Required, Rule::Array, Rule::Min(1)]),
    ("roles.*", &[Rule::Required, Rule::String, Rule::In(&["operator", "worker"])]),
    ("tenant", &[Rule::Required, Rule::String, Rule::Max(128), Rule::Pattern(tenant_format)]),
    ("claims", &[Rule::Sometimes, Rule::Array]),
    ("expires_at", &[Rule::Sometimes, Rule::Nullable, Rule::Date]),
];

const ROTATE_RULES: &Rules = &[
    ("token", &[Rule::Required, Rule::String, Rule::Min(32), Rule::Max(512)]),
    ("expires_at", &[Rule::Sometimes, Rule::Nullable, Rule::Date]),
];

fn invalid(field: &str, message: &str) -> RuntimeError {
    failure(vec![(field.to_owned(), vec![message.to_owned()])]).expect("one message")
}

/// `validateCredentialId`.
fn credential_id(id: &str) -> Result<()> {
    let mut bytes = id.bytes();
    let valid = id.len() <= 64
        && bytes.next().is_some_and(|first| first.is_ascii_alphanumeric())
        && bytes.all(|byte| byte.is_ascii_alphanumeric() || b"._-".contains(&byte));
    if valid { Ok(()) } else { Err(invalid("credential_id", "The credential id format is invalid.")) }
}

/// `CarbonImmutable::parse(...)->utc()` for a validated date.
fn date(value: &Value) -> Option<DateTime<Utc>> {
    value.as_str().and_then(super::schedule_backfill::php_date)
}

/// Carbon's `toIso8601String` in UTC.
fn iso(time: Option<DateTime<Utc>>) -> Value {
    time.map_or(Value::Null, |time| json!(time.format("%Y-%m-%dT%H:%M:%S+00:00").to_string()))
}

fn not_found(id: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::NOT_FOUND,
        response: json!({"message":"Runtime credential not found.","reason":"runtime_credential_not_found","credential_id":id}),
    }
}

fn token_conflict(error: sqlx::Error) -> RuntimeError {
    match error {
        sqlx::Error::Database(error) if error.is_unique_violation() => RuntimeError::Protocol {
            status: StatusCode::CONFLICT,
            response: json!({"message":"The supplied runtime credential token is already assigned.",
                "reason":"runtime_credential_token_conflict"}),
        },
        error => error.into(),
    }
}

/// The attributes an upsert stores, as PHP's `attributes` derives them.
struct Attributes {
    name: Option<String>,
    subject: String,
    roles: Vec<String>,
    tenant: String,
    claims: Value,
    token_prefix: String,
    token_hash: String,
    expires_at: Option<DateTime<Utc>>,
}

fn attributes(body: &Value) -> Result<Attributes> {
    let claims = match &body["claims"] {
        Value::Array(items) if !items.is_empty() => {
            return Err(invalid("claims", "The claims field must be a JSON object, not a list."));
        }
        Value::Object(map) => Value::Object(map.clone()),
        _ => json!({}),
    };
    let mut roles: Vec<String> = body["roles"]
        .as_array()
        .into_iter()
        .flatten()
        .filter_map(Value::as_str)
        .map(str::to_owned)
        .collect();
    roles.sort();
    roles.dedup();
    let token = body["token"].as_str().unwrap_or_default();
    Ok(Attributes {
        name: body["name"].as_str().map(str::to_owned),
        subject: body["subject"].as_str().unwrap_or_default().trim().to_owned(),
        roles,
        tenant: body["tenant"].as_str().unwrap_or_default().to_lowercase(),
        claims,
        token_prefix: prefix(token),
        token_hash: hash_token(token),
        expires_at: date(&body["expires_at"]),
    })
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
    fn serialize_credential(row: &DB::Row) -> Result<Value> {
        let claims = DB::optional_document_row(row, "claims")?
            .filter(|claims| claims.as_object().is_some_and(|map| !map.is_empty()))
            .unwrap_or_else(|| json!([]));
        Ok(json!({"id":DB::string(row,"id")?,"name":DB::optional_string(row,"name")?,"subject":DB::string(row,"subject")?,
            "roles":DB::document_row(row,"roles")?,"tenant":DB::string(row,"tenant")?,"claims":claims,
            "token_prefix":DB::string(row,"token_prefix")?,
            "expires_at":iso(DB::optional_instant(row,"expires_at")?),"revoked_at":iso(DB::optional_instant(row,"revoked_at")?),
            "rotated_at":iso(DB::optional_instant(row,"rotated_at")?),"created_at":iso(DB::optional_instant(row,"created_at")?),
            "updated_at":iso(DB::optional_instant(row,"updated_at")?)}))
    }

    /// `RuntimeCredential::activeForToken` and its `principal`.
    pub(crate) async fn runtime_credential_principal(&self, token: &str) -> Result<Option<Principal>> {
        let Some(row) = Self::query("SELECT * FROM runtime_credentials WHERE token_hash=$1")
            .bind(hash_token(token))
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(None);
        };
        if DB::optional_instant(&row, "revoked_at")?.is_some()
            || DB::optional_instant(&row, "expires_at")?.is_some_and(|expires| expires <= now())
        {
            return Ok(None);
        }
        let roles = DB::document_row(&row, "roles")?
            .as_array()
            .into_iter()
            .flatten()
            .filter_map(Value::as_str)
            .map(|role| role.trim().to_owned())
            .filter(|role| !role.is_empty())
            .fold(Vec::new(), |mut roles, role| {
                if !roles.contains(&role) {
                    roles.push(role);
                }
                roles
            });
        Ok(Some(Principal {
            subject: DB::string(&row, "subject")?,
            roles,
            method: "runtime-token",
            tenant: Some(DB::string(&row, "tenant")?),
            claims: DB::optional_document_row(&row, "claims")?.unwrap_or_else(|| json!({})),
            legacy_full_access: false,
        }))
    }

    pub(crate) async fn list_runtime_credentials(&self) -> Result<Value> {
        let rows = Self::query("SELECT * FROM runtime_credentials ORDER BY created_at,id")
            .fetch_all(&self.pool)
            .await?;
        let credentials = rows.iter().map(Self::serialize_credential).collect::<Result<Vec<_>>>()?;
        Ok(json!({"credentials":credentials}))
    }

    pub(crate) async fn show_runtime_credential(&self, id: &str) -> Result<Value> {
        Self::query("SELECT * FROM runtime_credentials WHERE id=$1")
            .bind(id)
            .fetch_optional(&self.pool)
            .await?
            .map(|row| Self::serialize_credential(&row))
            .transpose()?
            .ok_or_else(|| not_found(id))
    }

    /// `RuntimeCredentialController::upsert`: create, or confirm an
    /// identical credential; different attributes conflict.
    pub(crate) async fn upsert_runtime_credential(&self, id: &str, body: Value) -> Result<(StatusCode, Value)> {
        credential_id(id)?;
        let body = normalize(&body);
        validate(&body, CREDENTIAL_RULES)?;
        let attributes = attributes(&body)?;
        let mut tx = self.begin().await?;
        if let Some(row) = Self::query("SELECT * FROM runtime_credentials WHERE id=$1")
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?
        {
            // PHP stores empty claims as an empty array.
            let stored_claims: Value = match DB::optional_document_row(&row, "claims")? {
                Some(Value::Array(items)) if items.is_empty() => json!({}),
                Some(claims) => claims,
                None => json!({}),
            };
            let stored_expiry: Option<DateTime<Utc>> = DB::optional_instant(&row, "expires_at")?;
            let matches = DB::optional_string(&row, "name")? == attributes.name
                && DB::string(&row, "subject")? == attributes.subject
                && DB::document_row(&row, "roles")? == json!(attributes.roles)
                && DB::string(&row, "tenant")? == attributes.tenant
                && stored_claims == attributes.claims
                && DB::string(&row, "token_hash")? == attributes.token_hash
                && stored_expiry == attributes.expires_at
                && DB::optional_instant(&row, "revoked_at")?.is_none();
            tx.commit().await?;
            if !matches {
                return Err(RuntimeError::Protocol {
                    status: StatusCode::CONFLICT,
                    response: json!({"message":"Runtime credential already exists with different attributes.",
                        "reason":"runtime_credential_conflict","credential_id":id}),
                });
            }
            let mut credential = Self::serialize_credential(&row)?;
            credential["created"] = json!(false);
            return Ok((StatusCode::OK, credential));
        }
        let at = now();
        Self::query("INSERT INTO runtime_credentials(id,name,subject,roles,tenant,claims,token_prefix,token_hash,expires_at,revoked_at,rotated_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,NULL,NULL,$10,$11)")
            .bind(id)
            .bind(attributes.name.as_deref())
            .bind(attributes.subject.as_str())
            .bind(Some(DB::document(&json!(attributes.roles))))
            .bind(attributes.tenant.as_str())
            .bind(Some(DB::document(&attributes.claims)))
            .bind(attributes.token_prefix.as_str())
            .bind(attributes.token_hash.as_str())
            .bind(DB::optional_time(attributes.expires_at))
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .execute(&mut *tx)
            .await
            .map_err(token_conflict)?;
        let row = Self::query("SELECT * FROM runtime_credentials WHERE id=$1").bind(id).fetch_one(&mut *tx).await?;
        tx.commit().await?;
        let mut credential = Self::serialize_credential(&row)?;
        credential["created"] = json!(true);
        Ok((StatusCode::CREATED, credential))
    }

    /// `RuntimeCredentialController::rotate`: a new token, which also
    /// restores a revoked credential.
    pub(crate) async fn rotate_runtime_credential(&self, id: &str, body: Value) -> Result<Value> {
        credential_id(id)?;
        let body = normalize(&body);
        validate(&body, ROTATE_RULES)?;
        let token = body["token"].as_str().unwrap_or_default();
        let mut tx = self.begin().await?;
        let row = Self::query("SELECT * FROM runtime_credentials WHERE id=$1")
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| not_found(id))?;
        let hash = hash_token(token);
        let expires_at = if body.get("expires_at").is_some() {
            date(&body["expires_at"])
        } else {
            DB::optional_instant(&row, "expires_at")?
        };
        let changed = DB::string(&row, "token_hash")? != hash
            || DB::optional_instant(&row, "revoked_at")?.is_some()
            || DB::optional_instant(&row, "expires_at")? != expires_at;
        if changed {
            let at = now();
            Self::query("UPDATE runtime_credentials SET token_hash=$1,token_prefix=$2,expires_at=$3,revoked_at=NULL,rotated_at=$4,updated_at=$5 WHERE id=$6")
                .bind(hash.as_str())
                .bind(prefix(token))
                .bind(DB::optional_time(expires_at))
                .bind(DB::bind_time(at))
                .bind(DB::bind_time(at))
                .bind(id)
                .execute(&mut *tx)
                .await
                .map_err(token_conflict)?;
        }
        let row = Self::query("SELECT * FROM runtime_credentials WHERE id=$1").bind(id).fetch_one(&mut *tx).await?;
        tx.commit().await?;
        Self::serialize_credential(&row)
    }

    /// `RuntimeCredentialController::revoke`.
    pub(crate) async fn revoke_runtime_credential(&self, id: &str) -> Result<Value> {
        credential_id(id)?;
        let mut tx = self.begin().await?;
        let at = now();
        Self::query("UPDATE runtime_credentials SET revoked_at=$1,updated_at=$2 WHERE id=$3 AND revoked_at IS NULL")
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .bind(id)
            .execute(&mut *tx)
            .await?;
        let row = Self::query("SELECT * FROM runtime_credentials WHERE id=$1")
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| not_found(id))?;
        tx.commit().await?;
        Self::serialize_credential(&row)
    }
}

/// Credentials as published PHP stores them: `hash('sha256', $token)` of
/// each token. The first is live until 2030, the second is revoked.
#[cfg(test)]
pub(crate) const PHP_CREDENTIAL_ROWS: [&str; 2] = [
    r#"INSERT INTO runtime_credentials(id,name,subject,roles,tenant,claims,token_prefix,token_hash,expires_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcred','worker key','worker-a','["worker"]','default','{"region":"eu"}','dw_live_abc','5ac9039987e0c53110302d98235eafd5b48403cc74f32a9dc365418eab15bfa2','2030-01-01 00:00:00','2026-10-26 10:00:00','2026-10-26 10:00:00')"#,
    r#"INSERT INTO runtime_credentials(id,name,subject,roles,tenant,claims,token_prefix,token_hash,revoked_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kgone','old key','worker-b','["worker"]','default','[]','dw_live_old','2b968f18b9fe6bbd5de84bd3dfa0cf8e2eaf41be6a2e9a8ca860cae3a28321e9','2026-10-26 11:00:00','2026-10-26 10:00:00','2026-10-26 11:00:00')"#,
];

/// What native accepts over `PHP_CREDENTIAL_ROWS` after a takeover: the live
/// PHP-issued token authenticates as its subject, and the revoked one does not.
#[cfg(test)]
pub(crate) async fn assert_php_credentials_served(runtime: &super::Runtime) {
    let principal = runtime
        .runtime_credential_principal("dw_live_abcdefghijklmnopqrstuvwxyz")
        .await
        .unwrap()
        .expect("the PHP-issued token authenticates");
    assert_eq!(
        (principal.subject.as_str(), principal.roles.as_slice(), principal.method, principal.tenant.as_deref(), &principal.claims),
        ("worker-a", ["worker".to_owned()].as_slice(), "runtime-token", Some("default"), &json!({"region": "eu"}))
    );
    assert!(
        runtime.runtime_credential_principal("dw_live_oldoldoldoldoldoldoldold").await.unwrap().is_none(),
        "a revoked key stays refused"
    );
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tenant_principal(tenant: &str, roles: &[&str]) -> Principal {
        Principal {
            subject: "s".into(),
            roles: roles.iter().map(|role| (*role).to_owned()).collect(),
            method: "runtime-token",
            tenant: Some(tenant.into()),
            claims: json!({}),
            legacy_full_access: false,
        }
    }

    #[test]
    fn tenants_reach_only_their_namespace() {
        let worker = tenant_principal("Team-A", &["worker"]);
        assert!(worker.allows(&["worker"], "team-a", None, false));
        assert!(!worker.allows(&["worker"], "default", None, false));
        assert!(!worker.allows(&["operator", "admin"], "team-a", None, false));
        // Namespace routes need a target, and it must be the tenant.
        assert!(!worker.allows(&["worker"], "team-a", None, true));
        assert!(worker.allows(&["worker"], "team-a", Some("TEAM-A"), true));
        assert!(!worker.allows(&["worker"], "team-a", Some("team-b"), true));
        let legacy = Principal::role("admin", "token", true);
        assert!(legacy.allows(&["worker"], "anything", None, true));
    }

    #[test]
    fn tokens_hash_and_prefix_like_php() {
        assert_eq!(hash_token("abc"), "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad");
        assert_eq!(prefix("dwr_fixture_worker_0123456789"), "dwr_fixture_");
        assert!(credential_id("cred-1.a_b").is_ok());
        assert!(credential_id("-bad").is_err());
        assert!(credential_id(&"a".repeat(65)).is_err());
    }
}
