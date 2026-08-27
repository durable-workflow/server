<?php

declare(strict_types=1);

namespace Tests\Unit;

use Symfony\Component\Yaml\Yaml;
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
        $this->assertStringContainsString('lookup "v1" "Pod"', $helpers);
        $this->assertStringContainsString('lookup "batch/v1" "Job"', $helpers);
        $this->assertStringContainsString('$workload.spec.template.spec.containers', $helpers);
        $this->assertStringContainsString('$scheduler.spec.jobTemplate.spec.template.spec.containers', $helpers);
        $this->assertStringContainsString('include "durable-workflow.memoPayloadStorageForWorkloadImage"', $helpers);
        $this->assertStringContainsString('(not (has $phase (list "Succeeded" "Failed")))', $helpers);
        $this->assertStringContainsString('(not $terminal)', $helpers);
        $this->assertStringContainsString('$pod.metadata.deletionTimestamp', $helpers);
        $this->assertStringContainsString('docker.io/durableworkflow/server:2.0.0', $helpers);
        $this->assertStringNotContainsString('get $labels "app.kubernetes.io/version"', $helpers);
        $this->assertStringContainsString('fail (printf "memo payload transition', $helpers);
    }

    public function test_chart_fails_closed_for_an_unverified_target_image(): void
    {
        $helpers = $this->read('k8s/helm/durable-workflow/templates/_helpers.tpl');

        $this->assertStringContainsString('define "durable-workflow.targetMemoPayloadStorage"', $helpers);
        $this->assertStringContainsString('.Values.image.memoPayloadStorage', $helpers);
        $this->assertStringContainsString('(eq $targetStorage "unknown")', $helpers);
        $this->assertStringContainsString('(eq $targetStorage "envelope-v1")', $helpers);
        $this->assertStringContainsString('recognized Server image %s has %s capability', $helpers);
    }

    public function test_chart_does_not_treat_zero_desired_replicas_as_quiescence(): void
    {
        $helpers = $this->read('k8s/helm/durable-workflow/templates/_helpers.tpl');

        $this->assertStringContainsString('$replicas := 1', $helpers);
        $this->assertStringContainsString('if hasKey $workload.spec "replicas"', $helpers);
        $this->assertStringContainsString('$replicas = int (get $workload.spec "replicas")', $helpers);
        $this->assertStringNotContainsString('default 1 $workload.spec.replicas', $helpers);
        $this->assertStringContainsString('range $pod := default (list) $pods.items', $helpers);
        $this->assertStringContainsString('managed %s pod %s is %s', $helpers);
    }

    public function test_every_memo_writing_chart_workload_advertises_the_dual_representation(): void
    {
        foreach ([
            'server-deployment.yaml' => 2,
            'worker-deployment.yaml' => 2,
            'scheduler-cronjob.yaml' => 3,
        ] as $template => $expectedMarkerCount) {
            $source = $this->read("k8s/helm/durable-workflow/templates/{$template}");

            $this->assertSame(
                $expectedMarkerCount,
                substr_count(
                    $source,
                    'workflows.durable-workflow.dev/memo-payload-storage: {{ include "durable-workflow.targetMemoPayloadStorage" . | quote }}',
                ),
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

    public function test_helm_checks_execute_the_live_upgrade_guard_matrix(): void
    {
        $workflow = $this->read('.github/workflows/helm-chart-checks.yml');
        $scriptPath = base_path('scripts/ci/helm-memo-transition-upgrade.sh');
        $script = $this->read('scripts/ci/helm-memo-transition-upgrade.sh');

        $this->assertStringContainsString('scripts/ci/helm-memo-transition-upgrade.sh', $workflow);
        $this->assertTrue(is_executable($scriptPath));
        $this->assertStringContainsString('--dry-run=server', $script);
        $this->assertStringContainsString('memo-transition-hold', $script);
        $this->assertStringContainsString('--cascade=orphan', $script);
        $this->assertStringContainsString('--for=condition=complete', $script);
        $this->assertStringContainsString(<<<'YAML'
      template:
        metadata:
          labels:
            app.kubernetes.io/name: durable-workflow
            app.kubernetes.io/instance: memo-transition
            app.kubernetes.io/component: scheduler
YAML, $script);
        $this->assertStringContainsString("expect_allowed 'old Deployment pod and scheduler execution are deleted'", $script);
    }

    public function test_successor_worker_advertises_memo_authoring_support(): void
    {
        $script = $this->read('scripts/smoke-workflow-memo-rolling-upgrade.sh');
        $this->assertGreaterThan(0, preg_match_all("/-d '([^']+)'/", $script, $matches));

        $registrations = array_values(array_filter(
            array_map(
                static fn (string $payload): mixed => json_decode($payload, true),
                $matches[1],
            ),
            static fn (mixed $payload): bool => is_array($payload)
                && ($payload['worker_id'] ?? null) === 'memo-rolling-worker'
                && ($payload['runtime'] ?? null) === 'php',
        ));

        $this->assertCount(1, $registrations);
        $this->assertContains('memo_upserts', $registrations[0]['capabilities'] ?? []);
    }

    public function test_mysql_rolling_evidence_interrupts_the_published_envelope_predecessor(): void
    {
        $script = $this->read('scripts/smoke-workflow-memo-rolling-upgrade.sh');

        $this->assertStringContainsString('durableworkflow/server:2.0.0-rc.48', $script);
        $this->assertStringContainsString('CREATE TRIGGER interrupt_published_memo_rewrite', $script);
        $this->assertStringContainsString("SELECT CONCAT(id, ':', SHA2(value, 256))", $script);
        $this->assertStringContainsString('raw_row_identities="$(mysql_memo_value_identities)"', $script);
        $this->assertStringContainsString('interrupted_row_identities="$(mysql_memo_value_identities)"', $script);
        $this->assertStringContainsString('changed != (row_id <= cutoff)', $script);
        $this->assertStringNotContainsString("grep -Fq 'bounded published predecessor interruption'", $script);
        $this->assertStringContainsString('workflow_memo_payload_migration_source_ambiguous', $script);
        $this->assertStringContainsString('envelope-prefix:$converted_cutoff', $script);
        $this->assertStringContainsString('COUNT(DISTINCT `key`)', $script);
        $this->assertStringContainsString('database: [mysql, pgsql]', $this->read(
            '.github/workflows/memo-rolling-upgrade.yml',
        ));
    }

    public function test_mysql_rolling_service_allows_the_scoped_interruption_trigger(): void
    {
        $compose = Yaml::parseFile(
            base_path('docker-compose.memo-rolling.yml'),
            Yaml::PARSE_CUSTOM_TAGS,
        );

        $this->assertSame(
            ['--log-bin-trust-function-creators=1'],
            $compose['services']['mysql']['command'] ?? null,
        );
        $this->assertArrayNotHasKey('command', $compose['services']['pgsql'] ?? []);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }
}
