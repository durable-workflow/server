<?php

namespace App\Http\Controllers\Api;

use App\Models\RuntimeCredential;
use App\Support\ControlPlaneProtocol;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RuntimeCredentialController
{
    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        return ControlPlaneProtocol::json([
            'credentials' => RuntimeCredential::query()
                ->orderBy('created_at')
                ->get()
                ->map(fn (RuntimeCredential $credential): array => $this->serialize($credential)),
        ]);
    }

    public function show(Request $request, string $credentialId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $credential = RuntimeCredential::query()->find($credentialId);

        return $credential instanceof RuntimeCredential
            ? ControlPlaneProtocol::json($this->serialize($credential))
            : $this->notFound($credentialId);
    }

    public function upsert(Request $request, string $credentialId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $this->validateCredentialId($credentialId);
        $validated = $request->validate($this->credentialRules());
        $attributes = $this->attributes($validated);

        try {
            $result = DB::transaction(function () use ($attributes, $credentialId): array {
                $credential = RuntimeCredential::query()->lockForUpdate()->find($credentialId);

                if ($credential instanceof RuntimeCredential) {
                    return [
                        'credential' => $credential,
                        'created' => false,
                        'conflict' => ! $this->matches($credential, $attributes),
                    ];
                }

                return [
                    'credential' => RuntimeCredential::query()->create(['id' => $credentialId] + $attributes),
                    'created' => true,
                    'conflict' => false,
                ];
            });
        } catch (QueryException) {
            return ControlPlaneProtocol::json([
                'message' => 'The supplied runtime credential token is already assigned.',
                'reason' => 'runtime_credential_token_conflict',
            ], 409);
        }

        if ($result['conflict']) {
            return ControlPlaneProtocol::json([
                'message' => 'Runtime credential already exists with different attributes.',
                'reason' => 'runtime_credential_conflict',
                'credential_id' => $credentialId,
            ], 409);
        }

        return ControlPlaneProtocol::json(
            $this->serialize($result['credential']) + ['created' => $result['created']],
            $result['created'] ? 201 : 200,
        );
    }

    public function rotate(Request $request, string $credentialId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $this->validateCredentialId($credentialId);
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:512'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        try {
            $credential = DB::transaction(function () use ($credentialId, $validated): ?RuntimeCredential {
                $credential = RuntimeCredential::query()->lockForUpdate()->find($credentialId);

                if (! $credential instanceof RuntimeCredential) {
                    return null;
                }

                $tokenHash = RuntimeCredential::hashToken($validated['token']);
                $expiresAt = array_key_exists('expires_at', $validated)
                    ? $this->date($validated['expires_at'])
                    : $credential->expires_at;
                $changed = ! hash_equals((string) $credential->token_hash, $tokenHash)
                    || $credential->revoked_at !== null
                    || ! $this->datesEqual($credential->expires_at, $expiresAt);

                if ($changed) {
                    $credential->forceFill([
                        'token_hash' => $tokenHash,
                        'token_prefix' => RuntimeCredential::prefixFor($validated['token']),
                        'expires_at' => $expiresAt,
                        'revoked_at' => null,
                        'rotated_at' => now(),
                    ])->save();
                }

                return $credential->refresh();
            });
        } catch (QueryException) {
            return ControlPlaneProtocol::json([
                'message' => 'The supplied runtime credential token is already assigned.',
                'reason' => 'runtime_credential_token_conflict',
            ], 409);
        }

        return $credential instanceof RuntimeCredential
            ? ControlPlaneProtocol::json($this->serialize($credential))
            : $this->notFound($credentialId);
    }

    public function revoke(Request $request, string $credentialId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $this->validateCredentialId($credentialId);

        $credential = DB::transaction(function () use ($credentialId): ?RuntimeCredential {
            $credential = RuntimeCredential::query()->lockForUpdate()->find($credentialId);

            if (! $credential instanceof RuntimeCredential) {
                return null;
            }

            if ($credential->revoked_at === null) {
                $credential->forceFill(['revoked_at' => now()])->save();
            }

            return $credential->refresh();
        });

        return $credential instanceof RuntimeCredential
            ? ControlPlaneProtocol::json($this->serialize($credential))
            : $this->notFound($credentialId);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function credentialRules(): array
    {
        return [
            'token' => ['required', 'string', 'min:32', 'max:512'],
            'name' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(RuntimeCredential::roles())],
            'tenant' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'claims' => ['sometimes', 'array'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $claims = $validated['claims'] ?? [];

        if ($claims !== [] && array_is_list($claims)) {
            throw ValidationException::withMessages([
                'claims' => 'The claims field must be a JSON object, not a list.',
            ]);
        }

        $roles = array_values(array_unique($validated['roles']));
        sort($roles);

        return [
            'name' => $validated['name'] ?? null,
            'subject' => trim($validated['subject']),
            'roles' => $roles,
            'tenant' => strtolower($validated['tenant']),
            'claims' => $claims,
            'token_prefix' => RuntimeCredential::prefixFor($validated['token']),
            'token_hash' => RuntimeCredential::hashToken($validated['token']),
            'expires_at' => $this->date($validated['expires_at'] ?? null),
            'revoked_at' => null,
            'rotated_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function matches(RuntimeCredential $credential, array $attributes): bool
    {
        return $credential->name === $attributes['name']
            && $credential->subject === $attributes['subject']
            && $credential->roles === $attributes['roles']
            && $credential->tenant === $attributes['tenant']
            && ($credential->claims ?? []) == $attributes['claims']
            && hash_equals((string) $credential->token_hash, $attributes['token_hash'])
            && $this->datesEqual($credential->expires_at, $attributes['expires_at'])
            && $credential->revoked_at === null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === ''
            ? null
            : CarbonImmutable::parse((string) $value)->utc();
    }

    private function datesEqual(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return CarbonImmutable::parse($left)->utc()->equalTo(CarbonImmutable::parse($right)->utc());
    }

    private function validateCredentialId(string $credentialId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $credentialId) !== 1) {
            throw ValidationException::withMessages([
                'credential_id' => 'The credential id format is invalid.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(RuntimeCredential $credential): array
    {
        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'subject' => $credential->subject,
            'roles' => $credential->roles,
            'tenant' => $credential->tenant,
            'claims' => $credential->claims ?? [],
            'token_prefix' => $credential->token_prefix,
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'revoked_at' => $credential->revoked_at?->toIso8601String(),
            'rotated_at' => $credential->rotated_at?->toIso8601String(),
            'created_at' => $credential->created_at?->toIso8601String(),
            'updated_at' => $credential->updated_at?->toIso8601String(),
        ];
    }

    private function notFound(string $credentialId): JsonResponse
    {
        return ControlPlaneProtocol::json([
            'message' => 'Runtime credential not found.',
            'reason' => 'runtime_credential_not_found',
            'credential_id' => $credentialId,
        ], 404);
    }
}
