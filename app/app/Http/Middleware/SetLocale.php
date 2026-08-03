<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    public const SUPPORTED = ['en', 'ar', 'nl'];
    public const DEFAULT = 'en';

    public function handle(Request $request, Closure $next)
    {
        // Priority: session > app config default > hard-coded English.
        $locale = session('locale')
            ?? config('app.locale', self::DEFAULT);

        if (!in_array($locale, self::SUPPORTED, true)) {
            $locale = self::DEFAULT;
        }
        App::setLocale($locale);
        return $next($request);
    }
}
