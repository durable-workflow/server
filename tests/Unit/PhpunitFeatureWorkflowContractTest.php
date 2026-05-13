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

    public function test_phpunit_feature_runs_for_pull_requests_and_manual_dispatch(): void
    {
        foreach ([
            'pull_request:',
            'workflow_dispatch:',
            'name: PHPUnit feature suite',
            'vendor/bin/phpunit tests/Feature',
        ] as $needle) {
            $this->assertStringContainsString($needle, $this->workflow);
        }
    }

    public function test_workflow_package_checkout_is_credential_isolated_and_server_relative(): void
    {
        foreach ([
            "if: github.server_url == 'https://github.com'",
            "if: github.server_url != 'https://github.com'",
            'repository: durable-workflow/workflow',
            'repository: ${{ github.repository_owner }}/workflow',
            'token: ${{ secrets.CROSS_REPO_READ_TOKEN }}',
            'github-server-url: ${{ github.server_url }}',
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
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $this->workflow);
        }
    }

    public function test_untrusted_feature_suite_receives_only_checked_out_content(): void
    {
        foreach ([
            'persist-credentials: false',
            "--exclude=.git",
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
}
