<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ControlPlaneOperation
{
    public function __construct(
        public readonly string $operation,
        public readonly ?string $operationName,
        public readonly ?string $workflowId,
        public readonly ?string $runId = null,
    ) {}

    public static function fromRequest(Request $request): ?self
    {
        $path = '/'.ltrim($request->path(), '/');

        if ($request->isMethod('GET') && $path === '/api/workflows') {
            return new self('list', null, null);
        }

        if ($request->isMethod('POST') && $path === '/api/workflows') {
            return new self('start', null, null);
        }

        if ($request->isMethod('GET') && preg_match('#^/api/workflows/([^/]+)/runs/([^/]+)$#', $path, $matches) === 1) {
            return new self(
                'describe_run',
                null,
                rawurldecode($matches[1]),
                rawurldecode($matches[2]),
            );
        }

        if ($request->isMethod('GET') && preg_match('#^/api/workflows/([^/]+)/runs/([^/]+)/history$#', $path, $matches) === 1) {
            return new self(
                'history',
                null,
                rawurldecode($matches[1]),
                rawurldecode($matches[2]),
            );
        }

        if ($request->isMethod('GET') && preg_match('#^/api/workflows/([^/]+)/runs$#', $path, $matches) === 1) {
            return new self('list_runs', null, rawurldecode($matches[1]));
        }

        if ($request->isMethod('GET') && preg_match('#^/api/workflows/([^/]+)$#', $path, $matches) === 1) {
            return new self('describe', null, rawurldecode($matches[1]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/signal/([^/]+)$#', $path, $matches) === 1) {
            return new self('signal', rawurldecode($matches[2]), rawurldecode($matches[1]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/query/([^/]+)$#', $path, $matches) === 1) {
            return new self('query', rawurldecode($matches[2]), rawurldecode($matches[1]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/update/([^/]+)$#', $path, $matches) === 1) {
            return new self('update', rawurldecode($matches[2]), rawurldecode($matches[1]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/cancel$#', $path, $matches) === 1) {
            return new self('cancel', null, rawurldecode($matches[1]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/terminate$#', $path, $matches) === 1) {
            return new self('terminate', null, rawurldecode($matches[1]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/repair$#', $path, $matches) === 1) {
            return new self('repair', null, rawurldecode($matches[1]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/archive$#', $path, $matches) === 1) {
            return new self('archive', null, rawurldecode($matches[1]));
        }

        // Run-targeted commands — same operation names, with run_id attached.
        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/runs/([^/]+)/signal/([^/]+)$#', $path, $matches) === 1) {
            return new self('signal', rawurldecode($matches[3]), rawurldecode($matches[1]), rawurldecode($matches[2]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/runs/([^/]+)/query/([^/]+)$#', $path, $matches) === 1) {
            return new self('query', rawurldecode($matches[3]), rawurldecode($matches[1]), rawurldecode($matches[2]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/runs/([^/]+)/update/([^/]+)$#', $path, $matches) === 1) {
            return new self('update', rawurldecode($matches[3]), rawurldecode($matches[1]), rawurldecode($matches[2]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/runs/([^/]+)/cancel$#', $path, $matches) === 1) {
            return new self('cancel', null, rawurldecode($matches[1]), rawurldecode($matches[2]));
        }

        if ($request->isMethod('POST') && preg_match('#^/api/workflows/([^/]+)/runs/([^/]+)/terminate$#', $path, $matches) === 1) {
            return new self('terminate', null, rawurldecode($matches[1]), rawurldecode($matches[2]));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function attach(array $payload): array
    {
        if ($this->workflowId !== null && ! array_key_exists('workflow_id', $payload)) {
            $payload['workflow_id'] = $this->workflowId;
        }

        if ($this->runId !== null && ! array_key_exists('run_id', $payload)) {
            $payload['run_id'] = $this->runId;
        }

        return ControlPlaneResponseContract::attach(
            operation: $this->operation,
            operationName: $this->operationName,
            payload: $payload,
        );
    }
}
