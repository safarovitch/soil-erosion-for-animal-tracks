<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $availableLocales = config('app.available_locales', [config('app.locale')]);
        $sessionLocale = $request->session()->get('locale');

        if (
            is_string($sessionLocale) &&
            in_array($sessionLocale, $availableLocales, true)
        ) {
            App::setLocale($sessionLocale);
        }

        return $next($request);
    }
}

