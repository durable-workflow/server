<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Workflow\Serializers\Avro;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'upgrade_payload_fixture_failed: '.$error::class."\n");
    exit(1);
});

// Constructed database rows for metadata inspection, not executed workflows.
// Run only on a new disposable schema created by the normal PHP bootstrap.
$mode = $argv[1] ?? '';
if (! in_array($mode, ['seed', 'unsupported', 'malformed', 'oversized', 'restore'], true)) {
    throw new RuntimeException('Expected an explicit payload fixture mode.');
}
$type = 'parity.upgrade_payload_fixture';
if (DB::table('workflow_runs')->where('workflow_type', '!=', $type)->exists()
    || DB::table('workflow_instances')->where('workflow_type', '!=', $type)->exists()
    || DB::table('activity_executions')->where('activity_type', '!=', $type)->exists()) {
    throw new RuntimeException('Payload fixture refuses unrelated source rows.');
}
$bytes = Avro::serialize([9007199254740993, null, 'upgradeλ']);
$reference = new ExternalPayloadReference('file:///source-fixture-unavailable-object', hash('sha256', $bytes), strlen($bytes), 'avro');
$stored = ExternalPayloads::encodeStoredEnvelope(['codec' => 'avro', 'external_storage' => $reference->toArray()]);
$last = 'payload-inspection-run-032';
DB::transaction(static function () use ($mode, $type, $bytes, $stored, $last): void {
    if ($mode !== 'seed') {
        if (DB::table('workflow_runs')->count() !== 33 || DB::table('activity_executions')->count() !== 1) {
            throw new RuntimeException('Original constructed payload fixture is missing.');
        }
        $value = match ($mode) {
            'unsupported' => 'dw-external-payload:v2:unqualified',
            'malformed' => 'dw-external-payload:v1:%%%',
            'oversized' => 'dw-external-payload:v1:'.str_repeat('A', 65536),
            'restore' => null,
        };
        DB::table('workflow_runs')->where('id', $last)->update(['output' => $value]);

        return;
    }
    if (DB::table('workflow_runs')->exists() || DB::table('activity_executions')->exists()) {
        throw new RuntimeException('Payload seed requires an empty disposable source.');
    }
    for ($index = 0; $index < 33; $index++) {
        $id = sprintf('payload-inspection-run-%03d', $index);
        $instance = sprintf('payload-inspection-instance-%03d', $index);
        DB::table('workflow_instances')->insert(['id' => $instance, 'workflow_class' => $type,
            'workflow_type' => $type, 'namespace' => 'default', 'current_run_id' => $id, 'run_count' => 1]);
        DB::table('workflow_runs')->insert(['id' => $id, 'workflow_instance_id' => $instance,
            'run_number' => 1, 'workflow_class' => $type, 'workflow_type' => $type, 'namespace' => 'default',
            'status' => 'completed', 'payload_codec' => 'avro', 'output_payload_codec' => 'avro',
            'arguments' => $index === 0 ? $stored : $bytes, 'output' => $index === 0 ? $stored : null]);
    }
    DB::table('activity_executions')->insert(['id' => 'payload-inspection-act-01',
        'workflow_run_id' => 'payload-inspection-run-000', 'sequence' => 1, 'activity_class' => $type,
        'activity_type' => $type, 'status' => 'completed', 'payload_codec' => 'avro',
        'arguments' => $stored, 'result' => $stored]);
});
$hashes = [];
foreach ([Avro::class, ExternalPayloads::class, ExternalPayloadReference::class] as $class) {
    $hashes[$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
}
echo json_encode(['outcome' => 'pass', 'fixture_mode' => $mode,
    'scope' => 'constructed_source_rows_with_installed_php_serializers',
    'workflow_version' => InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
    'composer_reference' => InstalledVersions::getReference('durable-workflow/workflow'),
    'loaded_class_sha256' => $hashes, 'runs' => 33, 'activities' => 1,
    'provider_object_created' => false, 'workflow_execution_claimed' => false,
    'takeover_allowed' => false], JSON_THROW_ON_ERROR)."\n";
