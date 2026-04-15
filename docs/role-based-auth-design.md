# Role-Based Auth Design (Issue #209 Phase 1)

## Problem
Current flat auth: any valid token grants access to all endpoints. Workers can call terminate, operators can poll tasks.

## Phase 1 Solution: Role-Aware Token Auth

### Roles
- **worker**: Can only access `/worker/*` endpoints (task polling, completion)
- **operator**: Can access workflow control plane (start, signal, query, terminate, etc.)
- **admin**: Can access system operations, namespace provisioning, worker management

### Implementation

**1. Enhanced Auth Config** (`config/server.php`)
```php
'auth' => [
    'driver' => 'token',  // or 'signature', 'none'
    'token' => env('WORKFLOW_SERVER_AUTH_TOKEN'),  // backward compat
    
    // Role tokens (Phase 1)
    'role_tokens' => [
        'worker' => env('WORKFLOW_SERVER_WORKER_TOKEN'),
        'operator' => env('WORKFLOW_SERVER_OPERATOR_TOKEN'),
        'admin' => env('WORKFLOW_SERVER_ADMIN_TOKEN'),
    ],
    
    // Backward compatibility: if role_tokens not configured,
    // grant 'admin' to main token
    'backward_compatible' => env('WORKFLOW_SERVER_AUTH_BACKWARD_COMPATIBLE', true),
],
```

**2. Modified Authenticate Middleware**
- After validating token/signature, determine role
- Store role in `$request->attributes->set('auth.role', $role)`
- If backward compatible mode + main token → grant 'admin'

**3. New RequireRole Middleware**
```php
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $currentRole = $request->attributes->get('auth.role');
        
        if (!in_array($currentRole, $roles, true)) {
            return error(403, 'forbidden', "Access denied for role: $currentRole");
        }
        
        return $next($request);
    }
}
```

**4. Route Protection** (`routes/api.php`)
```php
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
- Main `auth.token` grants 'admin' role
- Maintains current behavior for existing deployments

### Testing
1. Unit test role determination logic
2. Integration test endpoint protection
3. Verify backward compatibility

### Migration Path

**Existing deployments (no change needed):**
```env
WORKFLOW_SERVER_AUTH_TOKEN=secret123
# → Grants 'admin' role (backward compatible)
```

**New deployments (role separation):**
```env
WORKFLOW_SERVER_WORKER_TOKEN=worker-secret
WORKFLOW_SERVER_OPERATOR_TOKEN=operator-secret
WORKFLOW_SERVER_ADMIN_TOKEN=admin-secret
```

Workers use `worker-secret`, operators use `operator-secret`, etc.

