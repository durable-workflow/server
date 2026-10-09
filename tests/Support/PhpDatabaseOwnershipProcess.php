<?php

use App\Database\PhpDatabaseRefused;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if ($argv[1] === 'leave-journal') {
    $writer = new PDO('sqlite:'.getenv('DB_DATABASE'));
    $writer->exec('PRAGMA journal_mode=DELETE');
    $writer->exec('PRAGMA cache_size=1');
    $writer->exec('BEGIN IMMEDIATE');
    $writer->exec('UPDATE sentinel SET payload = randomblob(1048576)');
    echo 'journal-ready';
    flush();
    while (true) {
        usleep(100000);
    }
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if ($argv[1] === 'mysql-init') {
    config(['database.connections.mysql.options' => [PDO::MYSQL_ATTR_INIT_COMMAND => 'UPDATE sentinel SET value = 99']]);
} elseif ($argv[1] === 'mysql-silent') {
    config(['database.connections.mysql.options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]]);
} elseif ($argv[1] === 'pgsql-schema') {
    config(['database.connections.pgsql.search_path' => 'other_runtime,public']);
} elseif ($argv[1] === 'wipe-other') {
    config([
        'database.connections.protected' => config('database.connections.sqlite'),
        'database.connections.sqlite.database' => ':memory:',
    ]);
}
try {
    if ($argv[1] === 'wipe-other') {
        exit($kernel->call('db:wipe', ['--database' => 'protected', '--force' => true]));
    }
    DB::connection()->getPdo();
} catch (PhpDatabaseRefused $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'php_database_ownership_unknown'.PHP_EOL);
    exit(1);
}
