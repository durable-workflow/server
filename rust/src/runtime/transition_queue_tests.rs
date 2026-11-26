//! Exercise the real transition entrypoint and pool while a writer holds the lock.
use super::{RuntimeError, store::Store};
use std::{future::{Future, poll_fn}, pin::Pin, sync::atomic::Ordering, task::Poll, time::Duration};

async fn pending<F: Future>(mut future: Pin<&mut F>) {
    poll_fn(|context| {
        assert!(future.as_mut().poll(context).is_pending(), "a queued transition acquired early");
        Poll::Ready(())
    }).await;
}

store_methods! {
    pub(super) async fn qualify_transition_queue(&self) {
        Self::query("CREATE TABLE dw_transition_queue_probe (ordinal BIGINT PRIMARY KEY)")
            .execute(&self.pool).await.unwrap();
        // Await each return hook so the pool has every slot ready before
        // testing which requests, rather than connection creation, occupy them.
        let slots = self.pool.options().get_max_connections();
        assert!(slots >= 3, "a holder, a queued waiter and readers need three slots");
        let mut warmed = Vec::new();
        for _ in 0..slots { warmed.push(self.pool.acquire().await.unwrap()); }
        for mut connection in warmed { connection.return_to_pool().await; }
        assert_eq!(self.pool.size(), slots);
        assert_eq!(self.pool.num_idle(), slots as usize);

        let holder = self.begin().await.unwrap();
        let namespaces: Vec<_> = (0..4).map(|n| self.in_namespace(&format!("fifo-{n}"))).collect();
        let mut waiters: Vec<_> = namespaces.iter().map(|store| Some(Box::pin(store.begin()))).collect();
        for waiter in &mut waiters { pending(waiter.as_mut().unwrap().as_mut()).await; }

        // All other connections remain available to simultaneous reads.
        // The predecessor instead spends them waiting for the database lock.
        let mut readers = Vec::new();
        for _ in 1..slots { readers.push(self.pool.acquire().await.unwrap()); }
        for mut reader in readers {
            let row = Self::query("SELECT COUNT(*) AS n FROM dw_transition_queue_probe")
                .fetch_one(&mut *reader).await.unwrap();
            assert_eq!(DB::number(&row, "n").unwrap(), 0);
            reader.return_to_pool().await;
        }
        // Cancelling the second arrival must neither block nor reorder the rest.
        drop(waiters[1].take());
        holder.commit().await.unwrap();
        for (committed, index) in [0usize, 2, 3].into_iter().enumerate() {
            // Poll later arrivals first: they may not barge past the first
            // queued waiter even when it has not yet resumed its future.
            for waiter in waiters.iter_mut().skip(index + 1).flatten() { pending(waiter.as_mut()).await; }
            let mut transaction = waiters[index].take().unwrap().await.unwrap();
            let row = Self::query("SELECT COUNT(*) AS n FROM dw_transition_queue_probe")
                .fetch_one(&mut *transaction).await.unwrap();
            assert_eq!(DB::number(&row, "n").unwrap(), committed as i64);
            Self::query("INSERT INTO dw_transition_queue_probe (ordinal) VALUES ($1)")
                .bind(index as i64).execute(&mut *transaction).await.unwrap();
            transaction.commit().await.unwrap();
        }

        let holder = self.begin().await.unwrap();
        let before = self.test_transitions.load(Ordering::Relaxed);
        assert_eq!(self.pool.options().get_acquire_timeout(), Duration::from_secs(5),
            "retain the runtime's existing acquisition deadline");
        assert_eq!(self.transition_queue_deadline, super::store::TRANSITION_QUEUE_DEADLINE);
        assert!(super::store::TRANSITION_QUEUE_DEADLINE > self.pool.options().get_acquire_timeout(),
            "waiting out a stalled transition is not a pool timeout (Server #1355)");
        // The production deadline is thirty seconds; the queue's behavior at
        // its deadline is the same with a shorter one.
        let deadline = Duration::from_secs(2);
        let started = tokio::time::Instant::now();
        let shortened: Vec<_> = namespaces.iter().map(|store| {
            let mut store = store.in_namespace(&store.namespace);
            store.transition_queue_deadline = deadline;
            store
        }).collect();
        let mut blocked: Vec<_> = shortened.iter().map(|store| Box::pin(store.begin())).collect();
        for waiter in &mut blocked { pending(waiter.as_mut()).await; }
        for waiter in blocked {
            let error = tokio::time::timeout(deadline + Duration::from_secs(2), waiter)
                .await.expect("queue wait must be bounded").err().expect("holder is still live");
            assert!(matches!(&error, RuntimeError::TransitionQueueTimeout), "{error:?}");
            assert_eq!(super::worker_incarnations::transient_reason(&error), Some("backend_unavailable"));
        }
        assert!(started.elapsed() >= deadline);
        assert_eq!(self.test_transitions.load(Ordering::Relaxed), before,
            "an expired queue waiter never reaches DB::begin");
        assert_eq!(self.pool.num_idle(), slots as usize - 1, "a queue timeout must not consume reader connections");
        holder.rollback().await.unwrap();

        // Rollback, statement failure and an implicit transaction drop all
        // release the queue. A subsequent transition observes committed data.
        let mut failed = self.begin().await.unwrap();
        assert!(Self::query("INSERT INTO dw_transition_queue_probe (ordinal) VALUES (0)")
            .execute(&mut *failed).await.is_err());
        drop(failed);
        let mut recovered = self.begin().await.unwrap();
        let row = Self::query("SELECT COUNT(*) AS n FROM dw_transition_queue_probe")
            .fetch_one(&mut *recovered).await.unwrap();
        assert_eq!(DB::number(&row, "n").unwrap(), 3);
        recovered.rollback().await.unwrap();
        Self::query("DROP TABLE dw_transition_queue_probe").execute(&self.pool).await.unwrap();
    }
}

#[tokio::test]
async fn sqlite_transition_queue_preserves_reads_fifo_and_timeout_admission() {
    tokio::time::timeout(Duration::from_secs(30), async {
        let directory = tempfile::tempdir().unwrap();
        let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(directory.path().join("queue.sqlite").to_str().unwrap()).await.unwrap());
        store.qualify_transition_queue().await;
        store.close().await;
    }).await.expect("bounded SQLite transition queue qualification");
}
