use std::collections::VecDeque;
use std::ops::{Deref, DerefMut};

use bytes::{Buf, Bytes, BytesMut};

use crate::error::Error;
use crate::io::MySqlBufExt;
use crate::io::{ProtocolDecode, ProtocolEncode};
use crate::net::{BufferedSocket, Socket};
use crate::protocol::response::{EofPacket, ErrPacket, OkPacket, Status};
use crate::protocol::{Capabilities, Packet};
use crate::{MySqlConnectOptions, MySqlDatabaseError};

pub struct MySqlStream<S = Box<dyn Socket>> {
    // Wrapping the socket in `Box` allows us to unsize in-place.
    pub(crate) socket: BufferedSocket<S>,
    pub(crate) server_version: (u16, u16, u16),
    pub(super) capabilities: Capabilities,
    pub(crate) sequence_id: u8,
    pub(crate) waiting: VecDeque<Waiting>,
    pub(crate) is_tls: bool,
    // The write buffer holds only commands without a reply, queued to leave
    // with the next command.
    pub(crate) queued_unanswered: bool,
    // The server status of the last status-bearing reply read, whether the
    // command path or `wait_until_ready` read it.
    pub(crate) status: Status,
    // False after an error reply, which carries no status, until a later
    // reply records one.
    pub(crate) status_known: bool,
    // A `COM_STMT_PREPARE` was sent and its reply is not fully read. Its
    // first packet would parse as an OK packet, so it is never drained.
    pub(crate) prepare_in_flight: bool,
    // While set, a reply must start arriving by this instant or the read
    // fails: a connection handed out after its liveness ping went unanswered
    // (Durable Workflow Server #1355). The first packet that arrives clears
    // it. Once it has passed, every read fails at once, so the connection is
    // discarded when it returns to the pool even if the replies turn up.
    pub(crate) reply_deadline: Option<std::time::Instant>,
    // While set, every read must receive its next packet within this long, or
    // it fails (Durable Workflow Server #1368): a statement in flight, or the
    // pool's release ping, on a peer that went silent without a reset.
    pub(crate) reply_timeout: Option<std::time::Duration>,
    // A read ran past `reply_deadline` or `reply_timeout`. The session is out
    // of step, so every later read fails at once and the owner discards it.
    pub(crate) reply_failed: bool,
}

#[derive(Debug, PartialEq, Eq)]
pub(crate) enum Waiting {
    // waiting for a result set
    Result,

    // waiting for a row within a result set
    Row,
}

impl<S: Socket> MySqlStream<S> {
    pub(crate) fn with_socket(options: &MySqlConnectOptions, socket: S) -> Self {
        let mut capabilities = Capabilities::PROTOCOL_41
            | Capabilities::IGNORE_SPACE
            | Capabilities::DEPRECATE_EOF
            | Capabilities::FOUND_ROWS
            | Capabilities::TRANSACTIONS
            | Capabilities::SECURE_CONNECTION
            | Capabilities::PLUGIN_AUTH_LENENC_DATA
            | Capabilities::MULTI_STATEMENTS
            | Capabilities::MULTI_RESULTS
            | Capabilities::PLUGIN_AUTH
            | Capabilities::PS_MULTI_RESULTS
            | Capabilities::SSL;

        if options.database.is_some() {
            capabilities |= Capabilities::CONNECT_WITH_DB;
        }

        Self {
            waiting: VecDeque::new(),
            capabilities,
            server_version: (0, 0, 0),
            sequence_id: 0,
            socket: BufferedSocket::new(socket),
            is_tls: false,
            queued_unanswered: false,
            status: Default::default(),
            status_known: true,
            prepare_in_flight: false,
            reply_deadline: None,
            reply_timeout: None,
            reply_failed: false,
        }
    }

    /// Send every buffered byte now, including queued commands without a
    /// reply. Explicit flushes and statement cache clears use this; only the
    /// path before the next command leaves queued closes to share its write.
    pub(crate) async fn flush_queued(&mut self) -> Result<(), Error> {
        if !self.socket.write_buffer().is_empty() {
            self.flush().await?;
        }
        self.queued_unanswered = false;
        Ok(())
    }

    /// Flush buffered writes to the database, bounded by `reply_deadline` and
    /// `reply_timeout` when set (Durable Workflow Server #1385). A flush that
    /// stalls on a non-reading peer sets `reply_failed` so the connection is
    /// discarded.
    pub(crate) async fn flush(&mut self) -> Result<(), Error> {
        if self.reply_failed {
            return Err(unanswered_reply());
        }
        let now = std::time::Instant::now();
        let deadline = match (self.reply_deadline, self.reply_timeout) {
            (None, None) => return Ok(self.socket.flush().await?),
            (Some(deadline), None) => deadline,
            (None, Some(timeout)) => now + timeout,
            (Some(deadline), Some(timeout)) => deadline.min(now + timeout),
        };
        let remaining = deadline.saturating_duration_since(now);
        let flushed = if remaining.is_zero() {
            None
        } else {
            sqlx_core::rt::timeout(remaining, self.socket.flush()).await.ok()
        };
        let Some(result) = flushed else {
            self.reply_failed = true;
            return Err(write_timed_out());
        };
        Ok(result?)
    }

