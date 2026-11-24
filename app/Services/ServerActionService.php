<?php

namespace App\Services;

use App\Models\ServerActionLog;
use App\Models\User;
use InvalidArgumentException;

class ServerActionService
{
    /**
     * Queue and trigger an asynchronous PowerShell action with strict command whitelisting.
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
            'output' => "[Lodis] Action '{$actionType}' queued at ".now()->toDateTimeString().".\n[Lodis] Launching detached PowerShell runner (3-second grace period)...",
            'started_at' => now(),
        ]);

        $this->spawnDetachedPowerShell($actionType, (int) $log->id);

        return $log;
    }

    /**
     * Spawn the detached PowerShell script on Windows without blocking the HTTP response.
     */
    protected function spawnDetachedPowerShell(string $actionType, int $logId): void
    {
        if (! in_array($actionType, ServerActionLog::allowedActions(), true)) {
            throw new InvalidArgumentException('Disallowed action key.');
        }

        if ($logId <= 0) {
            throw new InvalidArgumentException('Log ID must be a positive integer.');
        }

        if (app()->runningUnitTests()) {
            return;
        }

        $scriptPath = base_path('scripts/server-runner.ps1');

        if (! is_file($scriptPath)) {
            $log = ServerActionLog::query()->find($logId);
            $log?->update([
                'status' => 'failed',
                'output' => ($log->output ?? '')."\n[ERROR] PowerShell script not found at: {$scriptPath}",
                'finished_at' => now(),
            ]);

            return;
        }

        $escapedScript = escapeshellarg($scriptPath);
        $escapedAction = escapeshellarg($actionType);

        if (PHP_OS_FAMILY === 'Windows') {
            $command = sprintf(
                'start /B powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File %s -Action %s -LogId %d > NUL 2>&1',
                $escapedScript,
                $escapedAction,
                $logId,
            );

            $handle = @popen($command, 'r');
            if (is_resource($handle)) {
                pclose($handle);
            }
        }
    }
}
