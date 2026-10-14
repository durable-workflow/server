use super::Reference;
use sha2::{Digest, Sha256};
use std::{
    fs::File,
    io::{self, Read, Seek, SeekFrom, Write},
    path::{Path, PathBuf},
    sync::{
        Arc,
        atomic::{AtomicBool, Ordering},
    },
};
use tokio::sync::{OwnedSemaphorePermit, Semaphore};

const CHUNK_BYTES: usize = 8192;

#[derive(Debug, thiserror::Error, PartialEq, Eq)]
pub enum SnapshotError {
    #[error("external_payload_oversized")]
    Oversized,
    #[error("external_payload_integrity_mismatch")]
    IntegrityMismatch,
    #[error("external_payload_unavailable")]
    Unavailable,
    #[error("external_payload_not_found")]
    NotFound,
    #[error("external_payload_transfer_cancelled")]
    Cancelled,
}

/// A verified copy owns its bytes independently of the backing object.
/// The file is anonymous and closes on drop, including abandoned transfers.
pub struct Snapshot {
    file: File,
    size_bytes: u64,
    sha256: String,
    _permit: Arc<OwnedSemaphorePermit>,
}

impl Snapshot {
    pub(crate) fn rewind(&mut self) -> Result<(), SnapshotError> {
        self.file.rewind().map_err(|_| SnapshotError::Unavailable)
    }

    pub fn size_bytes(&self) -> u64 {
        self.size_bytes
    }

    pub fn sha256(&self) -> &str {
        &self.sha256
    }
}

impl Snapshot {
    pub fn into_async(self) -> AsyncSnapshot {
        let mut file = tokio::fs::File::from_std(self.file);
        file.set_max_buf_size(CHUNK_BYTES);
        AsyncSnapshot {
            file,
            size_bytes: self.size_bytes,
            sha256: self.sha256,
            _permit: self._permit,
        }
    }
}

pub struct AsyncSnapshot {
    file: tokio::fs::File,
    size_bytes: u64,
    sha256: String,
    _permit: Arc<OwnedSemaphorePermit>,
}

impl AsyncSnapshot {
    pub fn size_bytes(&self) -> u64 {
        self.size_bytes
    }
    pub fn sha256(&self) -> &str {
        &self.sha256
    }
}

impl tokio::io::AsyncRead for AsyncSnapshot {
    fn poll_read(
        self: std::pin::Pin<&mut Self>,
        context: &mut std::task::Context<'_>,
        buffer: &mut tokio::io::ReadBuf<'_>,
    ) -> std::task::Poll<io::Result<()>> {
        std::pin::Pin::new(&mut self.get_mut().file).poll_read(context, buffer)
    }
}

/// Read on a blocking worker. The permit also bounds completed snapshots while
/// a consumer is still reading them, including an HTTP response under pressure.
impl Read for Snapshot {
    fn read(&mut self, buffer: &mut [u8]) -> io::Result<usize> {
        self.file.read(buffer)
    }
}

struct CancelOnDrop(Arc<AtomicBool>);

impl Drop for CancelOnDrop {
    fn drop(&mut self) {
        self.0.store(true, Ordering::Release);
    }
}

/// The permit stays on the blocking worker if the requesting future is dropped.
/// A cancelled worker stops between reads; the source must bound blocking reads.
pub async fn capture<R: Read + Send + 'static>(
    source: R,
    expected: Reference,
    max_bytes: u64,
    temporary_directory: PathBuf,
    workers: Arc<Semaphore>,
) -> Result<Snapshot, SnapshotError> {
    let limit = max_bytes.max(1);
    let expected_size =
        u64::try_from(expected.size_bytes).map_err(|_| SnapshotError::IntegrityMismatch)?;
    if expected_size > limit {
        return Err(SnapshotError::Oversized);
    }
    let permit = workers
        .acquire_owned()
        .await
        .map_err(|_| SnapshotError::Unavailable)?;
    capture_with_permit(
        source,
        expected,
        max_bytes,
        temporary_directory,
        Arc::new(permit),
    )
    .await
}

