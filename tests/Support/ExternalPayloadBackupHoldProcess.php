<?php

use App\Support\ExternalPayloadBackupHold;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $action = $argv[1];
    $path = $argv[2];
    $owner = $argv[3];
    $hold = app(ExternalPayloadBackupHold::class);
    $wait = static function () use ($path): void {
        $deadline = microtime(true) + 10;
        while (! is_file($path.'.continue')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Test barrier timed out.');
            }
            usleep(10000);
        }
    };

    switch ($action) {
        case 'init':
            (require dirname(__DIR__, 2).'/database/migrations/2026_09_05_000100_create_external_payload_backup_hold.php')->up();
            break;
        case 'acquire':
            touch($path.'.requested');
            echo json_encode($hold->acquire($owner, 120), JSON_THROW_ON_ERROR);
            break;
        case 'status':
            echo json_encode($hold->status($owner), JSON_THROW_ON_ERROR);
            break;
        case 'expire':
            DB::table('runtime_external_payload_backup_hold')->update(['expires_at' => '2000-01-01 00:00:00']);
            break;
        case 'expired-transaction-status':
            DB::beginTransaction();
            $hold->acquire($owner, 1);
            DB::select('SELECT pg_sleep(1.1)');
            echo json_encode($hold->status(), JSON_THROW_ON_ERROR);
            DB::commit();
            break;
        case 'stale-read-delete':
            DB::beginTransaction();
            DB::table('runtime_external_payload_backup_hold')->first();
            touch($path.'.snapshot');
            $wait();
            // Continue through the same deletion guard with an older read view.
        case 'delete':
            $hold->deleting(static function () use ($path, $wait): void {
                touch($path.'.entered');
                $wait();
                touch($path.'.deleted');
            });
            if (DB::transactionLevel() > 0) {
                DB::commit();
            }
            break;
        default:
            throw new RuntimeException('Unknown probe action.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
