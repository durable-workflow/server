<?php

declare(strict_types=1);

namespace Tests\Support;

final readonly class ServerCodecRegressionFixture
{
    public function __construct(
        public string $id,
        public string $codec,
        public mixed $value,
        public ?string $wire,
        public string $operation,
        public ?string $error,
    ) {}
}
