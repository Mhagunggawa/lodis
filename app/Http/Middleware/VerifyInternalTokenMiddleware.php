<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalTokenMiddleware
{
    /**
     * Protect internal PowerShell callback endpoint by verifying localhost IP and X-Internal-Token header.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = (string) $request->ip();
        $allowedLocalIps = ['127.0.0.1', '::1', 'localhost'];

        if (! in_array($clientIp, $allowedLocalIps, true)) {
            return response()->json([
                'message' => 'Forbidden: internal callback only accepts local loopback requests.',
            ], 403);
        }

        $expectedToken = (string) config('lodis.internal_api_token', '');
        $providedToken = (string) $request->header('X-Internal-Token', '');

        if ($expectedToken === '' || $providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized: invalid or missing X-Internal-Token header.',
            ], 401);
        }

        return $next($request);
    }
}
