<?php

namespace App\Http\Controllers;

use App\Http\Requests\TriggerServerActionRequest;
use App\Models\ServerActionLog;
use App\Services\ServerActionService;
use App\Services\SslCheckerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServerActionController extends Controller
{
    /**
     * Display the Audit Log & Terminal Output Viewer page.
     */
    public function index(Request $request): Response
    {
        $status = trim((string) $request->query('status', ''));
        $actionType = trim((string) $request->query('action_type', ''));
        $search = trim((string) $request->query('search', ''));

        $logs = ServerActionLog::query()
            ->with('user:id,name,email')
            ->when($status !== '' && $status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($actionType !== '' && $actionType !== 'all', function ($query) use ($actionType) {
                $query->where('action_type', $actionType);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where('output', 'like', "%{$search}%");
            })
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Logs/Index', [
            'logs' => $logs,
            'filters' => [
                'status' => $status ?: 'all',
                'action_type' => $actionType ?: 'all',
                'search' => $search,
            ],
        ]);
    }

    /**
     * Trigger a whitelisted asynchronous server action (`reload_webserver` or `renew_ssl`).
     * Immediately returns HTTP 202 Accepted with log_id while PowerShell sleeps 3 seconds.
     */
    public function trigger(TriggerServerActionRequest $request, ServerActionService $actionService): JsonResponse
    {
        $action = (string) $request->validated('action');
        $log = $actionService->dispatchAction($action, $request->user());

        return response()->json([
            'message' => 'Aksi server telah dijadwalkan (asinkron jeda 3 detik).',
            'log_id' => $log->id,
            'action' => $log->action_type,
            'status' => $log->status,
        ], 202);
    }

    /**
     * Check the status of a specific ServerActionLog entry.
     */
    public function status(ServerActionLog $log): JsonResponse
    {
        return response()->json([
            'id' => $log->id,
            'action_type' => $log->action_type,
            'status' => $log->status,
            'output' => $log->output,
            'started_at' => $log->started_at?->toIso8601String(),
            'finished_at' => $log->finished_at?->toIso8601String(),
        ]);
    }

    /**
     * Return the oldest pending queued action for `scripts/lodis-worker.ps1` (protected by X-Internal-Token).
     */
    public function pending(): JsonResponse
    {
        $pendingLog = ServerActionLog::query()
            ->where('status', 'queued')
            ->oldest('id')
            ->first();

        if (! $pendingLog) {
            return response()->json([
                'has_pending' => false,
                'item' => null,
            ]);
        }

        return response()->json([
            'has_pending' => true,
            'item' => [
                'log_id' => $pendingLog->id,
                'action' => $pendingLog->action_type,
                'status' => $pendingLog->status,
            ],
        ]);
    }

    /**
     * Internal callback endpoint called by `scripts/lodis-worker.ps1` with X-Internal-Token.
     */
    public function callback(Request $request, SslCheckerService $sslChecker, ServerActionService $actionService): JsonResponse
    {
        $validated = $request->validate([
            'log_id' => ['required', 'integer', 'exists:server_action_logs,id'],
            'status' => ['required', 'string', Rule::in(['queued', 'running', 'success', 'failed'])],
            'output' => ['nullable', 'string'],
        ]);

        /** @var ServerActionLog $log */
        $log = ServerActionLog::query()->findOrFail((int) $validated['log_id']);

        $updateData = [
            'status' => $validated['status'],
            'output' => $validated['output'] ?? $log->output,
        ];

        if (in_array($validated['status'], ['success', 'failed'], true)) {
            $updateData['finished_at'] = now();
            $actionService->removeFromQueueTriggerFile((int) $log->id);
        }

        $log->update($updateData);

        if ($log->action_type === ServerActionLog::ACTION_RENEW_SSL && $validated['status'] === 'success') {
            $sslChecker->checkAllActiveDomains();
        }

        return response()->json([
            'ok' => true,
            'log_id' => $log->id,
            'status' => $log->status,
        ]);
    }
}
