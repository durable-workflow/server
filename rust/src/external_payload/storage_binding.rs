//! Internal, immutable location of one namespace's registered object. The
//! public reference stays opaque and never carries these filesystem details.
use super::{LocalStorage, Reference, SnapshotError};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::path::{Component, PathBuf};

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct StorageBinding {
    version: u8,
    namespace: String,
    generation: i64,
    driver: String,
    root_uri: String,
}

impl StorageBinding {
    pub(crate) fn new(namespace: &str, generation: i64, storage: &LocalStorage) -> Option<Self> {
        let binding = Self {
            version: 1,
            namespace: namespace.to_owned(),
            generation,
            driver: "local".into(),
            root_uri: format!("file://{}", storage.canonical_root().to_str()?),
        };
        binding.root(namespace)?;
        Some(binding)
    }

    fn root(&self, namespace: &str) -> Option<PathBuf> {
        if self.version != 1
            || self.namespace != namespace
            || namespace.is_empty()
            || namespace.chars().count() > 128
            || self.generation < 1
            || self.driver != "local"
            || self.root_uri.chars().count() > 4096
            || self.root_uri.contains('\0')
        {
            return None;
        }
        let root = PathBuf::from(self.root_uri.strip_prefix("file://")?);
        if !root.is_absolute()
            || root
                .components()
                .any(|part| matches!(part, Component::CurDir | Component::ParentDir))
            || self
                .root_uri
                .strip_prefix("file://")?
                .split('/')
                .any(|part| part == "." || part == "..")
            || format!("file://{}", root.to_str()?) != self.root_uri
        {
            return None;
        }
        Some(root)
    }

    /// Closed shape, owner and exact object address precede all filesystem I/O.
    #[cfg(test)]
    pub(crate) fn decode(
        value: Value,
        namespace: &str,
        reference: &Reference,
        uri: &str,
    ) -> Option<Self> {
        let binding: Self = serde_json::from_value(value).ok()?;
        binding.validate_address(namespace, reference, uri)
    }

    pub(crate) fn decode_stored(
        value: &str,
        namespace: &str,
        reference: &Reference,
        uri: &str,
    ) -> Option<Self> {
        if value.len() > 32_768 {
            return None;
        }
        // Deserialize the original text directly: a Value round trip would
        // erase duplicate keys before the closed-struct decoder sees them.
        let binding: Self = serde_json::from_str(value).ok()?;
        binding.validate_address(namespace, reference, uri)
    }

    fn validate_address(self, namespace: &str, reference: &Reference, uri: &str) -> Option<Self> {
        let binding = self;
        let root = binding.root(namespace)?;
        if LocalStorage::address_at(root, reference).ok()? != uri {
            return None;
        }
        Some(binding)
    }

    pub(crate) fn encode(&self) -> Option<String> {
        let encoded = serde_json::to_string(self).ok()?;
        (encoded.len() <= 32_768).then_some(encoded)
    }

    /// A deletion journal has already released the registration. Its original
    /// binding still limits deletion to the exact content-addressed object.
    pub(crate) fn decode_deletion(value: &str, namespace: &str, uri: &str) -> Option<Self> {
        let digest = uri.rsplit('/').next()?;
        if digest.len() != 64 || !digest.bytes().all(|b| b.is_ascii_hexdigit()) {
            return None;
        }
        let reference = Reference {
            schema: super::REFERENCE_SCHEMA,
            reference_id: "ep_00000000000000000000000000".into(),
            codec: "avro",
            size_bytes: 0,
            sha256: digest.to_owned(),
        };
        Self::decode_stored(value, namespace, &reference, uri)
    }

    pub(crate) async fn storage(&self) -> Result<LocalStorage, SnapshotError> {
        let root = self
            .root(&self.namespace)
            .ok_or(SnapshotError::Unavailable)?;
        LocalStorage::open_existing(root).await
    }

