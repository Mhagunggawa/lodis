<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMonitoredDomainRequest;
use App\Http\Requests\UpdateMonitoredDomainRequest;
use App\Models\MonitoredDomain;
use App\Services\SslCheckerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonitoredDomainController extends Controller
{
    /**
     * List all monitored domains with search and status filter.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $domains = MonitoredDomain::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where('domain_name', 'like', "%{$search}%")
                    ->orWhere('issuer', 'like', "%{$search}%");
            })
            ->when($status !== '' && $status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderByRaw('CASE WHEN days_remaining IS NULL THEN 1 ELSE 0 END')
            ->orderBy('days_remaining')
            ->orderBy('domain_name')
            ->get();

        return Inertia::render('Domains/Index', [
            'domains' => $domains,
            'filters' => [
                'search' => $search,
                'status' => $status ?: 'all',
            ],
        ]);
    }

    /**
     * Store a new monitored domain and immediately inspect its SSL certificate.
     */
    public function store(StoreMonitoredDomainRequest $request, SslCheckerService $sslChecker): RedirectResponse
    {
        $validated = $request->validated();

        $domain = MonitoredDomain::query()->create([
            'domain_name' => $validated['domain_name'],
            'port' => (int) $validated['port'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'status' => 'error',
        ]);

        if ($domain->is_active) {
            $sslChecker->checkDomain($domain);
        }

        return back()->with('success', "Domain {$domain->domain_name} berhasil ditambahkan dan dipindai.");
    }

    /**
     * Update an existing monitored domain.
     */
    public function update(UpdateMonitoredDomainRequest $request, MonitoredDomain $domain, SslCheckerService $sslChecker): RedirectResponse
    {
        $domain->update($request->validated());

        if ($domain->is_active) {
            $sslChecker->checkDomain($domain);
        }

        return back()->with('success', "Domain {$domain->domain_name} berhasil diperbarui.");
    }

    /**
     * Delete a monitored domain.
     */
    public function destroy(MonitoredDomain $domain): RedirectResponse
    {
        $name = $domain->domain_name;
        $domain->delete();

        return back()->with('success', "Domain {$name} berhasil dihapus.");
    }

    /**
     * Scan SSL certificate for a single monitored domain.
     */
    public function scan(MonitoredDomain $domain, SslCheckerService $sslChecker): RedirectResponse
    {
        $updated = $sslChecker->checkDomain($domain);

        return back()->with(
            'success',
            "SSL {$updated->domain_name} berhasil dipindai (Status: ".strtoupper($updated->status).', Sisa: '.($updated->days_remaining ?? 'N/A').' hari).',
        );
    }

    /**
     * Scan SSL certificates for all active monitored domains.
     */
    public function scanAll(SslCheckerService $sslChecker): RedirectResponse
    {
        $domains = $sslChecker->checkAllActiveDomains();

        return back()->with('success', "Berhasil memindai ulang {$domains->count()} domain aktif.");
    }
}
