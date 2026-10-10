<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
$application = require '/app/bootstrap/app.php';
$application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
$pdo = $connection->getPdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$sample = match ($driver) {
    'pgsql' => ['setting' => 'lock_timeout', 'value' => $pdo->query('SHOW lock_timeout')->fetchColumn()],
    'mysql' => ['setting' => 'innodb_lock_wait_timeout', 'value' => (int) $pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn()],
    'sqlite' => ['setting' => 'busy_timeout', 'value' => (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn()],
    default => throw new RuntimeException('Unsupported pressure session driver.'),
};
echo json_encode(['origin' => 'php_application_cli_connection', 'driver' => $driver, ...$sample], JSON_THROW_ON_ERROR)."\n";
