<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseImageImmutabilityGuardTest extends TestCase
{
    public static function registryStates(): array
    {
        return [
            'initial publication' => ['absent', true],
            'Docker CLI explicit absence' => ['docker_absent', true],
            'queued duplicate' => ['published', false],
            'partial publication in second registry' => ['partial', false],
            'authentication failure' => ['unauthorized', false],
            'transport failure' => ['transport', false],
            'rate limit' => ['rate_limit', false],
            'unknown response containing absence words' => ['ambiguous', false],
        ];
    }

    #[DataProvider('registryStates')]
    public function test_only_confirmed_absence_admits_the_producer(string $state, bool $admitted): void
    {
        $directory = sys_get_temp_dir().'/release-immutability-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $stub = $directory.'/docker';
        file_put_contents($stub, <<<'SH'
#!/usr/bin/env sh
set -eu
test "$1" = manifest
test "$2" = inspect
case "$REGISTRY_STATE/$3" in
  published/*|partial/ghcr.io/*) printf '{"schemaVersion":2}\n'; exit 0 ;;
  unauthorized/*) echo 'unauthorized: authentication required' >&2 ;;
  transport/*) echo 'connection timed out' >&2 ;;
  rate_limit/*) echo '429 Too Many Requests' >&2 ;;
  ambiguous/*) echo 'unauthorized: manifest unknown for this user' >&2 ;;
  docker_absent/durableworkflow/*) echo "no such manifest: docker.io/$3" >&2 ;;
  docker_absent/*) echo "no such manifest: $3" >&2 ;;
  *) echo 'manifest unknown' >&2 ;;
esac
exit 1
SH);
        chmod($stub, 0700);
        $root = dirname(__DIR__, 2);
        $command = 'sh '.escapeshellarg($root.'/scripts/ci/guard-unpublished-release-images.sh')
            .' && printf admitted > '.escapeshellarg($directory.'/producer');
        try {
            $process = proc_open(['sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [
                'PATH' => getenv('PATH'),
                'DOCKER' => $stub,
                'RELEASE_TAG' => '2.8.5',
                'REGISTRY_STATE' => $state,
            ]);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            self::assertSame($admitted, file_exists($directory.'/producer'), $stdout.$stderr);
            self::assertSame($admitted ? 0 : 1, $status, $stdout.$stderr);
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_the_guard_runs_before_the_registry_write(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release.yml');
        $guard = strpos($workflow, 'run: sh scripts/ci/guard-unpublished-release-images.sh');
        $build = strpos($workflow, '- name: Build and push exact image tags');
        self::assertNotFalse($guard);
        self::assertNotFalse($build);
        self::assertLessThan($build, $guard);
    }
}
