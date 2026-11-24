<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\MonitoredDomain;
use App\Services\SslCheckerService;
use App\Services\TelegramNotifierService;
use Illuminate\Console\Command;

class SslCheckReminderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ssl:check-reminder {--skip-scan : Skip live OpenSSL socket scan and evaluate existing database values}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect all active monitored domains and send SSL expiration reminders via Telegram Bot API / Email with daily anti-spam protection.';

    /**
     * Execute the console command.
     */
    public function handle(SslCheckerService $sslChecker, TelegramNotifierService $notifier): int
    {
        $rawThresholds = (string) AppSetting::getValue(
            'ssl_reminder_thresholds',
            (string) config('lodis.ssl_reminder_thresholds', '14,7,3,1'),
        );

        $thresholds = collect(explode(',', $rawThresholds))
            ->map(fn (string $val): int => (int) trim($val))
            ->filter(fn (int $val): bool => $val > 0)
            ->sortDesc()
            ->values()
            ->all();

        $maxThreshold = ! empty($thresholds) ? max($thresholds) : 14;

        $domains = MonitoredDomain::query()
            ->where('is_active', true)
            ->orderBy('domain_name')
            ->get();

        if ($domains->isEmpty()) {
            $this->info('Tidak ada domain aktif yang dipantau.');

            return self::SUCCESS;
        }

        $this->info("Memeriksa {$domains->count()} domain aktif (Ambang batas reminder: <= {$maxThreshold} hari ["
            .implode(', ', $thresholds).'])...');

        $remindedCount = 0;

        foreach ($domains as $domain) {
            if (! $this->option('skip-scan')) {
                $domain = $sslChecker->checkDomain($domain);
            }

            $days = $domain->days_remaining;

            if ($days === null) {
                $this->warn("- {$domain->domain_name}: Gagal membaca sertifikat SSL (status: {$domain->status}).");

                continue;
            }

            $matchesThreshold = in_array($days, $thresholds, true) || $days <= $maxThreshold;

            if (! $matchesThreshold) {
                $this->line("- {$domain->domain_name}: Aman ({$days} hari tersisa).");

                continue;
            }

            if ($domain->last_reminded_at !== null && $domain->last_reminded_at->isToday()) {
                $this->line("- {$domain->domain_name}: Sudah dikirim pengingat hari ini ({$domain->last_reminded_at->format('H:i')}), dilewati (anti-spam).");

                continue;
            }

            $result = $notifier->sendDomainExpiryReminder($domain);

            if ($result['success']) {
                $domain->update([
                    'last_reminded_at' => now(),
                ]);
                $remindedCount++;
                $this->info("- {$domain->domain_name} ({$days} hari): Pengingat terkirim! ({$result['message']})");
            } else {
                $this->error("- {$domain->domain_name} ({$days} hari): Gagal mengirim pengingat ({$result['message']})");
            }
        }

        $this->info("Selesai. Total pengingat terkirim: {$remindedCount}.");

        return self::SUCCESS;
    }
}
