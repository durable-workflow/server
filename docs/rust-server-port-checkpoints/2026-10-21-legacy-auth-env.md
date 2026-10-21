# October 21, legacy authentication settings

- **Found by.** Preparing to run the Sample App's polyglot stack against
  native. Its unchanged server environment sets `WORKFLOW_SERVER_AUTH_TOKEN`.
  Native required `DW_AUTH_TOKEN`, and refused to start when one of the
  legacy role-token names `WORKFLOW_SERVER_WORKER_TOKEN`,
  `WORKFLOW_SERVER_OPERATOR_TOKEN` or `WORKFLOW_SERVER_ADMIN_TOKEN` was set.
  Published PHP reads each of these through `EnvAuditor::env`: the `DW_*`
  name when it is set, otherwise the legacy name.
- **Source.** Native now reads the auth token and the three role tokens the
  same way. A set primary wins even when it is empty, as PHP's `env()`
  returns it. The provider, principal-token and runtime-credential settings
  keep their existing refusals.
- **Environment comparison.** Published PHP 2.5.14's `config/*.php` read 49
  settings through `EnvAuditor::env`. Native reads 16 of them, together with
  their legacy names. The rest fall into four groups:
  - **Refused when set.** Authentication drivers other than `token` are
    refused at start, so their signature keys and headers cannot take effect
    silently.
  - **Settings native ignores but that change behavior:**
    - `DW_DEFAULT_NAMESPACE`;
    - `DW_MAX_HISTORY_EVENTS`, `DW_MAX_PENDING_ACTIVITIES`,
      `DW_MAX_PENDING_CHILDREN` and `DW_MAX_TASKS_PER_POLL`;
    - `DW_ACTIVITY_TASK_TIMEOUT` and `DW_WORKER_PROTOCOL_VERSION`.

    Native uses PHP's defaults for these. A deployment that changed one
    would get different behavior from native without notice. This is
    recorded as a known gap; native should refuse a value it cannot honor.
  - **PHP process roles and topology.** `DW_MODE`, `DW_SERVER_ID`,
    `DW_SERVER_PROCESS_CLASS`, `DW_SERVER_TOPOLOGY_SHAPE`,
    `DW_TASK_DISPATCH_MODE`, the poll interval settings and the
    update-validation settings describe PHP's separate processes. Native
    runs one process type.
  - **Features native does not have.** Response compression, package
    provenance, the external executor configuration and storage admission
    files.
- **Verification.** A unit test covers the precedence: the primary wins, the
  legacy name fills in, a set but empty primary still wins, and neither
  gives nothing. The Sample App's polyglot run against native uses the
  legacy token name unchanged.