    /// Shut down the socket, failing immediately if a prior read or write
    /// already timed out and otherwise honoring the same bound (Durable
    /// Workflow Server #1385).
    pub(crate) async fn shutdown(&mut self) -> Result<(), Error> {
        if self.reply_failed {
            return Err(unanswered_reply());
        }
        let now = std::time::Instant::now();
        let deadline = match (self.reply_deadline, self.reply_timeout) {
            (None, None) => return Ok(self.socket.shutdown().await?),
            (Some(deadline), None) => deadline,
            (None, Some(timeout)) => now + timeout,
            (Some(deadline), Some(timeout)) => deadline.min(now + timeout),
        };
        let remaining = deadline.saturating_duration_since(now);
        let closed = if remaining.is_zero() {
            None
        } else {
            sqlx_core::rt::timeout(remaining, self.socket.shutdown()).await.ok()
        };
        let Some(result) = closed else {
            self.reply_failed = true;
            return Err(write_timed_out());
        };
        Ok(result?)
    }

    pub(crate) fn record_status(&mut self, status: Status) {
        self.status = status;
        self.status_known = true;
    }

    pub(crate) async fn wait_until_ready(&mut self) -> Result<(), Error> {
        // Queued commands without a reply wait for the next command; nothing
        // pending needs them to be sent first.
        if !self.socket.write_buffer().is_empty() && !self.queued_unanswered {
            self.flush().await?;
        }

        while !self.waiting.is_empty() {
            while self.waiting.front() == Some(&Waiting::Row) {
                let packet = self.recv_packet().await?;

                if !packet.is_empty() && packet[0] == 0xfe && packet.len() < 9 {
                    let eof = packet.eof(self.capabilities)?;

                    if eof.status.contains(Status::SERVER_MORE_RESULTS_EXISTS) {
                        *self.waiting.front_mut().unwrap() = Waiting::Result;
                    } else {
                        self.record_status(eof.status);
                        self.waiting.pop_front();
                    };
                }
            }

            while self.waiting.front() == Some(&Waiting::Result) {
                let packet = self.recv_packet().await?;

                if !packet.is_empty() && (packet[0] == 0x00 || packet[0] == 0xff) {
                    let ok = packet.ok()?;

                    if !ok.status.contains(Status::SERVER_MORE_RESULTS_EXISTS) {
                        self.record_status(ok.status);
                        self.waiting.pop_front();
                    }
                } else {
                    *self.waiting.front_mut().unwrap() = Waiting::Row;
                    self.skip_result_metadata(packet).await?;
                }
            }
        }

        Ok(())
    }

    pub(crate) async fn send_packet<'en, T>(&mut self, payload: T) -> Result<(), Error>
    where
        T: ProtocolEncode<'en, Capabilities>,
    {
        self.sequence_id = 0;
        self.write_packet(payload)?;
        self.flush().await?;
        Ok(())
    }

    /// Buffer a command that has no server reply (`COM_STMT_CLOSE`) so it
    /// leaves in the same write as the next command. Flushing it alone leaves
    /// unacknowledged data in flight, and Nagle's algorithm then holds the next
    /// command until the server's delayed acknowledgement (about 40 ms).
    pub(crate) fn queue_packet<'en, T>(&mut self, payload: T) -> Result<(), Error>
    where
        T: ProtocolEncode<'en, Capabilities>,
    {
        let unanswered = self.socket.write_buffer().is_empty() || self.queued_unanswered;
        self.sequence_id = 0;
        self.write_packet(payload)?;
        self.queued_unanswered = unanswered;
        Ok(())
    }

    pub(crate) fn write_packet<'en, T>(&mut self, payload: T) -> Result<(), Error>
    where
        T: ProtocolEncode<'en, Capabilities>,
    {
        self.queued_unanswered = false;
        self.socket
            .write_with(Packet(payload), (self.capabilities, &mut self.sequence_id))
    }

    async fn recv_packet_part(&mut self) -> Result<Bytes, Error> {
        if self.reply_failed {
            return Err(unanswered_reply());
        }
        let now = std::time::Instant::now();
        let deadline = match (self.reply_deadline, self.reply_timeout) {
            (None, None) => return self.read_packet_part().await,
            (Some(deadline), None) => deadline,
            (None, Some(timeout)) => now + timeout,
            (Some(deadline), Some(timeout)) => deadline.min(now + timeout),
        };
        let remaining = deadline.saturating_duration_since(now);
        let read = if remaining.is_zero() {
            None
        } else {
            sqlx_core::rt::timeout(remaining, self.read_packet_part()).await.ok()
        };
        let Some(payload) = read else {
            self.reply_failed = true;
            return Err(unanswered_reply());
        };
        let payload = payload?;
        self.reply_deadline = None;
        Ok(payload)
    }

