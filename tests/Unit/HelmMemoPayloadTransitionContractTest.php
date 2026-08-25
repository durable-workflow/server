<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class HelmMemoPayloadTransitionContractTest extends TestCase
{
    public function test_chart_fails_closed_for_active_envelope_only_workloads(): void
    {
        $helpers = $this->read('k8s/helm/durable-workflow/templates/_helpers.tpl');

        $this->assertStringContainsString(
            'define "durable-workflow.validateMemoPayloadTransition"',
            $helpers,
        );
        $this->assertStringContainsString('lookup "apps/v1" "Deployment"', $helpers);
        $this->assertStringContainsString('lookup "batch/v1" "CronJob"', $helpers);
        $this->assertStringContainsString('(ne $version "2.0.0-rc.46")', $helpers);
        $this->assertStringContainsString('(ne $storage "dual-v1")', $helpers);
        $this->assertStringContainsString('fail (printf "memo payload transition', $helpers);
    }

    public function test_chart_accepts_an_explicitly_scaled_to_zero_deployment(): void
    {
        $helpers = $this->read('k8s/helm/durable-workflow/templates/_helpers.tpl');

        $this->assertStringContainsString('$replicas := 1', $helpers);
        $this->assertStringContainsString('if hasKey $workload.spec "replicas"', $helpers);
        $this->assertStringContainsString('$replicas = int (get $workload.spec "replicas")', $helpers);
        $this->assertStringNotContainsString('default 1 $workload.spec.replicas', $helpers);
    }

    public function test_every_memo_writing_chart_workload_advertises_the_dual_representation(): void
    {
        foreach ([
            'server-deployment.yaml',
            'worker-deployment.yaml',
            'scheduler-cronjob.yaml',
        ] as $template) {
            $source = $this->read("k8s/helm/durable-workflow/templates/{$template}");

            $this->assertStringContainsString(
                'workflows.durable-workflow.dev/memo-payload-storage: "dual-v1"',
                $source,
                $template,
            );

            $this->assertStringContainsString(
                'include "durable-workflow.validateMemoPayloadTransition" .',
                $source,
                $template,
            );
        }
    }

    public function test_database_rolling_exercise_runs_for_the_landed_candidate_sha(): void
    {
        $workflow = $this->read('.github/workflows/memo-rolling-upgrade.yml');

        $this->assertStringContainsString('run-name: Workflow Memo Rolling Upgrade @ ${{ github.sha }}', $workflow);
        $this->assertMatchesRegularExpression('/push:\s+branches: \[main\]/', $workflow);
        $this->assertStringContainsString('database: [mysql, pgsql]', $workflow);
        $this->assertStringContainsString('scripts/smoke-workflow-memo-rolling-upgrade.sh', $workflow);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }
}
