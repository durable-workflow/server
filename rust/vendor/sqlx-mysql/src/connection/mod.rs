use std::fmt::{self, Debug, Formatter};
use std::future::Future;

pub(crate) use sqlx_core::connection::*;
use sqlx_core::sql_str::SqlSafeStr;
pub(crate) use stream::{MySqlStream, Waiting};

use crate::collation::Collation;
use crate::common::StatementCache;
use crate::error::Error;
use crate::protocol::response::Status;
use crate::protocol::statement::StmtClose;
use crate::protocol::text::{Ping, Quit};
use crate::statement::MySqlStatementMetadata;
use crate::transaction::Transaction;
use crate::{MySql, MySqlConnectOptions};

mod auth;
mod establish;
mod executor;
mod stream;
mod tls;

const MAX_PACKET_SIZE: u32 = 1024;

/// The charset parameter sent in the `Protocol::HandshakeResponse41` packet.
///
/// This becomes the default if `set_names = false`,
/// and also ensures that any error messages returned before `SET NAMES` are encoded correctly.
#[allow(clippy::cast_possible_truncation)]
const INITIAL_CHARSET: u8 = Collation::UTF8MB4_GENERAL_CI.0 as u8;

/// A connection to a MySQL database.
pub struct MySqlConnection {
    pub(crate) inner: Box<MySqlConnectionInner>,
}

pub(crate) struct MySqlConnectionInner {
    // underlying TCP stream,
    // wrapped in a potentially TLS stream,
    // wrapped in a buffered stream
    pub(crate) stream: MySqlStream,

    // transaction status
    pub(crate) transaction_depth: usize,

    // cache by query string to the statement id and metadata
    cache_statement: StatementCache<(u32, MySqlStatementMetadata)>,

    log_settings: LogSettings,
}

impl MySqlConnection {
    pub(crate) fn in_transaction(&self) -> bool {
        self.inner
            .stream
            .status
            .intersects(Status::SERVER_STATUS_IN_TRANS)
    }

    /// Read the remaining replies of commands already sent, without sending
    /// anything; the stream records the server status they end with. A
    /// result stream dropped after its first row (`fetch_one`,
    /// `fetch_optional`) leaves the rest of its reply unread although the
    /// server sent it with the row. A buffered command that still awaits
    /// sending is left alone, so the session stays not known to be idle. A
    /// statement prepare whose reply is not fully read is an error: that
    /// reply cannot be drained as a result, so the connection must be
    /// discarded (Durable Workflow Server #1204).
    pub async fn read_pending_replies(&mut self) -> Result<(), Error> {
        let stream = &mut self.inner.stream;
        if stream.prepare_in_flight {
            return Err(err_protocol!(
                "a cancelled statement prepare left a reply that cannot be drained"
            ));
        }
        if stream.waiting.is_empty()
            || !(stream.write_buffer().is_empty() || stream.queued_unanswered)
        {
            return Ok(());
        }
        stream.wait_until_ready().await
    }

    /// Whether this session is known to be idle outside a transaction: no
    /// reply is pending or unsent, no statement prepare is half read, no
    /// SQLx transaction is open, and the last reply read carried a server
    /// status (no error reply since) that has no transaction open. A
    /// cancelled command leaves a reply pending, so its state is not known
    /// until that reply is read (Durable Workflow Server #1204).
    pub fn is_idle_outside_transaction(&self) -> bool {
        let stream = &self.inner.stream;
        self.inner.transaction_depth == 0
            && stream.status_known
            && !stream.prepare_in_flight
            && !self.in_transaction()
            && stream.waiting.is_empty()
            && (stream.write_buffer().is_empty() || stream.queued_unanswered)
    }
}

impl Debug for MySqlConnection {
    fn fmt(&self, f: &mut Formatter<'_>) -> fmt::Result {
        f.debug_struct("MySqlConnection").finish()
    }
}

impl Connection for MySqlConnection {
    type Database = MySql;

    type Options = MySqlConnectOptions;

    async fn close(mut self) -> Result<(), Error> {
        self.inner.stream.send_packet(Quit).await?;
        self.inner.stream.shutdown().await?;

        Ok(())
    }

    async fn close_hard(self) -> Result<(), Error> {
        // Hard close must not wait for TLS I/O from an unresponsive peer.
        // Drop the owned transport instead of attempting graceful shutdown.
        drop(self);
        Ok(())
    }

    async fn ping(&mut self) -> Result<(), Error> {
        self.inner.stream.wait_until_ready().await?;
        // Registered before it is sent, so a ping cancelled before its reply
        // arrives leaves a pending reply the next command drains first
        // (Durable Workflow Server #1355).
        self.inner.stream.waiting.push_back(Waiting::Result);
        self.inner.stream.send_packet(Ping).await?;
        // The OK packet carries the session's current server status.
        let status = self.inner.stream.recv_ok().await?.status;
        self.inner.stream.waiting.pop_front();
        self.inner.stream.record_status(status);

        Ok(())
    }

    #[doc(hidden)]
    fn flush(&mut self) -> impl Future<Output = Result<(), Error>> + Send + '_ {
        async move {
            // `Connection::flush` sends everything, queued closes included.
            self.inner.stream.flush_queued().await?;
            self.inner.stream.wait_until_ready().await
        }
    }

    fn cached_statements_size(&self) -> usize {
        self.inner.cache_statement.len()
    }

    async fn clear_cached_statements(&mut self) -> Result<(), Error> {
        while let Some((statement_id, _)) = self.inner.cache_statement.remove_lru() {
            self.inner
                .stream
                .queue_packet(StmtClose {
                    statement: statement_id,
                })?;
        }

        // The server closes them now, not with some later command.
        self.inner.stream.flush_queued().await
    }

    #[doc(hidden)]
    fn should_flush(&self) -> bool {
        !self.inner.stream.write_buffer().is_empty()
    }

    fn begin(
        &mut self,
    ) -> impl Future<Output = Result<Transaction<'_, Self::Database>, Error>> + Send + '_ {
        Transaction::begin(self, None)
    }

    fn begin_with(
        &mut self,
        statement: impl SqlSafeStr,
    ) -> impl Future<Output = Result<Transaction<'_, Self::Database>, Error>> + Send + '_
    where
        Self: Sized,
    {
        Transaction::begin(self, Some(statement.into_sql_str()))
    }

    fn shrink_buffers(&mut self) {
        self.inner.stream.shrink_buffers();
    }
}
