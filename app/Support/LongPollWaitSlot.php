<?php

namespace App\Support;

final class LongPollWaitSlot
{
    /**
     * @param  list<array{cache: ServerPollingCache, key: string, owner: string}>  $reservations
     */
    private function __construct(
        private readonly array $reservations,
    ) {}

    public static function unlimited(): self
    {
        return new self([]);
    }

    public static function acquired(ServerPollingCache $cache, string $key, string $owner): self
    {
        return new self([compact('cache', 'key', 'owner')]);
    }

    public static function combine(self ...$slots): self
    {
        $reservations = [];

        foreach ($slots as $slot) {
            array_push($reservations, ...$slot->reservations);
        }

        return new self($reservations);
    }

    public function release(): void
    {
        foreach (array_reverse($this->reservations) as $reservation) {
            try {
                $store = $reservation['cache']->store();

                if ($store->get($reservation['key']) === $reservation['owner']) {
                    $store->forget($reservation['key']);
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }
}
