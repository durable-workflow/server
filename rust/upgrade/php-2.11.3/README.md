# Exact PHP source-catalog variant: Server 2.11.3

These deltas apply to the fresh [PHP 2.11.1 layout](../php-2.11.1/README.md).
Published Server 2.11.4, 2.11.5 and 2.11.7 add no migration and have this
layout too (checked October 30, 2026 against a fresh bootstrap of each).
Published 2.11.8 adds one table; that is the
[`php-2.11.8`](../php-2.11.8/README.md) layout.
They come from normal bootstrap of published Server 2.11.3, tag `2.11.3`,
source `8009904aac5d0a2bf082f06ec0f9f35b816d885a`, image index
`sha256:923a8924ecad13485562d7af30dc87d7fd6d9d7d4758ec8113bac0766fa8603e`,
which pins Core (Workflow package) 2.14.2.

The physical changes from 2.11.1:

- Core `2026_10_23_000100_add_full_failure_message_storage`: nullable
  `workflow_failures.message_full`.
- Core `2026_10_25_000100_add_service_call_idempotency_scope`: nullable
  `workflow_service_calls.idempotency_scope_hash` and its unique index
  `wf_service_calls_idem_scope_unique`.
- Core `2026_10_26_000100_create_workflow_service_call_dispatches_table`: the
  `workflow_service_call_dispatches` table and its three indexes.
- Server: nullable `workflow_worker_sessions.lease_owner_incarnation`, and
  nullable `workflow_durable_streams.reclaim_at` with its index
  `wf_durable_streams_ns_reclaim_idx`.

The same delta was captured from four databases: a fresh 2.11.3 database,
one 2.11.1 created and 2.11.3 migrated, and one 2.8.0 created and 2.11.3
migrated. The new migrations follow every earlier one, so all three end with
the same layout. A database 2.9.x created keeps its own task column order;
that is the [`php-2.11.3-from-2.9.7`](../php-2.11.3-from-2.9.7/README.md)
variant.

Definitions were read from actual published-image databases: SQLite, MySQL
8.0, MariaDB 10.11 through the `mysql` connection, and PostgreSQL 17. No
application row values or credentials are recorded.

Takeover:

- copies each active session's recorded holder incarnation into
  `dw_worker_session_holders`. PHP and native both use the SHA-256 of the
  holder registration's token, so a session PHP already orphaned stays
  orphaned;
- counts and drops `message_full` (`full_failure_messages_removed`). Native
  reads the labelled preview in `message`, as PHP's own older readers do;
  retained history and the verified backup keep the full message;
- drops the idempotency scope digest and its index. The digest is derived
  from the call's own target namespace, names and key;
- refuses while any dispatch is still scheduled or claimed
  (`upgrade_source_service_call_dispatches_pending`), and otherwise counts
  and drops the completed outbox (`service_call_dispatches_removed`);
- drops `reclaim_at` and its index. Only a durable stream has one, and
  takeover still refuses any message stream.
