<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ReleaseImageContinuationTest extends TestCase
{
    private string $directory;

    private const SOURCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const CORE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const DIGEST = 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private const AMD64 = 'sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private const ARM64 = 'sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/release-continuation-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/docker', <<<'SH'
#!/usr/bin/env sh
set -eu
printf '%s\n' "$*" >> "$CALL_LOG"
if [ "$1" = pull ]; then
    counter="$CALL_LOG-${DOCKER_DEFAULT_PLATFORM##*/}"
    count=0
    if [ -f "$counter" ]; then count=$(cat "$counter"); fi
    count=$((count + 1))
    printf '%s\n' "$count" > "$counter"
    if [ "$STATE" = pull_permanent ] || { [ "$STATE" = pull_transient ] && [ "$count" -lt 3 ]; }; then
        echo '500 Internal Server Error' >&2
        exit 1
    fi
    store="$CALL_LOG-store-${3##*:}"
    if [ -f "$store" ] && [ "$(cat "$store")" != "$DOCKER_DEFAULT_PLATFORM" ]; then
        echo "cannot overwrite digest ${3##*@}" >&2
        exit 1
    fi
    printf '%s\n' "$DOCKER_DEFAULT_PLATFORM" > "$store"
    exit 0
fi
if [ "$1" = run ]; then
    if [ "$STATE" = trust_failure ]; then echo 'trust assertion failed' >&2; exit 1; fi
    exit 0
fi
if [ "$1" = manifest ] && [ "$2" = inspect ]; then
    printf '{"schemaVersion":2}\n'
    exit 0
fi
test "$1" = buildx
test "$2" = imagetools
if [ "$3" = create ]; then exit 0; fi
test "$3" = inspect
if [ "$4" = --raw ]; then
    test "$5" = "durableworkflow/server@$INDEX_DIGEST"
    printf '%s\n' "$INDEX_JSON"
    exit 0
fi
if [ "$4" = --format ]; then
    if [ "$STATE" = wrong_arm_config ]; then
        case "$5" in *linux/arm64*) printf '%s\n' "$BAD_METADATA"; exit 0 ;; esac
    fi
    printf '%s\n' "$METADATA"
