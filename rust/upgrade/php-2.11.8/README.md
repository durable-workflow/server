# Exact PHP source-catalog variant: Server 2.11.8

These deltas apply to the [PHP 2.11.3 layout](../php-2.11.3/README.md), which
published 2.11.4, 2.11.5 and 2.11.7 share. They come from normal bootstrap of
published Server 2.11.8, tag `2.11.8`, source
`fa3fc2f2`, image index
`sha256:3848d5d5cd1b23bd2881e3fdecea64cc44b72e6fb812e577c2db6d0872d065dd`,
which pins Core (Workflow package) 2.15.12. Core 2.15.12 adds no migration
over 2.15.7; the 2.11.7 and 2.11.8 images list the same 52 Core migrations.

The one physical change from 2.11.7:

- Server `2026_11_01_000000_create_runtime_external_payload_deletions_table`
  (#911, #789): the `runtime_external_payload_deletions` table, its primary
  key, the unique `runtime_payload_deletions_namespace_uri_unique` on
  (`namespace`, `storage_uri_sha256`) and the index
  `runtime_payload_deletions_namespace_created_index`.

The delta was captured on November 1, 2026 as the difference between a fresh
2.11.7 database and a fresh 2.11.8 database, on SQLite, MySQL 8.0, MariaDB
10.11 through the `mysql` connection, and PostgreSQL 17, all from the
published images. Native's `upgrade-inspect` then named every real 2.11.8
database `php-2.11.8`, and every 2.11.7 one still `php-2.11.3`. No
application row values or credentials are recorded.

Takeover carries each pending deletion over to native's
`dw_external_payload_deletions` (`external_payload_deletions_copied`): the
same id, namespace, object, saved storage policy, attempt count and recorded
failure, and its times. Native's cleanup and retention passes then retry it
as PHP's do. No object is deleted during takeover. PHP's table is then
dropped. Inspection reports `external_payload_deletion_inspection`, and
takeover refuses before any change
(`upgrade_source_external_payload_deletions_unsupported`) while a pending
deletion names a storage driver native does not serve, or a failure PHP does
not record. MySQL and MariaDB run this as resumable journal step 11.
