<?php

namespace App\Support;

final class LongPollWaitSlot
{
    private function __construct(
        private readonly ?ServerPollingCache $cache,
        private readonly ?string $key,
        private readonly ?string $owner,
    ) {}

    public static function unlimited(): self
    {
        return new self(null, null, null);
    }

    public static function acquired(ServerPollingCache $cache, string $key, string $owner): self
    {
        return new self($cache, $key, $owner);
    }

    public function release(): void
    {
        if ($this->cache === null || $this->key === null || $this->owner === null) {
            return;
        }

        try {
            $store = $this->cache->store();

            if ($store->get($this->key) === $this->owner) {
                $store->forget($this->key);
            }
        } catch (\Throwable) {
            return;
        }
    }
}
