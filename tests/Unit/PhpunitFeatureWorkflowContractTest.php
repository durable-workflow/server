<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PhpunitFeatureWorkflowContractTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/phpunit-feature.yml');
        $this->assertNotFalse($workflow, '.github/workflows/phpunit-feature.yml must be readable');

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
            "if: \${{ github.server_url == 'https://github.com' }}",
            'vendor/bin/phpunit tests/Feature',
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

    public function test_untrusted_feature_suite_receives_only_checked_out_content(): void
    {
        foreach ([
            'persist-credentials: false',
            '--exclude=.git',
            "--exclude='*/.git'",
            'docker run --rm -i',
        ] as $needle) {
            $this->assertStringContainsString($needle, $this->workflow);
        }

        foreach ([
            '-v "${PWD}:/app"',
            '-v "${PWD}/workflow-package:/workflow:ro"',
            'ACTIONS_RUNTIME_TOKEN',
            'ACTIONS_ID_TOKEN_REQUEST_TOKEN',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $this->workflow);
        }
    }

    public function test_local_candidates_use_a_bounded_structural_gate(): void
    {
        foreach ([
            'structural:',
            'name: Candidate structure',
            'timeout-minutes: 5',
            'python -m py_compile',
            'scripts/ci/test-component-release-recovery.py',
        ] as $needle) {
            $this->assertStringContainsString($needle, $this->workflow);
        }
    }

    public function test_final_gate_fails_safe_for_complete_and_structural_routes(): void
    {
        foreach ([
            'qualification:',
            'name: Feature source qualification',
            'needs: [structural, feature]',
            'if: ${{ always() }}',
            'test "$STRUCTURAL_RESULT" = success',
            '[[ "$ACTIONS_SERVER_URL" == "https://github.com" ]]',
            'test "$FEATURE_RESULT" = success',
            'test "$FEATURE_RESULT" = skipped',
        ] as $needle) {
            $this->assertStringContainsString($needle, $this->workflow);
        }
    }
}
