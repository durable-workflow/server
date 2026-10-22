# October 22, runtime credentials

- **Found by.** The Sample App's children and namespaces scenarios on the
  second disposable VM run (Server #325 comment 6328544797). Both set
  `DW_RUNTIME_CREDENTIALS_ENABLED=true` and give each worker its own
  namespace-scoped token. Native refused that setting at startup. The
  namespaces scenario's anonymous phase also restarts the server with
  `DW_AUTH_DRIVER=none`, which native refused too.
- **Source.** Native follows published PHP 2.5.14:
  - **Management API.** `RuntimeCredentialController`, admin-only:
    - `GET /api/runtime-credentials` lists every credential, oldest first;
    - `GET /api/runtime-credentials/{id}` shows one;
    - `PUT /api/runtime-credentials/{id}` creates one (201), confirms an
      identical one (200, `created: false`), or refuses with
      `409 runtime_credential_conflict` or, for a token another credential
      uses, `409 runtime_credential_token_conflict`;
    - `POST /api/runtime-credentials/{id}/rotate` replaces the token and
      clears a revocation;
    - `DELETE /api/runtime-credentials/{id}` revokes it.
  - **Validation.** The controller's rules and Laravel's messages: the
    credential id format, a token of 32 to 512 characters, roles from
    `operator` and `worker`, the tenant format, claims as an object, and a
    valid `expires_at`. Roles are deduplicated and the tenant is lowercased.
  - **Storage.** The `runtime_credentials` table holds the SHA-256 of the
    token and its 12-byte prefix, never the token. Responses carry the
    prefix and PHP's six-digit `+00:00` timestamps.
  - **Authentication.** `ConfiguredAuthProvider`: with the setting on, a
    bearer token that matches no configured token is looked up by its hash.
    An unexpired, unrevoked credential becomes a principal with its subject,
    roles, tenant and claims.
  - **Authorization.** `RouteAuthorizationResource` and `tenantAllows`. A
    principal with a tenant reaches only the requested namespace equal to
    its tenant (the `X-Namespace` header, else the `namespace` query, else
    `default`). On a namespace route, the target is the path segment or else
    the `name` query field, and it must match the tenant too. Then one of
    the principal's roles must be allowed on the route. A refusal is PHP's
    403 with the principal's first role.
  - **Settings.** `DW_RUNTIME_CREDENTIALS_ENABLED`, or its
    `WORKFLOW_SERVER_` name, is read with PHP's `FILTER_VALIDATE_BOOLEAN`, so
    an unrecognized value turns it off. `DW_AUTH_DRIVER=none` admits every
    request as the anonymous administrator.
  - **Task repair.** Repair records the principal's subject, roles, method,
    tenant and claims instead of a fixed legacy token identity.
- **Differences from PHP.**
  - Native still requires `DW_AUTH_TOKEN` at startup, with the `none`
    driver or with database credentials. PHP needs no legacy token then.
  - Principal tokens (`DW_PRINCIPAL_TOKENS`), custom auth providers and the
    signature driver still refuse startup. Runtime credentials grant only
    `operator` and `worker`, so no tenant-bound administrator exists natively.
    For that reason native does not read a namespace target from a JSON body
    `name`, which PHP does; only creating a namespace sends one, and that
    needs an administrator.
  - Native has no service endpoints, so it does not read `caller_namespace`.
  - Commands and history do not yet record the authenticated principal.
    That remains the existing command-context gap.
- **Verification.**
  - Three integration tests pass on SQLite, PostgreSQL and MySQL: managing
    credentials, authenticating within a tenant through rotation and
    revocation, and the `none` driver. Two unit tests cover the tenant rule
    and token hashing. The full SQLite suite passes (331 integration, 189
    library). The auth, role, token and namespace tests pass on PostgreSQL
    and MySQL (60 each).
  - In the Waterline harness, 14 management requests went to published PHP
    2.5.14 and to native: create, confirm, both conflicts, four validation
    failures, show, missing, rotate refusals and revoke. Apart from
    timestamps and JSON key order, the outputs were identical.
  - With `DW_RUNTIME_CREDENTIALS_ENABLED=true` on both servers, 24
    authentication requests matched in status: worker, operator and
    cross-tenant credentials on worker, operator, admin and namespace routes,
    expired, unknown, malformed and revoked tokens. The 401 and 403 bodies
    were identical. Nine more requests checked the namespace route target
    from the path and from `name`, with case and whitespace; all matched.
  - The remaining body differences were outside this change: native answers
    an empty worker registration with `invalid_request` (the registration
    manifest gap), advertises fewer worker capabilities, and PHP's list held
    rows from its shared database.
