<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class PhpunitFeatureWorkflowContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $featureJob;

    /** @var array<string, mixed> */
    private array $parsedWorkflow;

    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/phpunit-feature.yml');
        $this->assertNotFalse($workflow, '.github/workflows/phpunit-feature.yml must be readable');
        $parsed = Yaml::parse($workflow);
        $this->assertIsArray($parsed);
        $featureJob = $parsed['jobs']['feature'] ?? null;
        $this->assertIsArray($featureJob);

        $this->featureJob = $featureJob;
        $this->parsedWorkflow = $parsed;
        $this->workflow = $workflow;
    }

    public function test_phpunit_feature_remains_complete_on_public_events(): void
    {
        foreach ([
            'push:',
            '- main',
            'pull_request:',
            'workflow_dispatch:',
            'name: PHPUnit feature suite',
            'if: ${{ always() }}',
            'vendor/bin/phpunit tests/Feature',
            'tests/Unit/NexusContractTest.php',
            'tests/Unit/PhpunitFeatureWorkflowContractTest.php',
        ] as $needle) {
            $this->assertStringContainsString($needle, $this->workflow);
        }
    }

    public function test_workflow_package_checkout_is_credential_isolated(): void
    {
        foreach ([
            'repository: durable-workflow/workflow',
            'persist-credentials: false',
            'rm -rf workflow-package/.git',
        ] as $needle) {
            $this->assertStringContainsString($needle, $this->workflow);
        }

        foreach ([
            'GIT_TERMINAL_PROMPT',
            'credential.helper',
            'core.askPass',
            'clone --depth=1',
            'CROSS_REPO_READ_TOKEN',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $this->workflow);
        }
    }

    public function test_feature_ci_receives_a_credential_free_complete_checkout(): void
    {
        foreach ([
            'persist-credentials: false',
            'fetch-depth: 0',
            'tar --no-same-owner -xf - -C /app',
            'docker run --rm -i',
        ] as $needle) {
            $this->assertStringContainsString($needle, $this->workflow);
        }

        foreach ([
            '-v "${PWD}:/app"',
            '-v "${PWD}/workflow-package:/workflow:ro"',
            'ACTIONS_RUNTIME_TOKEN',
            'ACTIONS_ID_TOKEN_REQUEST_TOKEN',
            '--exclude=.git',
            "--exclude='*/.git'",
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $this->workflow);
        }
    }

    public function test_regression_corpus_validation_uses_git_capable_ci_and_an_exact_base(): void
    {
        $checkout = $this->step('Checkout server');
        $execution = $this->step('Run feature, Nexus, and regression corpus suites');
        $manualInput = $this->parsedWorkflow['on']['workflow_dispatch']['inputs']['corpus_base_ref'] ?? null;

        $this->assertIsArray($manualInput);
        $this->assertTrue($manualInput['required'] ?? false);
        $this->assertSame('string', $manualInput['type'] ?? null);
        $this->assertSame(0, $checkout['with']['fetch-depth'] ?? null);
        $this->assertFalse($checkout['with']['persist-credentials'] ?? null);
        $this->assertSame(
            '${{ github.event_name == \'pull_request\' && github.event.pull_request.base.sha || github.event_name == \'merge_group\' && github.event.merge_group.base_sha || github.event_name == \'push\' && github.event.before || inputs.corpus_base_ref }}',
            $execution['env']['CORPUS_BASE_REF'] ?? null,
        );
        $this->assertArrayNotHasKey('if', $execution);
        $this->assertArrayNotHasKey('continue-on-error', $execution);

        $run = $execution['run'] ?? '';
        foreach ([
            'if [[ ! "$CORPUS_BASE_REF" =~ ^[0-9a-f]{40}$ ]] || [[ "$CORPUS_BASE_REF" =~ ^0+$ ]]',
            'exit 1',
            'docker run --rm -i',
            '-e CORPUS_BASE_REF',
            'tar --no-same-owner -xf - -C /app',
            'composer install --no-interaction --no-progress --prefer-dist',
            'git --version',
            'vendor/bin/phpunit --version',
            'git rev-parse --verify "$CORPUS_BASE_REF^{commit}"',
            'python3 scripts/ci/validate-regression-corpus.py',
            '--base-ref "$CORPUS_BASE_REF"',
            '--verify-counterfactual',
        ] as $needle) {
            $this->assertStringContainsString($needle, $run);
        }

        $this->assertSame(1, substr_count($run, 'composer install --no-interaction --no-progress --prefer-dist'));
        $this->assertSame(1, substr_count($run, 'docker run --rm -i'));
        $this->assertStringNotContainsString('--exclude=.git', $run);
        $this->assertStringNotContainsString("--exclude='*/.git'", $run);
        $this->assertStringNotContainsString('arguments+=(--base-ref', $this->workflow);
        $this->assertStringNotContainsString('then python3 scripts/ci/validate-regression-corpus.py', $this->workflow);

        $composerOffset = strpos($run, 'composer install --no-interaction');
        $gitOffset = strpos($run, 'git --version');
        $phpunitOffset = strpos($run, 'vendor/bin/phpunit tests/Feature');
        $validatorOffset = strpos($run, 'python3 scripts/ci/validate-regression-corpus.py');
        $this->assertIsInt($composerOffset);
        $this->assertIsInt($gitOffset);
        $this->assertIsInt($phpunitOffset);
        $this->assertIsInt($validatorOffset);
        $this->assertLessThan($phpunitOffset, $composerOffset);
        $this->assertLessThan($validatorOffset, $gitOffset);
        $this->assertLessThan($validatorOffset, $phpunitOffset);
    }

    public function test_php_tools_cache_preserves_trust_and_fresh_source_qualification(): void
    {
        $key = $this->step('Key the source-free PHP tool images');
        $restore = $this->step('Restore the source-free PHP tool images');
        $prepare = $this->step('Prepare the source-free PHP tool images');
        $save = $this->step('Save qualified source-free PHP tools from main');
        $execution = $this->step('Run feature, Nexus, and regression corpus suites');

        $this->assertStringContainsString('$(date -u +%Y%m%d)', $key['run']);
        $this->assertStringContainsString("hashFiles('Dockerfile', 'Dockerfile.dockerignore', '.dockerignore', 'docker/**')", $key['run']);
        $this->assertStringContainsString('${{ runner.os }}-${{ runner.arch }}', $key['run']);
        $this->assertSame('${{ steps.php_tools_key.outputs.key }}', $restore['with']['key']);
        $this->assertSame($restore['with'], $save['with']);
        $this->assertSame('${{ runner.temp }}/server-php-tools.tar', $restore['with']['path']);
        $this->assertArrayNotHasKey('restore-keys', $restore['with']);
        $this->assertSame("\${{ success() && github.event_name == 'push' && github.ref == 'refs/heads/main' && steps.php_tools_cache.outputs.cache-hit != 'true' }}", $save['if']);

        foreach (['docker load --input', 'docker build --target base -t durable-workflow-server-phpunit-base .',
            'FROM durable-workflow-server-phpunit-base', 'apt-get install -y --no-install-recommends git',
            'docker save --output', 'server-verify-trust durable-workflow-server-phpunit-base',
            'server-verify-trust durable-workflow-server-corpus-validator final',
            'server-verify-brotli durable-workflow-server-corpus-validator'] as $required) {
            $this->assertStringContainsString($required, $prepare['run']);
        }
        $this->assertLessThan(strpos($prepare['run'], 'server-verify-trust durable-workflow-server-phpunit-base'), strpos($prepare['run'], "\nfi\n"));
        $this->assertArrayNotHasKey('if', $prepare);
        $this->assertArrayNotHasKey('if', $execution);
        $this->assertStringNotContainsString('cache-hit', $execution['run']);
        $this->assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist', $execution['run']);
        $this->assertStringContainsString('vendor/bin/phpunit tests/Feature', $execution['run']);
        $this->assertStringContainsString('server-verify-trust durable-workflow-server-corpus-validator final', $execution['run']);
        $names = array_column($this->featureJob['steps'], 'name');
        $this->assertLessThan(array_search('Checkout workflow package from GitHub', $names, true),
            array_search('Save qualified source-free PHP tools from main', $names, true));
    }

    public function test_preflight_validates_release_metadata_and_corpus_tools(): void
    {
        foreach ([
            'preflight:',
            'name: Repository contracts',
            'timeout-minutes: 5',
            'node scripts/ci/sync-source-release.mjs --check',
            'python scripts/ci/test-regression-corpus-policy.py',
            'python3 -m unittest tests/Unit/Support/patched_pip_test.py',
            'python3 -m unittest tests/Unit/Support/cargo_trust_test.py',
            'python3 -m unittest tests/Unit/Support/cargo_lock_trust_test.py',
            'python3 -m unittest tests/Unit/Support/composer_trust_test.py',
        ] as $needle) {
            $this->assertStringContainsString($needle, $this->workflow);
        }
    }

    public function test_final_gate_requires_preflight_and_feature_suite(): void
    {
        foreach ([
            'qualification:',
            'name: Feature source qualification',
            'needs: [preflight, feature]',
            'if: ${{ always() }}',
            'test "$PREFLIGHT_RESULT" = success',
            'test "$FEATURE_RESULT" = success',
        ] as $needle) {
            $this->assertStringContainsString($needle, $this->workflow);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $name): array
    {
        $step = $this->featureJob['steps'][$this->stepIndex($name)] ?? null;
        $this->assertIsArray($step);

        return $step;
    }

    private function stepIndex(string $name): int
    {
        foreach ($this->featureJob['steps'] ?? [] as $index => $step) {
            if (is_array($step) && ($step['name'] ?? null) === $name) {
                return $index;
            }
        }

        $this->fail("Workflow step {$name} is missing.");
    }
}
