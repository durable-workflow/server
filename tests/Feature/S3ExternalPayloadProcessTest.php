<?php

namespace Tests\Feature;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class S3ExternalPayloadProcessTest extends TestCase
{
    private string $bucket;

    private string $database;

    private string $endpoint;

    private string $key;

    private string $secret;

    private S3Client $s3;

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = getenv('DW_TEST_S3_ENDPOINT');
        if (! is_string($endpoint) || $endpoint === '') {
            $this->markTestSkipped('Set DW_TEST_S3_ENDPOINT to run the real S3-compatible process test.');
        }

        $this->endpoint = $endpoint;
        $this->key = $this->requiredEnv('DW_TEST_S3_ACCESS_KEY_ID');
        $this->secret = $this->requiredEnv('DW_TEST_S3_SECRET_ACCESS_KEY');
        $this->bucket = 'dw-external-payload-'.bin2hex(random_bytes(6));
        $database = tempnam(sys_get_temp_dir(), 'dw-s3-process-');

        if ($database === false) {
            self::fail('Unable to create the process-boundary SQLite database.');
        }
        $this->database = $database;

        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $this->key, 'secret' => $this->secret],
            'http' => ['connect_timeout' => 1, 'timeout' => 5],
        ]);

        $this->waitForObjectStore();
        $this->s3->createBucket(['Bucket' => $this->bucket]);
        $this->runProcess([PHP_BINARY, 'artisan', 'migrate:fresh', '--force']);
    }

    protected function tearDown(): void
    {
        if (isset($this->s3, $this->bucket)) {
            try {
                $objects = $this->s3->listObjectsV2(['Bucket' => $this->bucket]);
                foreach ($objects['Contents'] ?? [] as $object) {
                    $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $object['Key']]);
                }
                $this->s3->deleteBucket(['Bucket' => $this->bucket]);
            } catch (AwsException) {
                // The assertion failure is more useful than cleanup noise.
            }
        }

        if (isset($this->database) && is_file($this->database)) {
            unlink($this->database);
        }

        parent::tearDown();
    }

    public function test_runtime_payload_survives_a_fresh_application_process_and_validates_integrity(): void
    {
        $payload = "durable\0workflow\xffs3-process-boundary";
        $payloadHash = hash('sha256', $payload);
        $payloadEnv = ['DW_TEST_EXTERNAL_PAYLOAD_BYTES' => base64_encode($payload)];

        $write = $this->runProbe('write', $payloadEnv);
        self::assertMatchesRegularExpression('/^DW_S3_PROBE_REFERENCE=/m', $write->getOutput());

        preg_match('/^DW_S3_PROBE_REFERENCE=(.+)$/m', $write->getOutput(), $matches);
        $reference = trim($matches[1]);
        $referenceEnv = $payloadEnv + ['DW_TEST_EXTERNAL_PAYLOAD_REFERENCE' => $reference];
        $key = 'process-boundary/avro/'.substr($payloadHash, 0, 2).'/'.$payloadHash;

        self::assertTrue($this->s3->doesObjectExistV2($this->bucket, $key));

        $read = $this->runProbe('read', $referenceEnv);
        self::assertStringContainsString('DW_S3_PROBE_SHA256='.$payloadHash, $read->getOutput());

        $corruptPayload = substr($payload, 0, -1).($payload[-1] === 'x' ? 'y' : 'x');
        $this->s3->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $corruptPayload]);

        $corruptRead = $this->runProbe('read', $referenceEnv, false);
        self::assertSame(2, $corruptRead->getExitCode());
        self::assertStringContainsString(
            'DW_S3_PROBE_ERROR=external_payload_integrity_mismatch',
            $corruptRead->getErrorOutput(),
        );

        $this->s3->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $payload]);

        $delete = $this->runProbe('delete', $referenceEnv);
        self::assertStringContainsString('DW_S3_PROBE_DELETED=1', $delete->getOutput());
        self::assertFalse($this->s3->doesObjectExistV2($this->bucket, $key));
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function runProbe(string $operation, array $environment = [], bool $mustSucceed = true): Process
    {
        return $this->runProcess(
            [PHP_BINARY, 'tests/Support/S3ExternalPayloadProcess.php', $operation],
            $environment,
            $mustSucceed,
        );
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function runProcess(array $command, array $environment = [], bool $mustSucceed = true): Process
    {
        $process = new Process($command, dirname(__DIR__, 2), $environment + $this->environment());
        $process->setTimeout(120);
        $process->run();

        if ($mustSucceed) {
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }

        return $process;
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:dGVzdGluZy10ZXN0aW5nLXRlc3RpbmctdGVzdGluZzEyMzQ1Ng==',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->database,
            'DW_AUTH_DRIVER' => 'none',
            'DW_EXTERNAL_PAYLOAD_S3_ACCESS_KEY_ID' => $this->key,
            'DW_EXTERNAL_PAYLOAD_S3_SECRET_ACCESS_KEY' => $this->secret,
            'DW_EXTERNAL_PAYLOAD_S3_REGION' => 'us-east-1',
            'DW_EXTERNAL_PAYLOAD_S3_BUCKET' => $this->bucket,
            'DW_EXTERNAL_PAYLOAD_S3_ENDPOINT' => $this->endpoint,
            'DW_EXTERNAL_PAYLOAD_S3_USE_PATH_STYLE_ENDPOINT' => 'true',
            'LOG_CHANNEL' => 'single',
            'QUEUE_CONNECTION' => 'database',
        ];
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            self::fail("Missing required environment variable {$name}.");
        }

        return $value;
    }

    private function waitForObjectStore(): void
    {
        $deadline = microtime(true) + 30;
        $lastError = null;

        do {
            try {
                $this->s3->listBuckets();

                return;
            } catch (AwsException $exception) {
                $lastError = $exception;
                usleep(250_000);
            }
        } while (microtime(true) < $deadline);

        self::fail('S3-compatible service did not become ready: '.($lastError?->getMessage() ?? 'unknown error'));
    }
}
