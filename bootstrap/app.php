<?php

use App\Domain\Support\DomainRuleViolation;
use App\Domain\Support\InvalidTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Same-origin SPA uses Sanctum cookie sessions; mobile apps use bearer tokens.
        $middleware->statefulApi();
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One predictable error shape for web and mobile: { message, code, errors? }.
        $api = fn (Request $r) => $r->is('api/*') || $r->expectsJson();

        $exceptions->render(function (DomainRuleViolation $e, Request $r) use ($api) {
            return $api($r) ? response()->json(['message' => $e->getMessage(), 'code' => $e->rule], 422) : null;
        });
        $exceptions->render(function (InvalidTransition $e, Request $r) use ($api) {
            return $api($r) ? response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition'], 409) : null;
        });
        $exceptions->render(function (ValidationException $e, Request $r) use ($api) {
            return $api($r) ? response()->json([
                'message' => $e->getMessage(), 'code' => 'validation_failed', 'errors' => $e->errors(),
            ], 422) : null;
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($api) {
            return $api($r) ? response()->json(['message' => 'Please sign in.', 'code' => 'unauthenticated'], 401) : null;
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $r) use ($api) {
            return $api($r) ? response()->json(['message' => 'You do not have access to this.', 'code' => 'forbidden'], 403) : null;
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $r) use ($api) {
            return $api($r) ? response()->json(['message' => 'Not found.', 'code' => 'not_found'], 404) : null;
        });
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $r) use ($api) {
            return $api($r) ? response()->json(['message' => 'Too many requests. Please slow down.', 'code' => 'rate_limited'], 429, $e->getHeaders()) : null;
        });
    })->create();
