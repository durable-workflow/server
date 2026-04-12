<?php

namespace Tests\Unit;

use App\Support\ServerPollingCache;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ServerPollingCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/cache/server-polling-test'));
        File::deleteDirectory(storage_path('framework/cache/server-polling/testing'));

        parent::tearDown();
    }

    public function test_it_uses_a_dedicated_file_cache_directory_for_polling_coordination(): void
    {
        config([
            'cache.default' => 'file',
            'server.polling.cache_path' => storage_path('framework/cache/server-polling-test'),
        ]);

        File::deleteDirectory(storage_path('framework/cache/server-polling-test'));

        /** @var ServerPollingCache $cache */
        $cache = app(ServerPollingCache::class);
        $store = $cache->store();

        $this->assertInstanceOf(CacheRepository::class, $store);
        $this->assertInstanceOf(FileStore::class, $store->getStore());
        $this->assertSame(
            storage_path('framework/cache/server-polling-test'),
            $store->getStore()->getDirectory(),
        );

        $store->put('polling-coordination-probe', 'value', now()->addMinute());

        $this->assertSame('value', $store->get('polling-coordination-probe'));
        $this->assertDirectoryExists(storage_path('framework/cache/server-polling-test'));
    }

    public function test_it_defaults_the_polling_cache_to_an_app_environment_scoped_directory(): void
    {
        config([
            'cache.default' => 'file',
        ]);

        File::deleteDirectory(storage_path('framework/cache/server-polling/testing'));

        /** @var ServerPollingCache $cache */
        $cache = app(ServerPollingCache::class);
        $store = $cache->store();

        $this->assertInstanceOf(CacheRepository::class, $store);
        $this->assertInstanceOf(FileStore::class, $store->getStore());
        $this->assertSame(
            storage_path('framework/cache/server-polling/testing'),
            $store->getStore()->getDirectory(),
        );

        $store->put('polling-coordination-default-probe', 'value', now()->addMinute());

        $this->assertSame('value', $store->get('polling-coordination-default-probe'));
        $this->assertDirectoryExists(storage_path('framework/cache/server-polling/testing'));
    }
}
