<?php

declare(strict_types=1);

use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadRegistry;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$requiredEnv = static function (string $name): string {
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        throw new RuntimeException("Missing required environment variable {$name}.");
    }

    return $value;
};

$reference = static function () use ($requiredEnv): array {
    $decoded = base64_decode($requiredEnv('DW_TEST_EXTERNAL_PAYLOAD_REFERENCE'), true);
    $value = is_string($decoded) ? json_decode($decoded, true) : null;

    if (! is_array($value)) {
        throw new RuntimeException('External payload reference is invalid.');
    }

    return $value;
};

try {
    $operation = $argv[1] ?? '';
    $namespace = 's3-process-boundary';
    $registry = $app->make(RuntimeExternalPayloadRegistry::class);

    if ($operation === 'write') {
        $payload = base64_decode($requiredEnv('DW_TEST_EXTERNAL_PAYLOAD_BYTES'), true);
        if (! is_string($payload)) {
            throw new RuntimeException('External payload bytes are invalid.');
        }

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $namespace],
            [
                'description' => 'S3 process-boundary qualification',
                'retention_days' => 1,
                'status' => 'active',
                'external_payload_storage' => [
                    'driver' => 's3',
                    'enabled' => true,
                    'threshold_bytes' => 1,
                    'config' => ['prefix' => 'process-boundary/'],
                ],
            ],
        );

        $result = $registry->upload($namespace, $payload, 'avro', hash('sha256', $payload));
        echo 'DW_S3_PROBE_REFERENCE='.base64_encode(json_encode($result, JSON_THROW_ON_ERROR)).PHP_EOL;

        exit(0);
    }

    if ($operation === 'read') {
        $payload = base64_decode($requiredEnv('DW_TEST_EXTERNAL_PAYLOAD_BYTES'), true);
        if (! is_string($payload)) {
            throw new RuntimeException('External payload bytes are invalid.');
        }

        $result = $registry->fetch($namespace, $reference());
        if (! hash_equals($payload, $result['data'])) {
            throw new RuntimeException('Fetched external payload does not match the expected bytes.');
        }

        echo 'DW_S3_PROBE_SHA256='.hash('sha256', $result['data']).PHP_EOL;

        exit(0);
    }

    if ($operation === 'delete') {
        echo 'DW_S3_PROBE_DELETED='.$registry->deleteForNamespace($namespace).PHP_EOL;

        exit(0);
    }

    throw new RuntimeException('Expected write, read, or delete operation.');
} catch (RuntimeExternalPayloadException $exception) {
    fwrite(STDERR, 'DW_S3_PROBE_ERROR='.$exception->reason.PHP_EOL);

    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, 'DW_S3_PROBE_ERROR='.$exception::class.': '.$exception->getMessage().PHP_EOL);

    exit(1);
}
