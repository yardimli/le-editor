<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Locale
{
    public function handle(Request $request, Closure $next)
    {
        app()->setLocale($request->user()?->locale ?? session('locale', 'tr'));
        return $next($request);
    }
}
