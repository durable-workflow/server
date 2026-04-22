# Auth Composition Contract

The auth composition contract defines how external execution carriers resolve
connection, namespace, authentication, TLS, profile, and diagnostic settings.
It is the carrier-neutral rule set for `dw`, SDK adapters, bridge adapters, and
future runners.

The authoritative machine-readable contract is published from
`GET /api/cluster/info` at `auth_composition_contract`:

- `schema: durable-workflow.v2.auth-composition.contract`
- `version: 1`
- `precedence.connection_values`
- `precedence.profile_selection`
- `canonical_environment`
- `auth_material`
- `effective_config`
- `redaction`

Connection values resolve in this order: command flags, environment variables,
the selected profile, and defaults. Profile selection resolves from an explicit
profile flag, then `DW_ENV`, then the current profile, then the default profile.
The portable environment names are `DURABLE_WORKFLOW_SERVER_URL`,
`DURABLE_WORKFLOW_NAMESPACE`, `DURABLE_WORKFLOW_AUTH_TOKEN`,
`DURABLE_WORKFLOW_TLS_VERIFY`, and `DW_ENV`.

Token authentication is supported in version 1. mTLS and signed-header
authentication are reserved extension points; carriers may store only
references for those materials until a future contract version defines their
runtime behavior. Secret material must never appear in diagnostics, logs,
schema manifests, or effective-config output.

Every carrier diagnostic should expose a redacted effective configuration with
the winning source for `server_url`, `namespace`, `profile`, `auth`, `tls`, and
`identity`. Auth values are either `redacted` or reference names. TLS exposes
only the effective verification boolean and its source. Identity is
server-asserted when the server provides it.
