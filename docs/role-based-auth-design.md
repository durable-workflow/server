# Role-Based Auth Design (Issue #209 Phase 1)

Status: implemented in the server route/middleware layer. The built-in
token/signature behavior now runs through the same `App\Contracts\AuthProvider`
contract that custom deployments can swap in with `DW_AUTH_PROVIDER`.

## Problem
Current flat auth: any valid token grants access to all endpoints. Workers can call terminate, operators can poll tasks.

## Phase 1 Solution: Role-Aware Auth

### Roles
- **worker**: Can access `/worker/*` endpoints (task polling, completion) and `/cluster/info`
- **operator**: Can access workflow control plane (start, signal, query, terminate, etc.)
- **admin**: Can access system operations, namespace provisioning, worker management

### Implementation

**1. Enhanced Auth Config** (`config/server.php`)
```php
'auth' => [
    'driver' => 'token',  // or 'signature', 'none'
    'token' => EnvAuditor::env('DW_AUTH_TOKEN', 'WORKFLOW_SERVER_AUTH_TOKEN'),  // backward compat

    // Role tokens (Phase 1)
    'role_tokens' => [
        'worker' => EnvAuditor::env('DW_WORKER_TOKEN', 'WORKFLOW_SERVER_WORKER_TOKEN'),
        'operator' => EnvAuditor::env('DW_OPERATOR_TOKEN', 'WORKFLOW_SERVER_OPERATOR_TOKEN'),
        'admin' => EnvAuditor::env('DW_ADMIN_TOKEN', 'WORKFLOW_SERVER_ADMIN_TOKEN'),
    ],

    'role_signature_keys' => [
        'worker' => EnvAuditor::env('DW_WORKER_SIGNATURE_KEY', 'WORKFLOW_SERVER_WORKER_SIGNATURE_KEY'),
        'operator' => EnvAuditor::env('DW_OPERATOR_SIGNATURE_KEY', 'WORKFLOW_SERVER_OPERATOR_SIGNATURE_KEY'),
        'admin' => EnvAuditor::env('DW_ADMIN_SIGNATURE_KEY', 'WORKFLOW_SERVER_ADMIN_SIGNATURE_KEY'),
    ],

    // Backward compatibility: if role credentials are not configured,
    // the legacy credential keeps full access
    'backward_compatible' => EnvAuditor::env('DW_AUTH_BACKWARD_COMPATIBLE', 'WORKFLOW_SERVER_AUTH_BACKWARD_COMPATIBLE', true),
],
```

**2. Modified Authenticate Middleware**
- After validating token/signature, determine role
- Store the authenticated `App\Auth\Principal` plus compatibility attributes
  (`auth.role`, `auth.method`, `auth.legacy_full_access`) on the request
- If backward compatible mode + main token → keep full access while no role
  credentials are configured, then treat it as admin-scoped during migration

**3. New RequireRole Middleware**
```php
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $principal = Authenticate::principal($request);

        if (! $authProvider->authorize($principal, 'server.route.access', [
            'allowed_roles' => $roles,
        ])) {
            return error(403, 'forbidden', 'Authenticated role is not allowed');
        }

        return $next($request);
    }
}
```

**4. Route Protection** (`routes/api.php`)
```php
// Discovery - all authenticated roles
Route::get('/cluster/info')->middleware('role:worker,operator,admin');

// Worker plane - worker role only
Route::prefix('worker')->middleware('role:worker')->group(...);

// Operator plane - operator or admin
Route::prefix('workflows')->middleware('role:operator,admin')->group(...);

// Admin plane - admin only
Route::prefix('system')->middleware('role:admin')->group(...);
Route::delete('/workers/{workerId}')->middleware('role:admin');
```

### Endpoint Categorization

**Worker Plane** (role: worker)
- All `/worker/*` endpoints
- `GET /cluster/info`

**Operator Plane** (role: operator, admin)
- Workflows: list, show, start, signal, query, update, cancel, terminate, repair, archive
- History: show, export
- Schedules: CRUD
- Search attributes: CRUD
- Task queues: read
- Workers: read
- Namespaces: read

**Admin Plane** (role: admin only)
- System operations: repair, retention, activity timeouts
- Worker management: DELETE /workers/{id}
- Namespace provisioning: POST/PUT /namespaces

### Backward Compatibility

If `role_tokens` not configured, fall back to:
- Main `auth.token` keeps full API access
- Maintains current behavior for existing deployments

If any role token is configured, the main `auth.token` is still accepted when
`backward_compatible` is true, but it is admin-scoped rather than full-access.
This lets operators migrate gradually without leaving worker-plane access on
the legacy credential.

Signature auth follows the same rule using `role_signature_keys` and the legacy
`signature_key`.

### Pluggable Provider Contract

Set `DW_AUTH_PROVIDER` to a Laravel-resolvable class implementing
`App\Contracts\AuthProvider`:

```php
interface AuthProvider
{
    public function authenticate(Request $request): Principal;

    public function authorize(Principal $principal, string $action, array $resource = []): bool;
}
```

`authenticate()` returns a `Principal` with a subject, roles, optional tenant,
auth method, and non-secret claims. `RequireRole` calls `authorize()` with
`action = server.route.access` and a resource payload containing
`allowed_roles`, method/path, route name, and resolved namespace when available.
Workflow command attribution records the principal context for control-plane
commands so signal/update/query history carries the authenticated subject.

### Testing
1. Unit test role determination logic
2. Integration test endpoint protection
3. Verify backward compatibility

### Migration Path

**Existing deployments (legacy name still honored):**
```env
DW_AUTH_TOKEN=secret123
# → Full API access when role credentials are absent
# Legacy WORKFLOW_SERVER_AUTH_TOKEN still resolves; `env:audit` logs a
# rename hint at boot.
```

**New deployments (role separation):**
```env
DW_WORKER_TOKEN=worker-secret
DW_OPERATOR_TOKEN=operator-secret
DW_ADMIN_TOKEN=admin-secret
```

Workers use `worker-secret`, operators use `operator-secret`, etc.
