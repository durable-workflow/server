<?php

declare(strict_types=1);

// Inject one synthetic application error after the actual role has booted.
// CLI commands used by the image entrypoint must not satisfy the role check.
$role = getenv('CONTAINER_LOGGING_PROBE_ROLE');
$command = $_SERVER['argv'][1] ?? null;
$selected = match ($role) {
    'api' => PHP_SAPI === 'apache2handler' && ($_SERVER['REQUEST_URI'] ?? null) === '/api/health',
    'worker' => $command === 'queue:work',
    'scheduler' => $command === 'schedule:evaluate',
    default => false,
};

if ($selected) {
    register_shutdown_function(static function () use ($role): void {
        $nonce = getenv('CONTAINER_LOGGING_PROBE_NONCE');
        if (!is_string($nonce) || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1) {
            throw new RuntimeException('Invalid container logging probe identity.');
        }

        file_put_contents('/app/storage/framework/testing/dw-container-logging-probe', $nonce);
        \Illuminate\Support\Facades\Log::error('container-logging-probe', [
            'role' => $role,
            'nonce' => $nonce,
        ]);
    });
}
