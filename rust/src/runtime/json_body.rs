//! Request bodies as deep as PHP's `json_decode` reads them. PHP nests up to
//! 511 levels; serde_json stops at 128 by default. A body this module accepts
//! is first bounded by `payload_limits::depth`, so disabling serde_json's own
//! limit cannot recurse without bound.
use axum::{
    body::{Body, Bytes},
    extract::{FromRequest, Request, rejection::JsonRejection},
    response::{IntoResponse, Response},
};
use serde::{Serialize, de::DeserializeOwned};

/// The deepest nesting PHP's `json_decode` accepts at its default depth.
pub(crate) const PHP_MAX_DEPTH: usize = 511;

/// `serde_json::from_slice`, reading nesting up to PHP's depth.
pub(crate) fn from_slice<T: DeserializeOwned>(bytes: &[u8]) -> serde_json::Result<T> {
    if super::payload_limits::depth(bytes) > PHP_MAX_DEPTH {
        // Past PHP's depth, the default limit gives serde_json's own error.
        return serde_json::from_slice(bytes);
    }
    let mut deserializer = serde_json::Deserializer::from_slice(bytes);
    deserializer.disable_recursion_limit();
    let value = T::deserialize(&mut deserializer)?;
    deserializer.end()?;
    Ok(value)
}

/// `axum::Json`, except that a valid body nested deeper than serde_json's
/// default limit, and no deeper than PHP's, is read. Every other answer,
/// including each rejection, is axum's own.
pub(crate) struct Json<T>(pub T);

impl<T, S> FromRequest<S> for Json<T>
where
    T: DeserializeOwned,
    S: Send + Sync,
{
    type Rejection = JsonRejection;

    async fn from_request(request: Request, state: &S) -> Result<Self, Self::Rejection> {
        let (parts, body) = request.into_parts();
        let bytes = Bytes::from_request(Request::from_parts(parts.clone(), body), state).await?;
        match axum::Json::<T>::from_request(Request::from_parts(parts, Body::from(bytes.clone())), state).await {
            Ok(axum::Json(value)) => Ok(Self(value)),
            Err(rejection @ JsonRejection::JsonSyntaxError(_)) => from_slice(&bytes).map(Self).map_err(|_| rejection),
            Err(rejection) => Err(rejection),
        }
    }
}

impl<T: Serialize> IntoResponse for Json<T> {
    fn into_response(self) -> Response {
        axum::Json(self.0).into_response()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::Value;

    fn nested(levels: usize) -> Vec<u8> {
        format!("{}1{}", "[".repeat(levels), "]".repeat(levels)).into_bytes()
    }

    #[test]
    fn bodies_are_read_to_php_s_depth_and_no_deeper() {
        assert!(serde_json::from_slice::<Value>(&nested(129)).is_err(), "serde_json's default stops at 128");
        for levels in [128, 129, 510, 511] {
            assert!(from_slice::<Value>(&nested(levels)).is_ok(), "{levels}");
        }
        assert!(from_slice::<Value>(&nested(512)).is_err());
        assert!(from_slice::<Value>(b"[1] trailing").is_err());
    }
}
