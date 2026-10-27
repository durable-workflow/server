# October 26, takeover keeps runtime credentials

- **Before.** Takeover refused any PHP database holding a
  `runtime_credentials` row (`upgrade_source_runtime_credentials_unsupported`).
  An operator who had issued worker tokens through
  `/api/runtime-credentials` could not cut over without
  revoking them all and reissuing tokens to every worker.
- **Why it is safe to keep them.** Native serves runtime credentials from the
  same `runtime_credentials` table, with the same columns. PHP stores
  `hash('sha256', $token)` as lowercase hex; native looks a token up by the same
  digest. Expiry, revocation, roles, tenant and claims are read from the same
  columns. So the rows need no conversion, and a token PHP issued keeps
  working after takeover.
- **Change.** The refusal is removed. Takeover leaves the table as it is.
- **Tests.** `PHP_CREDENTIAL_ROWS` stores one live and one revoked credential
  the way PHP does, with digests computed outside native. After takeover the
  live PHP token authenticates with its subject, roles, tenant and claims,
  and the revoked one is refused:
  - SQLite: `takeover_keeps_php_runtime_credentials_that_native_then_accepts`;
  - PostgreSQL, MySQL and MariaDB: the 2.11.1 takeover qualification, for
    both the fresh and the upgraded-from-2.9.7 layouts.
- **Update validation tasks** carry over too (October 27). Native validates
  updates in the same `workflow_update_validation_tasks` rows and keeps no
  state of its own, so takeover leaves them as they are. A pending task whose
  caller was waiting on PHP is then what a PHP restart leaves: a validator may
  still claim it, and a caller's retry with the same request reads its
  recorded outcome. `takeover_keeps_php_update_validation_tasks` covers it.
- **Still refused.** Message streams and external payloads.
