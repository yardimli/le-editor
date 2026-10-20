<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RejectEmailControls
{
    public function handle(Request $request, Closure $next)
    {
        $email = $request->input('email');
        if (is_string($email) && preg_match('/[\x00-\x1f\x7f]/', $email)) {
            throw ValidationException::withMessages(['email' => 'Invalid email address.']);
        }
        return $next($request);
    }
}
