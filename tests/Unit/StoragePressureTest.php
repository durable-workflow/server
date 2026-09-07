<?php

namespace Tests\Unit;

use App\Support\StoragePressure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\StoragePressureFixture;
use Tests\TestCase;

class StoragePressureTest extends TestCase
{
    use StoragePressureFixture;

    protected function tearDown(): void
    {
        $this->removeStoragePressure();
        parent::tearDown();
    }

    public function test_disabled_by_default(): void
    {
        config(['server.storage_admission.file' => null, 'server.storage_admission.source' => null]);
        $this->assertSame(['enabled' => false, 'state' => 'normal', 'reason' => null, 'observed_at' => null],
            app(StoragePressure::class)->snapshot());
    }

    public function test_each_read_observes_current_state_and_expiry(): void
    {
        $this->configureStoragePressure();
        $pressure = app(StoragePressure::class);
        $this->assertTrue($pressure->acceptsNewWork());
        foreach (['draining', 'fenced', 'normal'] as $state) {
            $this->observeStoragePressure($state);
            $this->assertSame($state, $pressure->snapshot()['state']);
        }
        $this->travel(16)->seconds();
        $this->assertSame('storage_admission_unavailable', $pressure->snapshot()['reason']);
        $this->observeStoragePressure('normal');
        $this->assertTrue($pressure->acceptsNewWork());
    }

    #[DataProvider('invalidObservations')]
    public function test_invalid_observation_fences_writes(array $overrides): void
    {
        $this->configureStoragePressure();
        $this->observeStoragePressure('normal', $overrides);
        $this->assertUnavailable();
    }

    public static function invalidObservations(): array
    {
        return [
            'schema' => [['schema' => 'something-else']],
            'source' => [['source' => 'other-storage']],
            'missing source' => [['source' => null]],
            'unknown state' => [['state' => 'healthy']],
            'wrong state type' => [['state' => true]],
            'string timestamp' => [['observed_at' => '123']],
            'missing timestamp' => [['observed_at' => null]],
            'future' => [['observed_at' => PHP_INT_MAX]],
            'stale' => [['observed_at' => 0]],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_missing_or_untrusted_file_fences_writes(string $kind): void
    {
        $this->configureStoragePressure();
        match ($kind) {
            'missing' => unlink($this->pressureFile),
            'malformed' => file_put_contents($this->pressureFile, '{'),
            'oversized' => file_put_contents($this->pressureFile, str_repeat(' ', 4097)),
            'world writable' => chmod($this->pressureFile, 0666),
            'group writable' => chmod($this->pressureFile, 0620),
            'directory' => config(['server.storage_admission.file' => dirname($this->pressureFile)]),
            'partial config' => config(['server.storage_admission.file' => '']),
            'missing identity' => config(['server.storage_admission.source' => '']),
            'invalid age' => config(['server.storage_admission.max_age_seconds' => 0]),
        };
        $this->assertUnavailable();
    }

    public static function invalidFiles(): array
    {
        return array_map(static fn (string $kind): array => [$kind], [
            'missing', 'malformed', 'oversized', 'world writable', 'group writable',
            'directory', 'partial config', 'missing identity', 'invalid age',
        ]);
    }

    public function test_symlink_is_not_an_observation(): void
    {
        $this->configureStoragePressure();
        $target = $this->pressureFile.'.target';
        rename($this->pressureFile, $target);
        symlink($target, $this->pressureFile);
        try {
            $this->assertUnavailable();
        } finally {
            unlink($target);
        }
    }

    private function assertUnavailable(): void
    {
        $this->assertSame([
            'enabled' => true, 'state' => 'fenced',
            'reason' => 'storage_admission_unavailable', 'observed_at' => null,
        ], app(StoragePressure::class)->snapshot());
    }
}
