<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\EnsureActive::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'sender.device' => \App\Http\Middleware\AuthenticateSenderDevice::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A 404 inside a Livewire request shows a "page not found" screen on
        // top of a page that exists, and Laravel does not log 404s. Write
        // down which component and action hit it, and why, so it can be traced.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if (!$request->hasHeader('X-Livewire')) {
                return null;
            }
            $cause = $e->getPrevious() ?? $e;
            $components = collect((array) $request->input('components'))->map(function ($c) {
                $snapshot = json_decode((string) ($c['snapshot'] ?? ''), true);

                return [
                    'name' => $snapshot['memo']['name'] ?? '?',
                    'calls' => collect((array) ($c['calls'] ?? []))->pluck('method')->all(),
                    'updates' => array_keys((array) ($c['updates'] ?? [])),
                ];
            })->all();
            \Illuminate\Support\Facades\Log::warning('Livewire request ended in 404', [
                'user' => $request->user()?->id,
                'cause' => get_class($cause) . ': ' . $cause->getMessage(),
                'at' => $cause->getFile() . ':' . $cause->getLine(),
                'components' => $components,
                'bytes' => strlen((string) $request->getContent()),
            ]);

            return null;
        });
    })->create();
