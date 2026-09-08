<?php

namespace Tests\Feature\Concerns;

use App\Support\StoragePressure;

trait StoragePressureFixture
{
    private string $pressureFile;

    private function configureStoragePressure(string $state = 'normal'): void
    {
        $this->pressureFile = tempnam(sys_get_temp_dir(), 'dw-storage-');
        config([
            'server.storage_admission.file' => $this->pressureFile,
            'server.storage_admission.source' => 'test-storage',
            'server.storage_admission.max_age_seconds' => 15,
        ]);
        $this->observeStoragePressure($state);
    }

    private function observeStoragePressure(string $state, array $overrides = []): void
    {
        file_put_contents($this->pressureFile, json_encode(array_replace([
            'schema' => StoragePressure::SCHEMA,
            'source' => 'test-storage',
            'observed_at' => now()->getTimestamp(),
            'state' => $state,
        ], $overrides), JSON_THROW_ON_ERROR));
        chmod($this->pressureFile, 0600);
    }

    private function removeStoragePressure(): void
    {
        if (isset($this->pressureFile) && (file_exists($this->pressureFile) || is_link($this->pressureFile))) {
            unlink($this->pressureFile);
        }
    }
}
