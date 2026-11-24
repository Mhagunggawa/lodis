<?php

namespace App\Http\Controllers;

use App\Models\MonitoredDomain;
use App\Models\ServerActionLog;
use App\Services\ServerMetricsService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the main Lodis monitoring dashboard.
     */
    public function index(ServerMetricsService $metricsService): Response
    {
        $metrics = $metricsService->getMetrics();

        $totalDomains = MonitoredDomain::query()->count();
        $activeDomains = MonitoredDomain::query()->where('is_active', true)->count();
        $criticalSslCount = MonitoredDomain::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('status', 'critical')
                    ->orWhere('status', 'error')
                    ->orWhere('days_remaining', '<=', 7);
            })
            ->count();

        $warningSslCount = MonitoredDomain::query()
            ->where('is_active', true)
            ->where('status', 'warning')
            ->count();

        $lastAction = ServerActionLog::query()
            ->with('user:id,name,email')
            ->latest('id')
            ->first();

        $domains = MonitoredDomain::query()
            ->orderByRaw('CASE WHEN days_remaining IS NULL THEN 1 ELSE 0 END')
            ->orderBy('days_remaining')
            ->limit(8)
            ->get();

        $recentLogs = ServerActionLog::query()
            ->with('user:id,name,email')
            ->latest('id')
            ->limit(6)
            ->get();

        return Inertia::render('Dashboard', [
            'kpi' => [
                'webserver_online' => $metrics['webserver_online'],
                'webserver_name' => $metrics['webserver_name'],
                'total_domains' => $totalDomains,
                'active_domains' => $activeDomains,
                'critical_ssl_count' => $criticalSslCount,
                'warning_ssl_count' => $warningSslCount,
                'last_action' => $lastAction,
            ],
            'metrics' => $metrics,
            'domains' => $domains,
            'recentLogs' => $recentLogs,
        ]);
    }

    /**
     * Return fresh server resource metrics JSON.
     */
    public function metrics(ServerMetricsService $metricsService): JsonResponse
    {
        return response()->json($metricsService->getMetrics(true));
    }
}
