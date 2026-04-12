<?php

use App\Support\ControlPlaneProtocol;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \App\Http\Middleware\NamespaceResolver::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\CompressResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (ControlPlaneProtocol::requestVersion($request) !== ControlPlaneProtocol::VERSION) {
                return null;
            }

            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
                'validation_errors' => $exception->errors(),
            ], 422);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (ControlPlaneProtocol::requestVersion($request) !== ControlPlaneProtocol::VERSION) {
                return null;
            }

            if (\App\Support\ControlPlaneOperation::fromRequest($request) === null) {
                return null;
            }

            $status = $exception->getStatusCode();
            $message = trim($exception->getMessage()) !== ''
                ? $exception->getMessage()
                : (Response::$statusTexts[$status] ?? "HTTP {$status}");

            $payload = array_filter([
                'message' => $message,
                'reason' => match ($status) {
                    401 => 'unauthorized',
                    403 => 'forbidden',
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    default => null,
                },
            ], static fn (mixed $value): bool => $value !== null);

            return ControlPlaneProtocol::jsonForRequest($request, $payload, $status);
        });
    })->create();