else
    if [ "$STATE" = missing ]; then echo 'manifest unknown' >&2; exit 1; fi
    digest="$INDEX_DIGEST"
    if [ "$STATE" = registry_mismatch ]; then
        case "$4" in ghcr.io/*) digest=sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd ;; esac
    fi
    printf 'Digest: %s\nPlatform: linux/amd64\n' "$digest"
    if [ "$STATE" != missing_arm ]; then printf 'Platform: linux/arm64\n'; fi
fi
SH);
        chmod($this->directory.'/docker', 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public static function invalidContinuationInputs(): array
    {
        return [
            'tag push' => [['GITHUB_EVENT_NAME' => 'push', 'GITHUB_REF' => 'refs/tags/2.11.6']],
            'feature branch' => [['GITHUB_REF' => 'refs/heads/feature']],
            'missing source' => [['INPUT_RELEASE_COMMIT' => '']],
            'abbreviated source' => [['INPUT_RELEASE_COMMIT' => 'aaaaaaaa']],
            'non-digest' => [['INPUT_EXISTING_IMAGE_DIGEST' => 'latest']],
            'digest suffix' => [['INPUT_EXISTING_IMAGE_DIGEST' => self::DIGEST.'-extra']],
        ];
    }

    #[DataProvider('invalidContinuationInputs')]
    public function test_invalid_continuation_cannot_admit_a_producer(array $overrides): void
    {
        $result = $this->runScript('validate-release-image-publish.sh', $this->context($overrides));
        self::assertSame(1, $result['status'], $result['text']);
        self::assertStringNotContainsString('verification_only=true', $result['output']);
        self::assertSame('', $result['calls']);
    }

    public function test_explicit_continuation_selects_verification_without_a_new_run_identity(): void
    {
        $context = $this->runScript('validate-release-image-publish.sh', $this->context());
        self::assertSame(0, $context['status'], $context['text']);
        self::assertStringContainsString('verification_only=true', $context['output']);
        self::assertStringContainsString('existing_image_digest='.self::DIGEST, $context['output']);

        $verified = $this->runScript('verify-release-exact-images.sh', $this->verification());
        self::assertSame(0, $verified['status'], $verified['text']);
        self::assertStringContainsString('exact_publish_outcome=success', $verified['output']);
        self::assertStringContainsString('image_digest='.self::DIGEST, $verified['output']);
        self::assertSame(2, substr_count($verified['calls'], '--format {{json (index .Image "linux/amd64")}}'));
        self::assertSame(2, substr_count($verified['calls'], '--format {{json (index .Image "linux/arm64")}}'));
        self::assertStringNotContainsString('imagetools create', $verified['calls']);
    }

    public function test_no_digest_preserves_new_release_and_existing_image_refusal(): void
    {
        $context = $this->runScript('validate-release-image-publish.sh', $this->context([
            'INPUT_EXISTING_IMAGE_DIGEST' => '',
        ]));
        self::assertSame(0, $context['status'], $context['text']);
        self::assertStringContainsString('verification_only=false', $context['output']);
        $guard = $this->runScript('guard-unpublished-release-images.sh', [
            'RELEASE_TAG' => '2.11.6',
        ]);
        self::assertSame(1, $guard['status'], $guard['text']);
        self::assertStringNotContainsString('imagetools create', $guard['calls']);
    }

    public static function invalidExistingImages(): array
    {
        return [
            'unexpected digest' => [['INDEX_DIGEST' => 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd']],
            'registries disagree' => [['STATE' => 'registry_mismatch']],
            'missing arm64' => [['STATE' => 'missing_arm']],
            'wrong arm64 source' => [['STATE' => 'wrong_arm_config']],
            'wrong tag' => [['RELEASE_TAG' => '2.11.7']],
            'wrong source' => [['RELEASE_COMMIT' => 'dddddddddddddddddddddddddddddddddddddddd']],
            'wrong Core commit' => [['WORKFLOW_PACKAGE_COMMIT' => 'dddddddddddddddddddddddddddddddddddddddd']],
            'new consumer run identity' => [['RELEASE_RUN_ID' => 'new-verification-run']],
            'missing image' => [['STATE' => 'missing']],
            'producer ran' => [['DOCKER_BUILD_OUTCOME' => 'success']],
            'new build digest' => [['BUILT_IMAGE_DIGEST' => self::DIGEST]],
            'incomplete Core authority' => [['WORKFLOW_PACKAGE_COMMIT' => '']],
            'missing full source' => [['RELEASE_COMMIT' => '']],
            'normal mode with continuation digest' => [['RELEASE_IMAGE_VERIFICATION_ONLY' => 'false']],
        ];
    }

    #[DataProvider('invalidExistingImages')]
    public function test_existing_image_failures_stop_before_any_registry_write(array $overrides): void
    {
        $result = $this->runScript('verify-release-exact-images.sh', $this->verification($overrides));
        self::assertSame(1, $result['status'], $result['text']);
        self::assertStringContainsString('exact_publish_outcome=failure', $result['output']);
        self::assertStringNotContainsString('imagetools create', $result['calls']);
    }

    public function test_rolling_aliases_use_verified_bytes_and_never_write_the_exact_tag(): void
    {
        $result = $this->runScript('promote-release-image-rolling-tags.sh', [
            'RELEASE_TAG' => '2.11.6', 'VERIFIED_IMAGE_DIGEST' => self::DIGEST,
            'RELEASE_IMAGE_KNOWN_TAGS' => "2.11.5\n2.11.6",
        ]);
        self::assertSame(0, $result['status'], $result['text']);
        self::assertSame(2, substr_count($result['calls'], 'imagetools create'));
        foreach (['durableworkflow/server', 'ghcr.io/durable-workflow/server'] as $image) {
            self::assertStringContainsString($image.'@'.self::DIGEST, $result['calls']);
            self::assertStringNotContainsString('--tag '.$image.':2.11.6', $result['calls']);
        }
    }

    public function test_a_native_final_release_keeps_latest_from_a_later_php_release(): void
    {
        $result = $this->runScript('promote-release-image-rolling-tags.sh', [
            'RELEASE_TAG' => '2.11.9', 'VERIFIED_IMAGE_DIGEST' => self::DIGEST,
            'RELEASE_IMAGE_KNOWN_TAGS' => "2.11.8\n2.11.9\nnative-3.0.0-rc.2\nnative-3.0.0",
        ]);
        self::assertSame(0, $result['status'], $result['text']);
        self::assertStringContainsString('latest_alias=false', $result['output']);
        foreach (['durableworkflow/server', 'ghcr.io/durable-workflow/server'] as $image) {
            self::assertStringContainsString('--tag '.$image.':2.11', $result['calls']);
            self::assertStringContainsString('--tag '.$image.':2 ', $result['calls']);
            self::assertStringNotContainsString($image.':latest', $result['calls']);
        }

        $candidate = $this->runScript('promote-release-image-rolling-tags.sh', [
            'RELEASE_TAG' => '2.11.9', 'VERIFIED_IMAGE_DIGEST' => self::DIGEST,
            'RELEASE_IMAGE_KNOWN_TAGS' => "2.11.8\n2.11.9\nnative-3.0.0-rc.2",
        ]);
        self::assertSame(0, $candidate['status'], $candidate['text']);
        self::assertStringContainsString('latest_alias=true', $candidate['output']);
        self::assertSame(2, substr_count($candidate['calls'], ':latest'));
    }

    public function test_invalid_alias_digest_stops_before_registry_writes(): void
    {
        $result = $this->runScript('promote-release-image-rolling-tags.sh', [
            'RELEASE_TAG' => '2.11.6', 'VERIFIED_IMAGE_DIGEST' => 'latest',
        ]);
        self::assertSame(1, $result['status'], $result['text']);
        self::assertSame('', $result['calls']);
    }

    public function test_tag_push_preserves_the_normal_new_release_mode(): void
    {
        $result = $this->runScript('validate-release-image-publish.sh', $this->context([
            'GITHUB_EVENT_NAME' => 'push', 'GITHUB_REF' => 'refs/tags/2.11.6',
            'INPUT_EXISTING_IMAGE_DIGEST' => '',
        ]));
        self::assertSame(0, $result['status'], $result['text']);
        self::assertStringContainsString('verification_only=false', $result['output']);
    }

    public function test_existing_released_artifact_verification_remains_compatible(): void
    {
        $result = $this->runScript('verify-release-exact-images.sh', $this->verification([
            'EXPECTED_IMAGE_DIGEST' => '', 'WORKFLOW_PACKAGE_SOURCE' => '',
            'WORKFLOW_PACKAGE_REF' => '', 'WORKFLOW_PACKAGE_COMMIT' => '',
        ]));
        self::assertSame(0, $result['status'], $result['text']);
        self::assertStringContainsString('image_digest='.self::DIGEST, $result['output']);
    }

    public function test_workflow_wires_continuation_to_all_normal_verification_gates(): void
    {
        $workflow = Yaml::parse(file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release.yml'));
        self::assertArrayHasKey('existing_image_digest', $workflow['on']['workflow_dispatch']['inputs']);
        self::assertFalse($workflow['on']['workflow_dispatch']['inputs']['existing_image_digest']['required']);
        $job = $workflow['jobs']['publish'];
        self::assertSame('dockerhub', $job['environment']);
        self::assertSame(['contents' => 'write', 'packages' => 'write'], $job['permissions']);
        $steps = array_column($job['steps'], null, 'name');
        foreach (['Resolve shared release image cache', 'Refuse replacement of published release images',
            'Start exact image build timer', 'Build and push exact image tags'] as $producer) {
            self::assertSame("\${{ steps.release_publish.outputs.verification_only != 'true' }}", $steps[$producer]['if']);
        }
        foreach (['Verify published image trust stores' => 'RELEASE_IMAGE',
            'Verify bare image first-run readiness' => 'DW_SERVER_IMAGE',
            'Verify source-free Compose bootstrap' => 'DW_SERVER_IMAGE',
            'Verify published image database TLS and certificate refusals' => 'TLS_TEST_IMAGE',
            'Verify published protocol catalog convergence' => 'SERVER_IMAGE'] as $name => $image) {
            self::assertStringContainsString('@${{ steps.exact.outputs.image_digest }}', $steps[$name]['env'][$image]);
            self::assertStringContainsString('success()', $steps[$name]['if']);
            self::assertArrayNotHasKey('continue-on-error', $steps[$name]);
        }
        self::assertSame('${{ steps.release_publish.outputs.existing_image_digest }}', $steps['Verify exact image publication']['env']['EXPECTED_IMAGE_DIGEST']);
        self::assertSame('${{ steps.exact.outputs.image_digest }}', $steps['Promote rolling image aliases']['env']['VERIFIED_IMAGE_DIGEST']);
        $names = array_keys($steps);
        $guard = $steps['Recheck immutable release identities before promotion'];
        self::assertLessThan(array_search('Promote rolling image aliases', $names, true), array_search('Recheck immutable release identities before promotion', $names, true));
        self::assertArrayNotHasKey('if', $guard);
        self::assertSame('${{ steps.exact.outputs.image_digest }}', $guard['env']['EXPECTED_IMAGE_DIGEST']);
        self::assertStringContainsString('scripts/ci/verify-release-tag-source.sh', $guard['run']);
        self::assertStringContainsString('scripts/ci/verify-release-exact-images.sh', $guard['run']);
        self::assertStringContainsString("needs.publish.result == 'success'", $workflow['jobs']['publish-chart']['if']);
    }

    public static function trustPullOutcomes(): array
    {
        return [
            'transient pulls recover on both platforms' => ['pull_transient', 0, 6, 2],
            'permanent pull failure stops without trust execution' => ['pull_permanent', 1, 3, 0],
            'trust content failure is never retried' => ['trust_failure', 1, 1, 1],
        ];
    }

    #[DataProvider('trustPullOutcomes')]
    public function test_only_pulls_are_retried_and_trust_uses_the_cached_digest(string $state, int $status, int $pulls, int $runs): void
    {
        $result = $this->runTrustStep(['STATE' => $state]);
        self::assertSame($status, $result['status'], $result['text']);
        self::assertSame($pulls, substr_count($result['calls'], 'pull --quiet'));
        self::assertSame($runs, substr_count($result['calls'], 'run --rm'));
        if ($runs > 0) {
            self::assertSame($runs, substr_count($result['calls'], '--pull never'));
            self::assertSame($runs, substr_count($result['calls'], '--network none'));
        }
        if ($status === 0) {
            self::assertSame(3, (int) file_get_contents($this->directory.'/calls-amd64'));
            self::assertSame(3, (int) file_get_contents($this->directory.'/calls-arm64'));
        }
    }

    public function test_mock_reproduces_the_same_index_cross_platform_overwrite(): void
    {
        $result = $this->runProcess(['bash', '-c', <<<'SH'
set -e
DOCKER_DEFAULT_PLATFORM=linux/amd64 docker pull --quiet "$RELEASE_IMAGE"
DOCKER_DEFAULT_PLATFORM=linux/arm64 docker pull --quiet "$RELEASE_IMAGE"
SH], ['RELEASE_IMAGE' => 'durableworkflow/server@'.self::DIGEST]);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('cannot overwrite digest '.self::DIGEST, $result['text']);
    }

    public function test_trust_runs_both_verified_children_without_overwriting_the_local_index(): void
    {
        $result = $this->runTrustStep();
        self::assertSame(0, $result['status'], $result['text']);
        self::assertStringContainsString('buildx imagetools inspect --raw durableworkflow/server@'.self::DIGEST, $result['calls']);
        foreach (['linux/amd64' => self::AMD64, 'linux/arm64' => self::ARM64] as $platform => $digest) {
            self::assertStringContainsString('pull --quiet durableworkflow/server@'.$digest, $result['calls']);
            self::assertStringContainsString('run --rm --pull never --platform '.$platform.' --network none --entrypoint server-verify-trust durableworkflow/server@'.$digest, $result['calls']);
        }
        self::assertStringNotContainsString('pull --quiet durableworkflow/server@'.self::DIGEST, $result['calls']);
        self::assertSame(2, substr_count($result['calls'], 'pull --quiet'));
        self::assertSame(2, substr_count($result['calls'], 'run --rm'));
    }

    public static function invalidTrustIndexes(): iterable
    {
        $amd64 = ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => self::AMD64,
            'platform' => ['os' => 'linux', 'architecture' => 'amd64']];
        $arm64 = ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => self::ARM64,
            'platform' => ['os' => 'linux', 'architecture' => 'arm64']];
        yield 'missing amd64' => [[$arm64]];
        yield 'missing arm64' => [[$amd64]];
        yield 'wrong operating system' => [[$amd64, array_replace($arm64, ['platform' => ['os' => 'windows', 'architecture' => 'arm64']])]];
        yield 'wrong architecture' => [[$amd64, array_replace($arm64, ['platform' => ['os' => 'linux', 'architecture' => 's390x']])]];
        yield 'foreign attestation is not an arm64 child' => [[$amd64, array_replace($arm64, ['platform' => ['os' => 'unknown', 'architecture' => 'unknown']])]];
        yield 'ambiguous platform' => [[$amd64, $arm64, $arm64]];
        yield 'invalid child digest' => [[$amd64, array_replace($arm64, ['digest' => 'latest'])]];
        yield 'nested index is not a platform image' => [[$amd64, array_replace($arm64, ['mediaType' => 'application/vnd.oci.image.index.v1+json'])]];
    }

    #[DataProvider('invalidTrustIndexes')]
    public function test_unverified_platform_selection_fails_before_any_pull_or_trust(array $manifests): void
    {
        $result = $this->runTrustStep(['INDEX_JSON' => json_encode(['manifests' => $manifests], JSON_THROW_ON_ERROR)]);
        self::assertNotSame(0, $result['status']);
        self::assertStringNotContainsString('pull --quiet', $result['calls']);
        self::assertStringNotContainsString('run --rm', $result['calls']);
    }

    public function test_mutable_trust_reference_fails_before_registry_or_container_access(): void
    {
        $result = $this->runTrustStep(['RELEASE_IMAGE' => 'durableworkflow/server:latest']);
        self::assertSame(1, $result['status']);
        self::assertSame('', $result['calls']);
    }

    private function runTrustStep(array $environment = []): array
    {
        $workflow = Yaml::parse(file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release.yml'));
        $steps = array_column($workflow['jobs']['publish']['steps'], null, 'name');
        $step = $steps['Verify published image trust stores'];
        $script = $this->directory.'/trust.sh';
        file_put_contents($script, $step['run']);

        return $this->runProcess(['bash', $script], array_replace([
            'RELEASE_IMAGE_PLATFORMS' => 'linux/amd64,linux/arm64',
            'RELEASE_IMAGE' => 'durableworkflow/server@'.self::DIGEST,
            'DW_PULL_ATTEMPTS' => $step['env']['DW_PULL_ATTEMPTS'] ?? '3',
            'DW_PULL_BACKOFF_SECONDS' => '0',
        ], $environment));
    }

    private function context(array $overrides = []): array
    {
        return array_replace([
            'GITHUB_EVENT_NAME' => 'workflow_dispatch', 'GITHUB_REF' => 'refs/heads/main',
            'INPUT_TAG' => '2.11.6', 'SOURCE_RELEASE_VERSION' => '2.11.6',
            'INPUT_RELEASE_COMMIT' => self::SOURCE, 'INPUT_EXISTING_IMAGE_DIGEST' => self::DIGEST,
            'DOCKERHUB_USERNAME' => 'fixture-user', 'DOCKERHUB_TOKEN' => 'fixture-token',
            'GHCR_TOKEN' => 'fixture-token',
        ], $overrides);
    }

    private function verification(array $overrides = []): array
    {
        return array_replace([
            'RELEASE_TAG' => '2.11.6', 'RELEASE_COMMIT' => self::SOURCE,
            'RELEASE_IMAGE_VERIFICATION_ONLY' => 'true', 'EXPECTED_IMAGE_DIGEST' => self::DIGEST,
            'DOCKER_BUILD_OUTCOME' => 'skipped',
            'WORKFLOW_PACKAGE_SOURCE' => 'https://github.com/durable-workflow/workflow.git',
            'WORKFLOW_PACKAGE_REF' => '2.15.7', 'WORKFLOW_PACKAGE_COMMIT' => self::CORE,
        ], $overrides);
    }

    private function runScript(string $script, array $environment): array
    {
        return $this->runProcess(['sh', dirname(__DIR__, 2).'/scripts/ci/'.$script], $environment);
    }

    private function runProcess(array $command, array $environment): array
    {
        $labels = [
            'org.opencontainers.image.revision' => self::SOURCE, 'dev.durable-workflow.release.tag' => '2.11.6',
            'dev.durable-workflow.release.run-id' => 'original-publisher', 'dev.durable-workflow.release.run-attempt' => '1',
            'dev.durable-workflow.workflow.source' => 'https://github.com/durable-workflow/workflow.git',
            'dev.durable-workflow.workflow.version' => '2.15.7', 'dev.durable-workflow.workflow.commit' => self::CORE,
        ];
        $badLabels = array_replace($labels, ['org.opencontainers.image.revision' => str_repeat('d', 40)]);
        file_put_contents($this->directory.'/output', '');
        file_put_contents($this->directory.'/calls', '');
        $environment = array_replace([
            'PATH' => $this->directory.':'.getenv('PATH'), 'DOCKER' => $this->directory.'/docker',
            'GITHUB_OUTPUT' => $this->directory.'/output', 'CALL_LOG' => $this->directory.'/calls',
            'STATE' => 'valid', 'INDEX_DIGEST' => self::DIGEST,
            'INDEX_JSON' => json_encode(['schemaVersion' => 2, 'manifests' => [
                ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => self::AMD64,
                    'platform' => ['os' => 'linux', 'architecture' => 'amd64']],
                ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => self::ARM64,
                    'platform' => ['os' => 'linux', 'architecture' => 'arm64']],
            ]], JSON_THROW_ON_ERROR),
            'METADATA' => json_encode(['config' => ['Labels' => $labels]], JSON_THROW_ON_ERROR),
            'BAD_METADATA' => json_encode(['config' => ['Labels' => $badLabels]], JSON_THROW_ON_ERROR),
        ], $environment);
        $process = proc_open($command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $environment);
        self::assertIsResource($process);
        $text = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'text' => $text,
            'output' => file_get_contents($this->directory.'/output'),
            'calls' => file_get_contents($this->directory.'/calls')];
    }
}
