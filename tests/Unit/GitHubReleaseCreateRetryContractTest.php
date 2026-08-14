<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class GitHubReleaseCreateRetryContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function test_transient_server_errors_retry_release_creation_until_success(): void
    {
        $result = $this->runPublisher('transient-then-success');

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertCount(3, $this->commandsMatching($result['commands'], 'release create '));
        $this->assertCount(3, $this->commandsMatching($result['commands'], 'release view '));
        $this->assertStringContainsString('HTTP 500: Internal Server Error', $result['stderr']);

        foreach ($this->commandsMatching($result['commands'], 'release create ') as $command) {
            $this->assertSame(
                'release create 2.0.0-rc.33 --verify-tag --generate-notes --title 2.0.0-rc.33 --prerelease',
                $command,
            );
        }
    }

    public function test_transient_create_accepts_release_created_before_retry(): void
    {
        $result = $this->runPublisher('created-after-transient');

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertCount(1, $this->commandsMatching($result['commands'], 'release create '));
        $this->assertCount(2, $this->commandsMatching($result['commands'], 'release view '));
        $this->assertStringContainsString('already exists', $result['stdout']);
    }

    public function test_rate_limit_response_is_retried(): void
    {
        $result = $this->runPublisher('rate-limit-then-success');

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertCount(2, $this->commandsMatching($result['commands'], 'release create '));
        $this->assertCount(2, $this->commandsMatching($result['commands'], 'release view '));
        $this->assertStringContainsString('HTTP 429: API rate limit exceeded', $result['stderr']);
    }

    public function test_transient_retries_stop_at_the_configured_bound(): void
    {
        $result = $this->runPublisher('transient-always');

        $this->assertNotSame(0, $result['exitCode']);
        $this->assertCount(4, $this->commandsMatching($result['commands'], 'release create '));
        $this->assertCount(4, $this->commandsMatching($result['commands'], 'release view '));
        $this->assertStringContainsString('persisted for 4 attempts', $result['stderr']);
    }

    public function test_authentication_authorization_validation_and_tag_failures_are_terminal(): void
    {
        foreach ([
            'HTTP 401: Bad credentials',
            'HTTP 403: Resource not accessible by integration',
            'HTTP 422: Validation Failed',
            'tag 2.0.0-rc.33 is not published',
        ] as $terminalError) {
            $result = $this->runPublisher('terminal', $terminalError);

            $this->assertNotSame(0, $result['exitCode'], $terminalError);
            $this->assertCount(
                1,
                $this->commandsMatching($result['commands'], 'release create '),
                $terminalError,
            );
            $this->assertCount(
                1,
                $this->commandsMatching($result['commands'], 'release view '),
                $terminalError,
            );
        }
    }

    public function test_release_workflow_runs_the_retrying_publisher_after_image_provenance_checks(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot.'/.github/workflows/release.yml');

        $buildOffset = strpos($workflow, 'Build and push exact image tags');
        $exactOffset = strpos($workflow, 'Verify exact image publication');
        $releaseOffset = strpos($workflow, 'scripts/ci/create-github-release.sh');

        $this->assertIsInt($buildOffset);
        $this->assertIsInt($exactOffset);
        $this->assertIsInt($releaseOffset);
        $this->assertLessThan($exactOffset, $buildOffset);
        $this->assertLessThan($releaseOffset, $exactOffset);
        $this->assertSame(1, substr_count($workflow, 'Build and push exact image tags'));
        $this->assertStringContainsString(
            'RELEASE_TAG: ${{ steps.release_publish.outputs.tag }}',
            $workflow,
        );
    }

    /**
     * @return array{exitCode:int, stdout:string, stderr:string, commands:list<string>}
     */
    private function runPublisher(string $scenario, string $terminalError = ''): array
    {
        $tmpDir = sys_get_temp_dir().'/github-release-create-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $fakeGh = $tmpDir.'/gh';
        $logFile = $tmpDir.'/gh.log';
        $stateFile = $tmpDir.'/create-count';

        file_put_contents($fakeGh, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$*" >> "$FAKE_GH_LOG"
create_count="$(cat "$FAKE_GH_STATE" 2>/dev/null || printf '0')"

if [ "${1:-}" = release ] && [ "${2:-}" = view ]; then
    if [ "$FAKE_GH_SCENARIO" = created-after-transient ] && [ "$create_count" -ge 1 ]; then
        printf '{"tagName":"%s"}\n' "${3:-}"
        exit 0
    fi

    printf 'release not found\n' >&2
    exit 1
fi

if [ "${1:-}" = release ] && [ "${2:-}" = create ]; then
    create_count="$((create_count + 1))"
    printf '%s\n' "$create_count" > "$FAKE_GH_STATE"

    case "$FAKE_GH_SCENARIO" in
        transient-then-success)
            if [ "$create_count" -le 2 ]; then
                printf 'HTTP 500: Internal Server Error\n' >&2
                exit 1
            fi
            printf 'https://github.com/durable-workflow/server/releases/tag/%s\n' "${3:-}"
            exit 0
            ;;
        rate-limit-then-success)
            if [ "$create_count" -eq 1 ]; then
                printf 'HTTP 429: API rate limit exceeded\n' >&2
                exit 1
            fi
            printf 'https://github.com/durable-workflow/server/releases/tag/%s\n' "${3:-}"
            exit 0
            ;;
        transient-always)
            printf 'HTTP 500: Internal Server Error\n' >&2
            exit 1
            ;;
        created-after-transient)
            printf 'HTTP 500: Internal Server Error\n' >&2
            exit 1
            ;;
        terminal)
            printf '%s\n' "$FAKE_GH_TERMINAL_ERROR" >&2
            exit 1
            ;;
    esac
fi

printf 'unexpected gh invocation: %s\n' "$*" >&2
exit 2
SH);
        $this->assertTrue(chmod($fakeGh, 0755));

        try {
            $result = $this->runScript('scripts/ci/create-github-release.sh', [
                'GH_CLI' => $fakeGh,
                'RELEASE_TAG' => '2.0.0-rc.33',
                'GITHUB_RELEASE_MAX_ATTEMPTS' => '4',
                'GITHUB_RELEASE_RETRY_INITIAL_DELAY_SECONDS' => '0',
                'GITHUB_RELEASE_RETRY_MAX_DELAY_SECONDS' => '0',
                'FAKE_GH_SCENARIO' => $scenario,
                'FAKE_GH_TERMINAL_ERROR' => $terminalError,
                'FAKE_GH_LOG' => $logFile,
                'FAKE_GH_STATE' => $stateFile,
            ]);

            $commands = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            return $result + ['commands' => $commands === false ? [] : $commands];
        } finally {
            @unlink($fakeGh);
            @unlink($logFile);
            @unlink($stateFile);
            @rmdir($tmpDir);
        }
    }

    /**
     * @param  list<string>  $commands
     * @return list<string>
     */
    private function commandsMatching(array $commands, string $prefix): array
    {
        return array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_starts_with($command, $prefix),
        ));
    }

    /**
     * @param  array<string, string>  $env
     * @return array{exitCode:int, stdout:string, stderr:string}
     */
    private function runScript(string $path, array $env): array
    {
        $process = proc_open(
            $this->repoRoot.'/'.$path,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repoRoot,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'] + $env,
        );

        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }
}
