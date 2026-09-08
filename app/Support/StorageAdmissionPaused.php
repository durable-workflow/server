<?php

namespace App\Support;

use App\Http\Middleware\EnforceStorageAdmission;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class StorageAdmissionPaused extends RuntimeException
{
    public function __construct(public readonly array $snapshot)
    {
        parent::__construct('Storage admission paused before a new claim.');
    }

    public function render(Request $request): Response
    {
        return app(EnforceStorageAdmission::class)->reject($request, $this->snapshot, false);
    }
}
