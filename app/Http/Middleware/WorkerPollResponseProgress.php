<?php

namespace App\Http\Middleware;

use App\Support\LongPollResponseProgress;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

final class WorkerPollResponseProgress
{
    private const PATHS = [
        'api/worker/workflow-tasks/poll',
        'api/worker/activity-tasks/poll',
        'api/worker/query-tasks/poll',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $accept = AcceptHeader::fromString(strtolower($request->headers->get('Accept', '')));
        $transport = $accept->get(LongPollResponseProgress::MEDIA_TYPE);

        if (! $request->isMethod('POST') || ! in_array($request->path(), self::PATHS, true)
            || $transport === null || $transport->getQuality() <= 0
            || $transport->getQuality() < ($accept->get('application/json')?->getQuality() ?? 0)) {
            return $next($request);
        }

        $progress = app()->make(LongPollResponseProgress::class, ['request' => $request]);
        $request->attributes->set(LongPollResponseProgress::REQUEST_ATTRIBUTE, $progress);

        return $progress->response(static fn (): Response => $next($request));
    }
}
