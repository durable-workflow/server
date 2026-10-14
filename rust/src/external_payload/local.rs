//! Content-addressed local storage. The registry must commit a writing row
//! before put and hold the object lock until the ready promotion commits.
use super::{Reference, Snapshot, SnapshotError, capture};
use sha2::{Digest, Sha256};
use std::{
    fs::{self, File, OpenOptions},
    io::{Read, Seek, Write},
    path::PathBuf,
    sync::Arc,
};
use tokio::sync::Semaphore;

#[derive(Clone)]
pub struct LocalStorage {
    root: Arc<PathBuf>,
}

fn unavailable(_: impl std::fmt::Debug) -> SnapshotError {
    SnapshotError::Unavailable
}

fn checked(reference: &Reference) -> Result<(), SnapshotError> {
    let value = serde_json::to_value(reference).map_err(unavailable)?;
    if Reference::validate(&value).map_err(unavailable)? != *reference {
        return Err(SnapshotError::IntegrityMismatch);
    }
    Ok(())
}

impl LocalStorage {
    pub async fn open(root: PathBuf) -> Result<Self, SnapshotError> {
        let root = tokio::task::spawn_blocking(move || {
            fs::create_dir_all(&root).map_err(unavailable)?;
            let root = root.canonicalize().map_err(unavailable)?;
            if !root.is_dir() || root.to_str().is_none() {
                return Err(SnapshotError::Unavailable);
            }
            Ok(root)
        })
        .await
        .map_err(unavailable)??;
        Ok(Self {
            root: Arc::new(root),
        })
    }

    fn path(&self, reference: &Reference) -> Result<PathBuf, SnapshotError> {
        checked(reference)?;
        Ok(self
            .root
            .join("avro")
            .join(&reference.sha256[..2])
            .join(&reference.sha256))
    }

    /// Matches PHP rawurlencode per path segment, including Unicode and spaces.
    /// This URI is internal registry state and must never enter remote responses.
    pub fn uri_for(&self, reference: &Reference) -> Result<String, SnapshotError> {
        let path = self.path(reference)?;
        let mut uri = String::from("file://");
        for byte in path.to_str().ok_or(SnapshotError::Unavailable)?.bytes() {
            if byte.is_ascii_alphanumeric() || b"/-_.~".contains(&byte) {
                uri.push(char::from(byte));
            } else {
                use std::fmt::Write;
                write!(uri, "%{byte:02X}").map_err(unavailable)?;
            }
        }
        Ok(uri)
    }

    /// Consumes the verified upload. Its permit remains on the blocking worker
    /// through sync, even when the requesting future is cancelled.
    pub async fn put(
        &self,
        reference: Reference,
        mut snapshot: Snapshot,
    ) -> Result<(), SnapshotError> {
        let path = self.path(&reference)?;
        if snapshot.size_bytes() != reference.size_bytes as u64
            || snapshot.sha256() != reference.sha256
        {
            return Err(SnapshotError::IntegrityMismatch);
        }
        let root = self.root.clone();
        tokio::task::spawn_blocking(move || {
            let directory = path.parent().ok_or(SnapshotError::Unavailable)?;
            fs::create_dir_all(directory).map_err(unavailable)?;
            let directory = directory.canonicalize().map_err(unavailable)?;
            if !directory.starts_with(root.as_ref()) || directory == *root {
                return Err(SnapshotError::Unavailable);
            }
            if fs::symlink_metadata(&path).is_ok_and(|entry| !entry.file_type().is_file()) {
                return Err(SnapshotError::Unavailable);
            }
            let mut output = OpenOptions::new()
                .read(true)
                .write(true)
                .create(true)
                .truncate(false)
                .open(&path)
                .map_err(unavailable)?;
            // The DB object lock serializes native writes/deletion. The OS lock
            // also respects PHP's existing local writer during source controls.
            // Do not strand a blocking worker behind an unbounded foreign lock.
            output.try_lock().map_err(unavailable)?;
            let mut hash = Sha256::new();
            let mut buffer = [0; 8192];
            let mut size = 0_u64;
            if output.metadata().map_err(unavailable)?.len() == snapshot.size_bytes() {
                loop {
                    let count = output.read(&mut buffer).map_err(unavailable)?;
                    if count == 0 {
                        break;
                    }
                    hash.update(&buffer[..count]);
                    size += count as u64;
                }
            }
            let digest: String = hash
                .finalize()
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect();
            if size != snapshot.size_bytes() || digest != snapshot.sha256() {
                snapshot.rewind()?;
                output.rewind().map_err(unavailable)?;
                output.set_len(0).map_err(unavailable)?;
                let copied = std::io::copy(&mut snapshot, &mut output).map_err(unavailable)?;
                if copied != snapshot.size_bytes() {
                    return Err(SnapshotError::Unavailable);
                }
            }
            output.flush().map_err(unavailable)?;
            output.sync_all().map_err(unavailable)?;
            // Persist a newly created directory entry before registry readiness.
            for parent in [
                directory.as_path(),
                directory.parent().ok_or(SnapshotError::Unavailable)?,
                root.as_path(),
            ] {
                File::open(parent)
                    .and_then(|directory| directory.sync_all())
                    .map_err(unavailable)?;
            }
            Ok(())
        })
        .await
        .map_err(unavailable)?
    }

