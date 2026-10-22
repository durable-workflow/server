# October 22, environment contract audit

- **Found by.** The ops-compatibility gate's review of every `DW_*` setting
  in `config/dw-contract.php` (164 entries) against native's readers. Native
  refused only two PHP settings it could not honor. It silently ran with
  PHP's default meaning for dozens of others, such as
  `DW_ACTIVITY_TASK_TIMEOUT`, the task admission caps and the structural
  limits.
  It also read `(true)` as false for `DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS`
  and `DW_RUNTIME_CREDENTIALS_ENABLED`, where Laravel's `env()` reads it as
  true.
- **Source.** `rust/src/config_audit.rs` gives every contract setting one
  disposition:
  - **Honored (36).** Native reads it and applies PHP's meaning. Four of
    these still differ in detail and are listed below.
  - **Ignored (57).** It changes nothing a client or operator of the native
    server observes, for example PHP's queue, cache and process-pool tuning.
  - **Refused (71).** Native refuses to start unless the setting keeps PHP's
    default meaning. Each rule follows PHP's own cast for that setting:
    - `(int)`;
    - `(bool)`;
    - `FILTER_VALIDATE_BOOLEAN` with its fallback;
    - `positiveIntOrNull`;
    - `json_decode` with or without `JSON_THROW_ON_ERROR`;
    - exact or trimmed, case-insensitive string comparisons.

  The refusal names the setting, the accepted values and the reason, and
  never repeats the value.
- **PHP's reading rules.**
  - Values are read like Laravel's `env()`. The words `true`, `false`,
    `empty` and `null` are recognized bare or in parentheses, in any case,
    and one pair of matching outer quotes is removed.
  - Settings use `EnvAuditor::env` and `Env::dw`. A `DW_*` value that reads
    as null falls back to the legacy name. A legacy value that reads as null
    stays null.
  - The auth tokens, auth driver and runtime credential switch now use the
    same reading.
- **Settings outside the contract.** PHP also reads `DW_STORAGE_CONNECTION`
  and `DW_V2_LEGACY_SCHEDULE_STORAGE_TIMEZONE`. Native refuses a storage
  connection other than its own `DB_CONNECTION`, and any legacy schedule
  timezone.
- **Warnings.** At startup native logs PHP's `env:audit` warnings for unknown
  `DW_*` names and for legacy names. Its own settings, such as
  `DW_BIND_ADDRESS`, `DW_RUST_EXPERIMENTAL` and the test-only names, are not
  unknown. `DW_ENV_AUDIT_STRICT=1` refuses startup on any warning, as the PHP
  image's entrypoint does.
- **Shipped deployments.** The values that shipped Compose, Helm, k8s and
  `.env.example` set for the HTTP server process are accepted, and a unit
  test keeps them so. Some shipped containers set PHP's `scheduler_node` or
  `worker_node` process class. They run PHP commands that native does not
  replace, so native refuses those classes. The Sample App native overrides
  give their native slots `DW_SERVER_PROCESS_CLASS=server_http_node`.
- **Tests.**
  - The table matches the contract's names and legacy names exactly, and
    every refusal accepts the contract's own default.
  - Shipped values start.
  - Laravel's normalization and PHP's casts are tested case by case,
    including the edge values.
  - The legacy fallback, refusal messages, warnings and strict mode are
    tested.
  - The parity harness's unqualified authentication cases still find
    `unsupported development authentication configuration`.
- **Still to do (next slice).** These refusals are temporary; native should
  honor the settings:
  - `DW_ACTIVITY_TASK_TIMEOUT`;
  - the history page sizes;
  - the update completion wait;
  - task repair timing;
  - `DW_V2_MULTI_NODE`, reported in health.

  PHP's defaults where native differs today, though honored by name:
  - the worker poll timeout (30 seconds, native 5);
  - the update wait (10 seconds, native 30);
  - the history page size (500, native 100);
  - the operation name length (256, native 255);
  - `DW_MAX_MEMO_BYTES` on start memos.

  Also to do:
  - The other honored readers still parse raw values. They will move to
    the same reading.
  - `DB_SOCKET` and non-default `DB_CHARSET`/`DB_COLLATION` should refuse.