    async fn read_packet_part(&mut self) -> Result<Bytes, Error> {
        // https://dev.mysql.com/doc/dev/mysql-server/8.0.12/page_protocol_basic_packets.html
        // https://mariadb.com/kb/en/library/0-packet/#standard-packet

        let mut header: Bytes = self.socket.read(4).await?;

        // cannot overflow
        #[allow(clippy::cast_possible_truncation)]
        let packet_size = header.get_uint_le(3) as usize;
        let sequence_id = header.get_u8();

        self.sequence_id = sequence_id.wrapping_add(1);

        let payload: Bytes = self.socket.read(packet_size).await?;

        // TODO: packet compression

        Ok(payload)
    }

    // receive the next packet from the database server
    // may block (async) on more data from the server
    pub(crate) async fn recv_packet(&mut self) -> Result<Packet<Bytes>, Error> {
        let payload = self.recv_packet_part().await?;
        let payload = if payload.len() < 0xFF_FF_FF {
            payload
        } else {
            let mut final_payload = BytesMut::with_capacity(0xFF_FF_FF * 2);
            final_payload.extend_from_slice(&payload);

            drop(payload); // we don't need the allocation anymore

            let mut last_read = 0xFF_FF_FF;
            while last_read == 0xFF_FF_FF {
                let part = self.recv_packet_part().await?;
                last_read = part.len();
                final_payload.extend_from_slice(&part);
            }
            final_payload.into()
        };

        if payload
            .first()
            .ok_or(err_protocol!("Packet empty"))?
            .eq(&0xff)
        {
            self.waiting.pop_front();
            // An error reply carries no server status. The failed command
            // may have left a transaction open (`autocommit=0`, or a routine
            // that started one), so the session state is no longer known.
            self.status_known = false;

            // instead of letting this packet be looked at everywhere, we check here
            // and emit a proper Error
            return Err(
                MySqlDatabaseError(ErrPacket::decode_with(payload, self.capabilities)?).into(),
            );
        }

        Ok(Packet(payload))
    }

    pub(crate) async fn recv<'de, T>(&mut self) -> Result<T, Error>
    where
        T: ProtocolDecode<'de, Capabilities>,
    {
        self.recv_packet().await?.decode_with(self.capabilities)
    }

    pub(crate) async fn recv_ok(&mut self) -> Result<OkPacket, Error> {
        self.recv_packet().await?.ok()
    }

    pub(crate) async fn maybe_recv_eof(&mut self) -> Result<Option<EofPacket>, Error> {
        if self.capabilities.contains(Capabilities::DEPRECATE_EOF) {
            Ok(None)
        } else {
            self.recv().await.map(Some)
        }
    }

    async fn skip_result_metadata(&mut self, mut packet: Packet<Bytes>) -> Result<(), Error> {
        let num_columns: u64 = packet.get_uint_lenenc()?; // column count

        for _ in 0..num_columns {
            let _ = self.recv_packet().await?;
        }

        self.maybe_recv_eof().await?;

        Ok(())
    }

    pub fn boxed_socket(self) -> MySqlStream {
        MySqlStream {
            socket: self.socket.boxed(),
            server_version: self.server_version,
            capabilities: self.capabilities,
            sequence_id: self.sequence_id,
            waiting: self.waiting,
            is_tls: self.is_tls,
            queued_unanswered: self.queued_unanswered,
            status: self.status,
            status_known: self.status_known,
            prepare_in_flight: self.prepare_in_flight,
            reply_deadline: self.reply_deadline,
            reply_timeout: self.reply_timeout,
            reply_failed: self.reply_failed,
        }
    }
}

impl<S> Deref for MySqlStream<S> {
    type Target = BufferedSocket<S>;

    fn deref(&self) -> &Self::Target {
        &self.socket
    }
}

impl<S> DerefMut for MySqlStream<S> {
    fn deref_mut(&mut self) -> &mut Self::Target {
        &mut self.socket
    }
}

/// No reply within a reply bound: after an unanswered liveness ping
/// (Durable Workflow Server #1355), or on any read while `reply_timeout` is
/// set (#1368). The session is not in step any more, so its owner discards it.
pub(crate) fn unanswered_reply() -> Error {
    Error::Io(std::io::Error::new(
        std::io::ErrorKind::TimedOut,
        "the database sent no reply within the connection's reply bound",
    ))
}

/// A write flush stalled past the connection's bound on a peer that stopped
/// reading (Durable Workflow Server #1385). The session is out of step and
/// discarded.
fn write_timed_out() -> Error {
    Error::Io(std::io::Error::new(
        std::io::ErrorKind::TimedOut,
        "the database did not accept a write within the connection's bound",
    ))
}
