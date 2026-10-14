<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
$directory = sys_get_temp_dir().'/dw-external-registry-'.bin2hex(random_bytes(8));
mkdir($directory, 0775, true);
touch($directory.'/state.sqlite');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$directory.'/state.sqlite');
putenv('CACHE_STORE=array');
$app = require '/app/bootstrap/app.php';
$app->useStoragePath($directory.'/laravel');
foreach (['logs', 'framework/cache/data', 'framework/sessions', 'framework/views'] as $path) {
    mkdir($directory.'/laravel/'.$path, 0775, true);
}
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'Published external registry probe failed: '.get_class($error).': '.substr($error->getMessage(), 0, 400)."\n");
    exit(1);
});
$checks = [];
$assert = static function (bool $condition, string $name) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException('Published registry disagreement: '.$name);
    }
    $checks[] = $name;
};
$refusal = static function (Closure $operation, string $reason) use ($assert): void {
    try {
        $operation();
    } catch (App\Support\RuntimeExternalPayloadException $exception) {
        $assert($exception->reason === $reason, $reason);

        return;
    }
    throw new RuntimeException('Published registry unexpectedly accepted '.$reason);
};
try {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $directory.'/state.sqlite',
        'cache.default' => 'array',
        'server.external_payload_transport.max_payload_bytes' => 4096,
        'server.external_payload_transport.abandoned_upload_expiry_seconds' => 60,
        'server.external_payload_transport.max_bytes_per_namespace' => null,
        'server.external_payload_transport.max_objects_per_namespace' => null,
        'server.external_payload_transport.hard_max_bytes_per_namespace' => null,
        'server.external_payload_transport.hard_max_objects_per_namespace' => null,
        'server.external_payload_transport.namespace_overrides' => [],
    ]);
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    foreach (['default', 'other', 'broken'] as $namespace) {
        $root = $directory.($namespace === 'broken' ? '/broken' : '/space λ');
        if (! is_dir($root)) {
            mkdir($root, 0775, true);
        }
        App\Models\WorkflowNamespace::query()->updateOrCreate(['name' => $namespace], [
            'status' => 'active',
            'retention_mode' => 'bounded',
            'retention_days' => 30,
            'external_payload_storage' => ['driver' => 'local', 'enabled' => true,
                'config' => ['uri' => 'file://'.$root]],
        ]);
    }
    $registry = app(App\Support\RuntimeExternalPayloadRegistry::class);
    // Upload does not validate an Avro datum. Integrity covers this exact
    // encoded transport string, independently from later state-bearing decode.
    $bytes = 'original encoded transport string';
    $sha = hash('sha256', $bytes);
    $reference = $registry->upload('default', $bytes, 'avro', $sha);
    $assert($registry->upload('default', $bytes, 'avro', $sha) === $reference, 'same_namespace_retry_same_id');
    $row = App\Models\RuntimeExternalPayload::query()->where('id', $reference['reference_id'])->firstOrFail();
    $assert($row->upload_status === 'ready' && $row->retained_at === null, 'upload_ready_unclaimed');
    $assert(str_contains($row->storage_uri, '/space%20%CE%BB/avro/'), 'php_encoded_local_path');
    $bucket = hexdec(substr(hash('sha256', $row->storage_uri), 0, 4)) % App\Support\RuntimeExternalPayloadObjectLock::BUCKETS;
    $assert($bucket >= 0 && $bucket < 256, 'fixed_256_object_lock_buckets');
    $other = $registry->upload('other', $bytes, 'avro', $sha);
    $assert($other['reference_id'] !== $reference['reference_id'], 'separate_namespace_same_bytes_distinct_id');
    $refusal(fn () => $registry->fetch('other', $reference), 'external_payload_not_found');
    $conflicting = $reference;
    $conflicting['size_bytes']++;
    $refusal(fn () => $registry->fetch('default', $conflicting), 'external_payload_integrity_mismatch');
    $fetched = $registry->fetch('default', $reference, stream: true)['data'];
    $providerPath = rawurldecode((string) parse_url($row->storage_uri, PHP_URL_PATH));
    file_put_contents($providerPath, 'partial');
    $assert(stream_get_contents($fetched->rewind()) === $bytes, 'verified_snapshot_independent_of_provider_change');
    $fetched->close();
    $refusal(fn () => $registry->fetch('default', $reference), 'external_payload_integrity_mismatch');
    $assert($registry->upload('default', $bytes, 'avro', $sha) === $reference && file_get_contents($providerPath) === $bytes,
        'same_id_retry_repairs_partial_bytes');
    $row->forceFill(['expires_at' => now()->subMinute()])->save();
    $refusal(fn () => $registry->fetch('default', $reference), 'external_payload_expired');
    $retained = now()->subHour()->startOfSecond();
    $row->forceFill(['retained_at' => $retained, 'expires_at' => null])->save();
    $registry->upload('default', $bytes, 'avro', $sha);
    $row->refresh();
    $assert($row->retained_at->equalTo($retained) && $row->expires_at === null && $row->upload_status === 'ready',
        'retained_retry_preserves_owner_and_no_expiry');
    file_put_contents($directory.'/broken/avro', 'block the provider directory');
    $refusal(fn () => $registry->upload('broken', $bytes, 'avro', $sha), 'external_payload_unavailable');
    $writing = App\Models\RuntimeExternalPayload::query()->where('namespace', 'broken')->sole();
    $assert($writing->upload_status === 'writing' && $writing->retained_at === null && $writing->expires_at !== null,
        'provider_failure_leaves_committed_writing_row');
    unlink($directory.'/broken/avro');
    $recovered = $registry->upload('broken', $bytes, 'avro', $sha);
    $assert($recovered['reference_id'] === $writing->id && $writing->refresh()->upload_status === 'ready',
        'failed_provider_retry_recovers_original_id');
    $hashes = [];
    foreach ([App\Support\RuntimeExternalPayloadRegistry::class, App\Support\RuntimeLocalExternalPayloadStorage::class,
        App\Support\RuntimeExternalPayloadObjectLock::class] as $class) {
        $hashes[$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
    }
    echo json_encode(['outcome' => 'pass', 'scope' => 'local_registry_and_bytes_only', 'checks' => $checks,
        'source_sha256' => $hashes], JSON_THROW_ON_ERROR)."\n";
} finally {
    Illuminate\Support\Facades\DB::purge('sqlite');
    Illuminate\Support\Facades\File::deleteDirectory($directory);
}
