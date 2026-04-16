<?php

namespace App\Support;

use Illuminate\Http\Request;
use Workflow\V2\CommandContext;

final class WorkflowCommandContextFactory
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function make(
        Request $request,
        string $workflowId,
        string $commandName,
        array $metadata = [],
    ): CommandContext {
        [$defaultAuthStatus, $defaultAuthMethod] = $this->defaultAuthMetadata();

        return CommandContext::controlPlane()->with([
            'caller' => $this->callerMetadata($request),
            'auth' => $this->authMetadata($request, $defaultAuthStatus, $defaultAuthMethod),
            'request' => $this->requestMetadata($request),
            'server' => [
                'namespace' => $request->attributes->get('namespace'),
                'workflow_id' => $workflowId,
                'command' => $commandName,
                'metadata' => $metadata,
            ],
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function defaultAuthMetadata(): array
    {
        $authDriver = (string) config('server.auth.driver', 'none');
        $authConfigured = match ($authDriver) {
            'token' => $this->hasConfiguredCredential('server.auth.token')
                || $this->hasConfiguredCredential('server.auth.role_tokens'),
            'signature' => $this->hasConfiguredCredential('server.auth.signature_key')
                || $this->hasConfiguredCredential('server.auth.role_signature_keys'),
            default => false,
        };

        return [
            $authConfigured ? 'authorized' : 'not_configured',
            $authConfigured ? $authDriver : 'none',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function callerMetadata(Request $request): array
    {
        return array_filter([
            'type' => $this->forwardedAttributionValue($request, 'caller_type') ?? 'server',
            'label' => $this->forwardedAttributionValue($request, 'caller_label') ?? 'Standalone Server',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function authMetadata(
        Request $request,
        string $defaultStatus,
        string $defaultMethod,
    ): array {
        return array_filter([
            'status' => $this->forwardedAttributionValue($request, 'auth_status') ?? $defaultStatus,
            'method' => $this->forwardedAttributionValue($request, 'auth_method') ?? $defaultMethod,
            'role' => $request->attributes->get(\App\Http\Middleware\Authenticate::ATTRIBUTE_ROLE),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function hasConfiguredCredential(string $key): bool
    {
        $value = config($key);

        if (is_string($value)) {
            return $value !== '';
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestMetadata(Request $request): array
    {
        $path = '/'.ltrim($request->path(), '/');
        $headers = array_filter([
            'x_request_id' => $this->headerValue($request, 'X-Request-Id'),
            'x_correlation_id' => $this->headerValue($request, 'X-Correlation-Id'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        $fingerprintPayload = [
            'method' => $request->method(),
            'path' => $path,
            'payload' => $this->normalize($request->all()),
            'headers' => $headers,
        ];

        $encodedFingerprintPayload = json_encode(
            $fingerprintPayload,
            JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return array_filter([
            'method' => $request->method(),
            'path' => $path,
            'route_name' => $request->route()?->getName(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $headers['x_request_id'] ?? null,
            'correlation_id' => $headers['x_correlation_id'] ?? null,
            'fingerprint' => $encodedFingerprintPayload === false
                ? null
                : 'sha256:'.hash('sha256', $encodedFingerprintPayload),
            'headers' => $headers === [] ? null : $headers,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function headerValue(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private function forwardedAttributionValue(Request $request, string $field): ?string
    {
        if (! config('server.command_attribution.trust_forwarded_headers', false)) {
            return null;
        }

        $header = config("server.command_attribution.headers.{$field}");

        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $value = $this->headerValue($request, $header);

        return $value !== null
            ? trim($value)
            : null;
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
