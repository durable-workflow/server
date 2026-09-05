<?php

namespace App\Console\Commands;

use App\Support\ExternalPayloadBackupHold;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ExternalPayloadBackupHoldCommand extends Command
{
    protected $signature = 'external-payloads:backup-hold
        {action : acquire, renew, release, or status}
        {--owner= : Unique backup UUID; required except for unscoped status}
        {--ttl=900 : Lease lifetime in seconds (1-3600)}';

    protected $description = 'Coordinate online backup with external payload reclamation';

    public function handle(ExternalPayloadBackupHold $hold): int
    {
        try {
            $owner = $this->option('owner');
            $ttl = filter_var($this->option('ttl'), FILTER_VALIDATE_INT);
            if ($ttl === false) {
                throw new InvalidArgumentException('Backup hold TTL must be an integer.');
            }
            $report = match ($this->argument('action')) {
                'acquire' => $hold->acquire((string) $owner, $ttl),
                'renew' => $hold->renew((string) $owner, $ttl),
                'release' => $hold->release((string) $owner),
                'status' => $hold->status($owner),
                default => throw new InvalidArgumentException('Action must be acquire, renew, release, or status.'),
            };
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