/// One bounded request can verify several references sequentially. Its snapshots
/// share transfer admission, retaining that slot until every consumer finishes.
pub(crate) async fn capture_with_permit<R: Read + Send + 'static>(
    source: R,
    expected: Reference,
    max_bytes: u64,
    temporary_directory: PathBuf,
    permit: Arc<OwnedSemaphorePermit>,
) -> Result<Snapshot, SnapshotError> {
    let limit = max_bytes.max(1);
    let expected_size =
        u64::try_from(expected.size_bytes).map_err(|_| SnapshotError::IntegrityMismatch)?;
    if expected_size > limit {
        return Err(SnapshotError::Oversized);
    }
    let cancelled = Arc::new(AtomicBool::new(false));
    let _cancellation = CancelOnDrop(cancelled.clone());
    tokio::task::spawn_blocking(move || {
        capture_blocking(
            source,
            expected_size,
            &expected.sha256,
            limit,
            &temporary_directory,
            &cancelled,
            permit,
        )
    })
    .await
    .map_err(|_| SnapshotError::Unavailable)?
}

fn capture_blocking<R: Read>(
    mut source: R,
    expected_size: u64,
    expected_sha256: &str,
    limit: u64,
    temporary_directory: &Path,
    cancelled: &AtomicBool,
    permit: Arc<OwnedSemaphorePermit>,
) -> Result<Snapshot, SnapshotError> {
    let mut file =
        tempfile::tempfile_in(temporary_directory).map_err(|_| SnapshotError::Unavailable)?;
    let mut hash = Sha256::new();
    let mut size_bytes = 0_u64;
    let mut buffer = [0_u8; CHUNK_BYTES];
    loop {
        if cancelled.load(Ordering::Acquire) {
            return Err(SnapshotError::Cancelled);
        }
        // Read at most one byte beyond the limit, without allocating a body.
        let remaining = limit.saturating_sub(size_bytes).saturating_add(1);
        let read_size = usize::try_from(remaining)
            .unwrap_or(usize::MAX)
            .min(CHUNK_BYTES);
        let count = match source.read(&mut buffer[..read_size]) {
            Ok(count) => count,
            Err(error) if error.kind() == io::ErrorKind::Interrupted => continue,
            Err(error) if error.kind() == io::ErrorKind::NotFound => {
                return Err(SnapshotError::NotFound);
            }
            Err(_) => return Err(SnapshotError::Unavailable),
        };
        if count == 0 {
            break;
        }
        size_bytes = size_bytes
            .checked_add(count as u64)
            .ok_or(SnapshotError::Oversized)?;
        if size_bytes > limit {
            return Err(SnapshotError::Oversized);
        }
        hash.update(&buffer[..count]);
        file.write_all(&buffer[..count])
            .map_err(|_| SnapshotError::Unavailable)?;
    }
    let sha256 = hash
        .finalize()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect::<String>();
    if size_bytes != expected_size || sha256 != expected_sha256 {
        return Err(SnapshotError::IntegrityMismatch);
    }
    file.seek(SeekFrom::Start(0))
        .map_err(|_| SnapshotError::Unavailable)?;
    Ok(Snapshot {
        file,
        size_bytes,
        sha256,
        _permit: permit,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        io::Cursor,
        sync::{Condvar, Mutex},
        time::Duration,
    };
    use tokio::sync::Notify;

    fn reference(bytes: &[u8]) -> Reference {
        Reference::validate(&serde_json::json!({
            "schema":super::super::REFERENCE_SCHEMA,"reference_id":"ep_01J00000000000000000000000",
            "codec":"avro","size_bytes":bytes.len(),"sha256":Sha256::digest(bytes).iter().map(|byte|format!("{byte:02x}")).collect::<String>()
        }))
        .unwrap()
    }

    #[tokio::test(flavor = "current_thread")]
    async fn a_batch_retains_its_single_slot_until_the_last_snapshot_consumer_finishes() {
        let directory = tempfile::tempdir().unwrap();
        let workers = Arc::new(Semaphore::new(1));
        let permit = Arc::new(workers.clone().acquire_owned().await.unwrap());
        let first = capture_with_permit(
            Cursor::new(b"first".to_vec()),
            reference(b"first"),
            16,
            directory.path().to_owned(),
            permit.clone(),
        )
        .await
        .unwrap();
        let second = capture_with_permit(
            Cursor::new(b"second".to_vec()),
            reference(b"second"),
            16,
            directory.path().to_owned(),
            permit.clone(),
        )
        .await
        .unwrap();
        drop(permit);
        assert_eq!(workers.available_permits(), 0);
        let first = first.into_async();
        drop(second);
        assert_eq!(workers.available_permits(), 0);
        drop(first);
        assert_eq!(workers.available_permits(), 1);
    }

    #[tokio::test(flavor = "current_thread")]
    async fn asynchronous_consumer_keeps_verified_bytes_and_transfer_capacity() {
        use tokio::io::AsyncReadExt;
        let directory = tempfile::tempdir().unwrap();
        let workers = Arc::new(Semaphore::new(1));
        let bytes = b"wwHioz3/VYAiNwA=";
        let expected = reference(bytes);
        let snapshot = capture(
            Cursor::new(bytes),
            expected.clone(),
            64,
            directory.path().to_owned(),
            workers.clone(),
        )
        .await
        .unwrap();
        let mut consumer = snapshot.into_async();
        assert_eq!(consumer.size_bytes(), bytes.len() as u64);
        assert_eq!(consumer.sha256(), expected.sha256);
        assert_eq!(workers.available_permits(), 0);
        let mut observed = Vec::new();
        consumer.read_to_end(&mut observed).await.unwrap();
        assert_eq!(observed, bytes);
        assert_eq!(
            workers.available_permits(),
            0,
            "capacity remains held through the response lifetime"
        );
        drop(consumer);
        assert_eq!(workers.available_permits(), 1);
    }

    #[tokio::test]
    async fn snapshot_survives_backing_changes_and_contains_exact_encoded_bytes() {
        let directory = tempfile::tempdir().unwrap();
        let original = directory.path().join("backing");
        let bytes = b"wwHioz3/VYAiNwA=";
        std::fs::write(&original, bytes).unwrap();
        let expected = reference(bytes);
        let mut snapshot = capture(
            File::open(&original).unwrap(),
            expected.clone(),
            bytes.len() as u64,
            directory.path().to_owned(),
            Arc::new(Semaphore::new(1)),
        )
        .await
        .unwrap();
        assert_eq!(snapshot.size_bytes(), bytes.len() as u64);
        assert_eq!(snapshot.sha256(), expected.sha256);
        std::fs::write(&original, b"changed after verification").unwrap();
        let mut observed = Vec::new();
        snapshot.read_to_end(&mut observed).unwrap();
        assert_eq!(observed, bytes);
        assert_eq!(
            std::fs::read_dir(directory.path()).unwrap().count(),
            1,
            "anonymous snapshot leaves no named temporary object"
        );
    }

    struct CountedReader {
        source: Cursor<Vec<u8>>,
        read_bytes: Arc<std::sync::atomic::AtomicUsize>,
    }
    impl Read for CountedReader {
        fn read(&mut self, buffer: &mut [u8]) -> io::Result<usize> {
            let size = self.source.read(buffer)?;
            self.read_bytes.fetch_add(size, Ordering::Relaxed);
            Ok(size)
        }
    }

    #[tokio::test]
    async fn declaration_and_observed_limits_refuse_without_retaining_partial_files() {
        let directory = tempfile::tempdir().unwrap();
        let read_bytes = Arc::new(std::sync::atomic::AtomicUsize::new(0));
        let workers = Arc::new(Semaphore::new(1));
        let source = CountedReader {
            source: Cursor::new(vec![7; 100]),
            read_bytes: read_bytes.clone(),
        };
        assert!(matches!(
            capture(
                source,
                reference(&[7; 100]),
                64,
                directory.path().to_owned(),
                workers.clone()
            )
            .await,
            Err(SnapshotError::Oversized)
        ));
        assert_eq!(
            read_bytes.load(Ordering::Relaxed),
            0,
            "declared oversize refuses before reading"
        );
        let source = CountedReader {
            source: Cursor::new(vec![7; 100]),
            read_bytes: read_bytes.clone(),
        };
        assert!(matches!(
            capture(
                source,
                reference(&[7; 64]),
                64,
                directory.path().to_owned(),
                workers
            )
            .await,
            Err(SnapshotError::Oversized)
        ));
        assert_eq!(
            read_bytes.load(Ordering::Relaxed),
            65,
            "one-byte probe bounds actual oversize reads"
        );
        assert_eq!(std::fs::read_dir(directory.path()).unwrap().count(), 0);
    }

    #[tokio::test]
    async fn exact_boundary_empty_metadata_size_and_digest_are_checked() {
        let directory = tempfile::tempdir().unwrap();
        let workers = Arc::new(Semaphore::new(1));
        for bytes in [vec![], vec![7; 8192], vec![7; 8193], vec![7; 2_097_169]] {
            let mismatch_limit = bytes.len() as u64 + 1;
            let expected = reference(&bytes);
            let snapshot = capture(
                Cursor::new(bytes.clone()),
                expected.clone(),
                bytes.len() as u64,
                directory.path().to_owned(),
                workers.clone(),
            )
            .await
            .unwrap();
            assert_eq!(snapshot.size_bytes(), bytes.len() as u64);
            assert_eq!(
                workers.available_permits(),
                0,
                "a retained snapshot still consumes transfer capacity"
            );
            drop(snapshot);
            assert_eq!(workers.available_permits(), 1);
            let mut bad_size = expected.clone();
            bad_size.size_bytes += 1;
            assert!(matches!(
                capture(
                    Cursor::new(bytes.clone()),
                    bad_size,
                    mismatch_limit,
                    directory.path().to_owned(),
                    workers.clone()
                )
                .await,
                Err(SnapshotError::IntegrityMismatch)
            ));
            let mut bad_digest = expected;
            bad_digest.sha256 = "0".repeat(64);
            assert!(matches!(
                capture(
                    Cursor::new(bytes),
                    bad_digest,
                    mismatch_limit,
                    directory.path().to_owned(),
                    workers.clone()
                )
                .await,
                Err(SnapshotError::IntegrityMismatch)
            ));
        }
        assert_eq!(std::fs::read_dir(directory.path()).unwrap().count(), 0);
    }

    struct BlockedReader {
        entered: Arc<Notify>,
        released: Arc<(Mutex<bool>, Condvar)>,
        source: Cursor<Vec<u8>>,
        first: bool,
    }
    impl Read for BlockedReader {
        fn read(&mut self, buffer: &mut [u8]) -> io::Result<usize> {
            if self.first {
                self.first = false;
                self.entered.notify_one();
                let (lock, condition) = &*self.released;
                let mut released = lock.lock().unwrap();
                while !*released {
                    released = condition.wait(released).unwrap();
                }
            }
            self.source.read(buffer)
        }
    }
    fn release(gate: &(Mutex<bool>, Condvar)) {
        *gate.0.lock().unwrap() = true;
        gate.1.notify_all();
    }

    #[tokio::test(flavor = "current_thread")]
    async fn blocked_reads_do_not_block_executor_and_cancelled_jobs_keep_their_permit() {
        let directory = tempfile::tempdir().unwrap();
        let entered = Arc::new(Notify::new());
        let gate = Arc::new((Mutex::new(false), Condvar::new()));
        let workers = Arc::new(Semaphore::new(1));
        let source = BlockedReader {
            entered: entered.clone(),
            released: gate.clone(),
            source: Cursor::new(vec![7; 16]),
            first: true,
        };
        let job = tokio::spawn(capture(
            source,
            reference(&[7; 16]),
            64,
            directory.path().to_owned(),
            workers.clone(),
        ));
        // A watchdog releases the source if the implementation accidentally
        // blocks this single executor thread, so the counterfactual can fail.
        let watchdog_gate = gate.clone();
        let watchdog = std::thread::spawn(move || {
            std::thread::sleep(Duration::from_secs(2));
            release(&watchdog_gate);
        });
        let observed = tokio::time::timeout(Duration::from_millis(500), entered.notified()).await;
        if observed.is_err() {
            release(&gate);
            watchdog.join().unwrap();
            panic!("capture blocked the executor");
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
        assert!(!job.is_finished());
        assert_eq!(workers.available_permits(), 0);
        job.abort();
        assert!(matches!(job.await,Err(error) if error.is_cancelled()));
        assert_eq!(
            workers.available_permits(),
            0,
            "cancellation cannot free a still-running blocking job"
        );
        release(&gate);
        tokio::time::timeout(Duration::from_secs(1), async {
            while workers.available_permits() != 1 {
                tokio::task::yield_now().await;
            }
        })
        .await
        .unwrap();
        assert_eq!(std::fs::read_dir(directory.path()).unwrap().count(), 0);
        watchdog.join().unwrap();
    }

    #[tokio::test]
    async fn unreadable_source_or_temporary_directory_has_no_snapshot() {
        struct FailedReader;
        impl Read for FailedReader {
            fn read(&mut self, _: &mut [u8]) -> io::Result<usize> {
                Err(io::Error::other("private backing failure"))
            }
        }
        let directory = tempfile::tempdir().unwrap();
        let workers = Arc::new(Semaphore::new(1));
        assert!(matches!(
            capture(
                FailedReader,
                reference(b"data"),
                64,
                directory.path().to_owned(),
                workers.clone()
            )
            .await,
            Err(SnapshotError::Unavailable)
        ));
        assert!(matches!(
            capture(
                Cursor::new(b"data"),
                reference(b"data"),
                64,
                directory.path().join("missing"),
                workers
            )
            .await,
            Err(SnapshotError::Unavailable)
        ));
        assert_eq!(std::fs::read_dir(directory.path()).unwrap().count(), 0);
    }
}
