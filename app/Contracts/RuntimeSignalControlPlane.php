<?php

namespace App\Contracts;

interface RuntimeSignalControlPlane
{
    /**
     * Deliver a runtime-reserved signal that external signal ingress cannot forge.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runtimeSignal(string $instanceId, string $name, array $options = []): array;
}
