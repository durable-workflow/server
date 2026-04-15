<?php

namespace App\Support;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\Filesystem;

final class ServerPollingCache
{
    private ?CacheRepository $store = null;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Filesystem $files,
    ) {}

    public function store(): CacheRepository
    {
        if ($this->store instanceof CacheRepository) {
            return $this->store;
        }

        $store = $this->cache->store();

        // If the default cache driver is 'file', use a dedicated polling cache directory
        // to avoid polluting the main cache. For shared backends (Redis, database, etc.),
        // use the default store directly to enable cross-node wake signal coordination.
        if ($store instanceof LaravelCacheRepository && $store->getStore() instanceof FileStore) {
            $path = $this->cachePath();

            $this->files->ensureDirectoryExists($path, 0775, true);

            $store = new LaravelCacheRepository(new FileStore($this->files, $path));
        }

        return $this->store = $store;
    }

    private function cachePath(): string
    {
        $configured = config('server.polling.cache_path');

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : storage_path('framework/cache/server-polling');
    }
}