    pub(crate) fn deletion_policy(&self) -> Value {
        // Reads/reclamation are independent of the newest upload-enable flag.
        serde_json::to_value(self).expect("a binding contains only portable scalars")
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn reference() -> Reference {
        Reference::validate(&json!({"schema":super::super::REFERENCE_SCHEMA,
            "reference_id":"ep_01ARZ3NDEKTSV4RRFFQ69G5FAV","codec":"avro","size_bytes":0,
            "sha256":"0".repeat(64)}))
        .unwrap()
    }

    #[tokio::test]
    async fn bindings_keep_original_generation_location_and_namespace_without_public_reference_changes()
     {
        let temporary = tempfile::tempdir().unwrap();
        let first = LocalStorage::open(temporary.path().join("first"))
            .await
            .unwrap();
        let second = LocalStorage::open(temporary.path().join("second"))
            .await
            .unwrap();
        let reference = reference();
        let original = StorageBinding::new("default", 1, &first).unwrap();
        let moved = StorageBinding::new("default", 2, &second).unwrap();
        let original_value = serde_json::to_value(&original).unwrap();
        assert_ne!(
            original_value["root_uri"],
            serde_json::to_value(&moved).unwrap()["root_uri"]
        );
        assert_eq!(
            StorageBinding::decode(
                original_value,
                "default",
                &reference,
                &first.uri_for(&reference).unwrap()
            ),
            Some(original.clone())
        );
        assert_eq!(
            original
                .storage()
                .await
                .unwrap()
                .uri_for(&reference)
                .unwrap(),
            first.uri_for(&reference).unwrap()
        );
        assert_eq!(
            serde_json::to_value(&reference)
                .unwrap()
                .as_object()
                .unwrap()
                .len(),
            5
        );
        assert_eq!(original.deletion_policy()["generation"], 1);
    }

    #[tokio::test]
    async fn foreign_unknown_malformed_and_substituted_bindings_refuse_before_storage_access() {
        let temporary = tempfile::tempdir().unwrap();
        let storage = LocalStorage::open(temporary.path().join("original"))
            .await
            .unwrap();
        let reference = reference();
        let uri = storage.uri_for(&reference).unwrap();
        let original =
            serde_json::to_value(StorageBinding::new("default", 1, &storage).unwrap()).unwrap();
        for (key, value) in [
            ("namespace", json!("peer")),
            ("generation", json!(0)),
            ("generation", json!(1.5)),
            ("version", json!(2)),
            ("driver", json!("unknown")),
            ("extra", json!(true)),
            ("root_uri", json!("file:///not/the/original/root")),
            ("root_uri", json!("file:///a/../b")),
        ] {
            let mut altered = original.clone();
            altered[key] = value;
            assert!(
                StorageBinding::decode(altered, "default", &reference, &uri).is_none(),
                "{key}"
            );
        }
        assert!(
            StorageBinding::decode(original, "default", &reference, "file:///foreign/object")
                .is_none()
        );
    }

    #[tokio::test]
    async fn original_text_rejects_duplicate_fields_and_deletion_keeps_the_exact_owner() {
        let temporary = tempfile::tempdir().unwrap();
        let storage = LocalStorage::open(temporary.path().join("original"))
            .await
            .unwrap();
        let reference = reference();
        let uri = storage.uri_for(&reference).unwrap();
        let binding = StorageBinding::new("default", 1, &storage).unwrap();
        let encoded = binding.encode().unwrap();
        assert!(StorageBinding::decode_stored(&encoded, "default", &reference, &uri).is_some());
        let duplicated = encoded.replace(
            "\"namespace\":\"default\"",
            "\"namespace\":\"peer\",\"namespace\":\"default\"",
        );
        assert_ne!(duplicated, encoded);
        assert!(StorageBinding::decode_stored(&duplicated, "default", &reference, &uri).is_none());
        assert!(StorageBinding::decode_deletion(&encoded, "default", &uri).is_some());
        assert!(StorageBinding::decode_deletion(&encoded, "peer", &uri).is_none());
        assert!(
            StorageBinding::decode_deletion(&encoded, "default", "file:///foreign/avro/00/invalid")
                .is_none()
        );
        assert!(
            StorageBinding::decode_stored(&" ".repeat(32_769), "default", &reference, &uri)
                .is_none()
        );
    }

    #[tokio::test]
    async fn a_missing_retained_root_is_not_recreated() {
        let temporary = tempfile::tempdir().unwrap();
        let root = temporary.path().join("original");
        let storage = LocalStorage::open(root.clone()).await.unwrap();
        let binding = StorageBinding::new("default", 1, &storage).unwrap();
        std::fs::remove_dir(&root).unwrap();
        assert!(binding.storage().await.is_err());
        assert!(!root.exists());
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn a_replaced_root_cannot_redirect_the_recorded_object() {
        let temporary = tempfile::tempdir().unwrap();
        let root = temporary.path().join("original");
        let storage = LocalStorage::open(root.clone()).await.unwrap();
        let binding = StorageBinding::new("default", 1, &storage).unwrap();
        std::fs::rename(&root, temporary.path().join("retained")).unwrap();
        let foreign = temporary.path().join("foreign");
        std::fs::create_dir(&foreign).unwrap();
        std::os::unix::fs::symlink(&foreign, &root).unwrap();
        assert!(binding.storage().await.is_err());
        assert!(std::fs::read_dir(foreign).unwrap().next().is_none());
    }
}
