<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IpWhitelistMiddleware
{
    /**
     * Restrict access to whitelisted IP addresses when ADMIN_IP_WHITELIST is configured.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $whitelistConfig = trim((string) config('lodis.admin_ip_whitelist', ''));

        if ($whitelistConfig === '') {
            return $next($request);
        }

        $allowedIps = array_values(array_filter(array_map('trim', explode(',', $whitelistConfig))));

        if (empty($allowedIps)) {
            return $next($request);
        }

        $clientIp = (string) $request->ip();

        if (! in_array($clientIp, $allowedIps, true)) {
            abort(403, 'Akses ditolak: alamat IP Anda ('.$clientIp.') tidak terdaftar dalam ADMIN_IP_WHITELIST.');
        }

        return $next($request);
    }
}
