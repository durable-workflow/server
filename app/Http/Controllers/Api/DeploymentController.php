<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\DeploymentLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * First-class worker deployment HTTP surface that exposes the
 * lifecycle (promote, drain, resume, rollback) as one coherent
 * operator-facing API. Backed by {@see DeploymentLifecycleService},
 * which projects the legacy `workflow_worker_build_id_rollouts`
 * table into {@see \Workflow\V2\Support\WorkerDeployment} value
 * objects so the legacy build-id endpoints continue to work
 * unchanged.
 *
 * Refusals carry the machine-readable
 * {@see \Workflow\V2\Support\DeploymentBlockage} list (HTTP 409)
 * frozen by docs/architecture/worker-deployment.md.
 */
class DeploymentController
{
    public function __construct(
        private readonly DeploymentLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $deployments = $this->lifecycle->listForNamespace($namespace);

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'deployments' => array_map(static fn ($d) => $d->toArray(), $deployments),
        ]);
    }

    public function show(Request $request, string $name): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        try {
            $parsed = $this->lifecycle->parseName($this->decodeName($name));
        } catch (InvalidArgumentException $e) {
            return ControlPlaneProtocol::json(['error' => $e->getMessage()], 400);
        }

        $namespace = (string) $request->attributes->get('namespace');

        if ($parsed['namespace'] !== $namespace) {
            return ControlPlaneProtocol::json([
                'error' => 'namespace_mismatch',
                'message' => sprintf(
                    'Deployment name %s is in namespace %s but the request targets %s.',
                    $name,
                    $parsed['namespace'],
                    $namespace,
                ),
            ], 400);
        }

        $deployment = $this->lifecycle->find($parsed['namespace'], $parsed['task_queue'], $parsed['build_id']);

        if ($deployment === null) {
            return ControlPlaneProtocol::json(['error' => 'deployment_not_found'], 404);
        }

        return ControlPlaneProtocol::json($deployment->toArray());
    }

    public function promote(Request $request, string $name): JsonResponse
    {
        return $this->runLifecycle($request, $name, fn ($parsed) => $this->lifecycle->promote(
            $parsed['namespace'],
            $parsed['task_queue'],
            $parsed['build_id'],
        ));
    }

    public function drain(Request $request, string $name): JsonResponse
    {
        return $this->runLifecycle($request, $name, fn ($parsed) => $this->lifecycle->drain(
            $parsed['namespace'],
            $parsed['task_queue'],
            $parsed['build_id'],
        ));
    }

    public function resume(Request $request, string $name): JsonResponse
    {
        return $this->runLifecycle($request, $name, fn ($parsed) => $this->lifecycle->resume(
            $parsed['namespace'],
            $parsed['task_queue'],
            $parsed['build_id'],
        ));
    }

    public function rollback(Request $request, string $name): JsonResponse
    {
        return $this->runLifecycle($request, $name, fn ($parsed) => $this->lifecycle->rollback(
            $parsed['namespace'],
            $parsed['task_queue'],
            $parsed['build_id'],
        ));
    }

    /**
     * @param  callable(array{namespace: string, task_queue: string, build_id: string|null}): array{deployment: \Workflow\V2\Support\WorkerDeployment|null, blockages: list<array<string, mixed>>}  $action
     */
    private function runLifecycle(Request $request, string $name, callable $action): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        try {
            $parsed = $this->lifecycle->parseName($this->decodeName($name));
        } catch (InvalidArgumentException $e) {
            return ControlPlaneProtocol::json(['error' => $e->getMessage()], 400);
        }

        $namespace = (string) $request->attributes->get('namespace');

        if ($parsed['namespace'] !== $namespace) {
            return ControlPlaneProtocol::json([
                'error' => 'namespace_mismatch',
                'message' => sprintf(
                    'Deployment name %s is in namespace %s but the request targets %s.',
                    $name,
                    $parsed['namespace'],
                    $namespace,
                ),
            ], 400);
        }

        $result = $action($parsed);

        if ($result['blockages'] !== []) {
            $this->orderBlockages($result['blockages']);

            return ControlPlaneProtocol::json([
                'deployment' => $result['deployment']?->toArray(),
                'blockages' => $result['blockages'],
            ], 409);
        }

        return ControlPlaneProtocol::json($result['deployment']?->toArray() ?? []);
    }

    private function decodeName(string $name): string
    {
        return rawurldecode($name);
    }

    /**
     * Order the blockages so the configuration-class diagnoses appear
     * before the fleet-class diagnoses, and the safety-class diagnoses
     * appear last. The contract document pins this ordering so the
     * CLI and Waterline render the most actionable refusal first.
     *
     * @param list<array<string, mixed>> $blockages
     */
    private function orderBlockages(array &$blockages): void
    {
        $rank = static function (string $reason): int {
            return match ($reason) {
                'unknown_deployment',
                'incompatible_policy',
                'fleet_is_draining' => 0,
                'no_compatible_workers',
                'missing_worker_heartbeat',
                'fingerprint_mismatch' => 1,
                'replay_safety_failed' => 2,
                default => 3,
            };
        };

        usort($blockages, static function (array $a, array $b) use ($rank): int {
            return $rank((string) ($a['reason'] ?? '')) <=> $rank((string) ($b['reason'] ?? ''));
        });
    }
}
