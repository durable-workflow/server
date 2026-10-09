<?php

namespace App\Console\Commands;

use App\Database\PhpDatabaseRefused;
use App\Support\BackendUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssertPhpDatabaseCommand extends Command
{
    protected $signature = 'server:assert-php-database {--allow-unavailable : Preserve degraded startup when the backend is temporarily unavailable}';

    protected $description = 'Refuse databases owned by Rust before starting a PHP Server role';

    public function handle(): int
    {
        try {
            DB::connection()->getPdo();

            return self::SUCCESS;
        } catch (PhpDatabaseRefused $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            if ($this->option('allow-unavailable') && BackendUnavailable::is($exception)) {
                $this->warn('Database temporarily unavailable. Readiness remains degraded; every later PHP connection must still pass its ownership check.');

                return self::SUCCESS;
            }

            $this->error('php_database_ownership_unknown: The database could not be checked. No connection was released to PHP. Check database availability and permissions.');

            return self::FAILURE;
        }
    }
}
