<?php

namespace App\Services;

use App\Models\ServerActionLog;
use App\Models\User;
use InvalidArgumentException;

class ServerActionService
{
    /**
     * Queue an asynchronous server action with strict command whitelisting.
     *
     * Avast Behavior Shield Safe Mode:
     * Never calls popen, exec, shell_exec, proc_open, or cmd.exe from PHP.
     * Instead, stores the queued record in `server_action_logs` and writes a local
     * trigger payload to `storage/app/lodis-queue.json` which is picked up every 3 seconds
     * by the background PowerShell worker (`scripts/lodis-worker.ps1`).
     */
    public function dispatchAction(string $actionType, ?User $user = null): ServerActionLog
    {
        if (! in_array($actionType, ServerActionLog::allowedActions(), true)) {
            throw new InvalidArgumentException("Invalid or unauthorized server action: {$actionType}");
        }

        $log = ServerActionLog::query()->create([
            'user_id' => $user?->id,
            'action_type' => $actionType,
            'status' => 'queued',
            'output' => "[Lodis Queue] Action '{$actionType}' queued at ".now()->toDateTimeString().".\n[Lodis Queue] Waiting for background worker (scripts/lodis-worker.ps1) to pick up task...",
            'started_at' => now(),
        ]);

        $this->writeQueueTriggerFile($log);

        return $log;
    }

    /**
     * Resolve the queue trigger file path (uses separate file during automated tests).
     */
    public function getQueueFilePath(): string
    {
        return app()->runningUnitTests()
            ? storage_path('app/lodis-queue-testing.json')
            : storage_path('app/lodis-queue.json');
    }

    /**
     * Write or append the queued action into `storage/app/lodis-queue.json` without spawning any shell process.
     */
    public function writeQueueTriggerFile(ServerActionLog $log): void
    {
        $queueFilePath = $this->getQueueFilePath();
        $directory = dirname($queueFilePath);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $existingItems = [];

        if (is_file($queueFilePath)) {
            $raw = @file_get_contents($queueFilePath);
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $existingItems = $decoded;
                }
            }
        }

        $existingItems = array_values(array_filter(
            $existingItems,
            fn (mixed $item): bool => is_array($item) && (int) ($item['log_id'] ?? 0) !== (int) $log->id,
        ));

        $existingItems[] = [
            'log_id' => (int) $log->id,
            'action' => (string) $log->action_type,
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
        ];

        @file_put_contents(
            $queueFilePath,
            json_encode($existingItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }

    /**
     * Remove a processed log_id from `storage/app/lodis-queue.json`.
     */
    public function removeFromQueueTriggerFile(int $logId): void
    {
        $queueFilePath = $this->getQueueFilePath();

        if (! is_file($queueFilePath)) {
            return;
        }

        $raw = @file_get_contents($queueFilePath);
        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return;
        }

        $remaining = array_values(array_filter(
            $decoded,
            fn (mixed $item): bool => is_array($item) && (int) ($item['log_id'] ?? 0) !== $logId,
        ));

        @file_put_contents(
            $queueFilePath,
            json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }
}
