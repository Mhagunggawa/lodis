<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthCheckController extends Controller
{
    /**
     * Return HTTP 200 OK health status for auto-reconnect polling after web server reload.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'app' => config('app.name', 'Lodis'),
            'server' => 'Laragon Windows Server',
            'timestamp' => now()->toIso8601String(),
        ], 200);
    }
}