    pub async fn fetch(
        &self,
        reference: Reference,
        uri: &str,
        max_bytes: u64,
        temporary_directory: PathBuf,
        workers: Arc<Semaphore>,
    ) -> Result<Snapshot, SnapshotError> {
        if self.uri_for(&reference)? != uri {
            return Err(SnapshotError::Unavailable);
        }
        let path = self.path(&reference)?;
        // Opening is part of capture's admitted blocking work, so queued
        // fetches cannot create an unbounded set of files or blocking jobs.
        let source = LocalReader {
            path,
            root: self.root.clone(),
            source: None,
        };
        capture(source, reference, max_bytes, temporary_directory, workers).await
    }
}

struct LocalReader {
    path: PathBuf,
    root: Arc<PathBuf>,
    source: Option<File>,
}
impl Read for LocalReader {
    fn read(&mut self, buffer: &mut [u8]) -> std::io::Result<usize> {
        if self.source.is_none() {
            let resolved = self.path.canonicalize()?;
            if !resolved.starts_with(self.root.as_ref()) {
                return Err(std::io::Error::other("external_payload_unavailable"));
            }
            if !resolved.is_file() {
                return Err(std::io::Error::other("external_payload_unavailable"));
            }
            self.source = Some(File::open(resolved)?);
        }
        self.source
            .as_mut()
            .expect("opened local object")
            .read(buffer)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Cursor;
    use std::path::Path;

    fn reference(data: &[u8]) -> Reference {
        let digest: String = Sha256::digest(data)
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        Reference {
            schema: super::super::REFERENCE_SCHEMA,
            reference_id: "ep_01J00000000000000000000000".into(),
            codec: "avro",
            size_bytes: data.len() as i64,
            sha256: digest,
        }
    }
    async fn upload(storage: &LocalStorage, temp: &Path, data: &[u8]) -> Reference {
        let expected = reference(data);
        let snapshot = capture(
            Cursor::new(data.to_vec()),
            expected.clone(),
            data.len() as u64 + 1,
            temp.into(),
            Arc::new(Semaphore::new(1)),
        )
        .await
        .unwrap();
        storage.put(expected.clone(), snapshot).await.unwrap();
        expected
    }

    #[tokio::test]
    async fn retries_repair_partial_bytes_without_changing_content_identity() {
        let temp = tempfile::tempdir().unwrap();
        let storage = LocalStorage::open(temp.path().join("space λ"))
            .await
            .unwrap();
        let data = b"encoded transport bytes";
        let expected = upload(&storage, temp.path(), data).await;
        let uri = storage.uri_for(&expected).unwrap();
        assert!(uri.contains("space%20%CE%BB/avro/"));
        let path = storage.path(&expected).unwrap();
        let before = fs::metadata(&path).unwrap().modified().unwrap();
        upload(&storage, temp.path(), data).await;
        assert_eq!(fs::metadata(&path).unwrap().modified().unwrap(), before);
        fs::write(&path, b"partial").unwrap();
        upload(&storage, temp.path(), data).await;
        let mut fetched = storage
            .fetch(
                expected.clone(),
                &uri,
                100,
                temp.path().into(),
                Arc::new(Semaphore::new(1)),
            )
            .await
            .unwrap();
        let mut observed = Vec::new();
        fetched.read_to_end(&mut observed).unwrap();
        assert_eq!(observed, data);
        // The completed snapshot does not follow a subsequent provider change.
        fs::write(&path, b"wrong backing bytes").unwrap();
        fetched.rewind().unwrap();
        observed.clear();
        fetched.read_to_end(&mut observed).unwrap();
        assert_eq!(observed, data);
        assert!(matches!(
            storage
                .fetch(
                    expected,
                    &uri,
                    100,
                    temp.path().into(),
                    Arc::new(Semaphore::new(1))
                )
                .await,
            Err(SnapshotError::IntegrityMismatch)
        ));
    }

    #[tokio::test]
    async fn size_metadata_and_paths_refuse_before_overwriting_a_neighbor() {
        let temp = tempfile::tempdir().unwrap();
        let storage = LocalStorage::open(temp.path().join("objects"))
            .await
            .unwrap();
        let expected = upload(&storage, temp.path(), b"one").await;
        let snapshot = capture(
            Cursor::new(b"two".to_vec()),
            reference(b"two"),
            100,
            temp.path().into(),
            Arc::new(Semaphore::new(1)),
        )
        .await
        .unwrap();
        assert!(matches!(
            storage.put(expected.clone(), snapshot).await,
            Err(SnapshotError::IntegrityMismatch)
        ));
        assert_eq!(fs::read(storage.path(&expected).unwrap()).unwrap(), b"one");
        let mut forged = expected.clone();
        forged.sha256 = "../outside".into();
        assert!(storage.uri_for(&forged).is_err());
        let uri = storage.uri_for(&expected).unwrap();
        assert!(
            storage
                .fetch(
                    expected.clone(),
                    "file:///outside",
                    100,
                    temp.path().into(),
                    Arc::new(Semaphore::new(1))
                )
                .await
                .is_err()
        );
        assert!(matches!(
            storage
                .fetch(
                    expected,
                    &uri,
                    2,
                    temp.path().into(),
                    Arc::new(Semaphore::new(1))
                )
                .await,
            Err(SnapshotError::Oversized)
        ));
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn contended_provider_lock_refuses_without_waiting_or_changing_bytes() {
        let temp = tempfile::tempdir().unwrap();
        let storage = LocalStorage::open(temp.path().join("objects"))
            .await
            .unwrap();
        let expected = upload(&storage, temp.path(), b"one").await;
        let path = storage.path(&expected).unwrap();
        let lock = OpenOptions::new()
            .read(true)
            .write(true)
            .open(&path)
            .unwrap();
        lock.lock().unwrap();
        let snapshot = capture(
            Cursor::new(b"one".to_vec()),
            expected.clone(),
            100,
            temp.path().into(),
            Arc::new(Semaphore::new(1)),
        )
        .await
        .unwrap();
        let result = tokio::time::timeout(
            std::time::Duration::from_secs(1),
            storage.put(expected, snapshot),
        )
        .await;
        drop(lock);
        assert!(matches!(result, Ok(Err(SnapshotError::Unavailable))));
        assert_eq!(fs::read(path).unwrap(), b"one");
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn symlink_escape_does_not_read_or_truncate_foreign_bytes() {
        use std::os::unix::fs::symlink;
        let temp = tempfile::tempdir().unwrap();
        let storage = LocalStorage::open(temp.path().join("objects"))
            .await
            .unwrap();
        let expected = reference(b"foreign");
        let path = storage.path(&expected).unwrap();
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        let outside = temp.path().join("foreign");
        fs::write(&outside, b"foreign").unwrap();
        symlink(&outside, &path).unwrap();
        let uri = storage.uri_for(&expected).unwrap();
        assert!(
            storage
                .fetch(
                    expected.clone(),
                    &uri,
                    100,
                    temp.path().into(),
                    Arc::new(Semaphore::new(1))
                )
                .await
                .is_err()
        );
        let snapshot = capture(
            Cursor::new(b"foreign".to_vec()),
            expected.clone(),
            100,
            temp.path().into(),
            Arc::new(Semaphore::new(1)),
        )
        .await
        .unwrap();
        assert!(storage.put(expected, snapshot).await.is_err());
        assert_eq!(fs::read(outside).unwrap(), b"foreign");
    }
}
