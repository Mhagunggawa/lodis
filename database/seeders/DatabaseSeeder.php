<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\MonitoredDomain;
use App\Models\ServerActionLog;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@lodis.local'],
            [
                'name' => 'Administrator Lodis',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'last_login_at' => now(),
            ],
        );

        $defaultSettings = [
            'telegram_bot_token' => (string) config('lodis.telegram_bot_token', ''),
            'telegram_chat_id' => (string) config('lodis.telegram_chat_id', ''),
            'notification_channel' => (string) config('lodis.notification_channel', 'both'),
            'notification_email' => (string) config('lodis.notification_email', 'admin@lodis.local'),
            'ssl_reminder_thresholds' => (string) config('lodis.ssl_reminder_thresholds', '14,7,3,1'),
            'webserver_service_name' => (string) config('lodis.webserver_service_name', 'Apache2.4'),
            'win_acme_path' => (string) config('lodis.win_acme_path', 'C:\win-acme\wacs.exe'),
            'win_acme_task_name' => (string) config('lodis.win_acme_task_name', ''),
        ];

        foreach ($defaultSettings as $key => $value) {
            AppSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        }

        if (MonitoredDomain::query()->count() === 0) {
            MonitoredDomain::query()->create([
                'domain_name' => 'laravel.com',
                'port' => 443,
                'issuer' => 'Cloudflare Inc ECC CA-3',
                'ssl_valid_from' => now()->subDays(30),
                'ssl_expires_at' => now()->addDays(60),
                'days_remaining' => 60,
                'status' => 'valid',
                'last_checked_at' => now(),
                'is_active' => true,
            ]);

            MonitoredDomain::query()->create([
                'domain_name' => 'portal-instansi.go.id',
                'port' => 443,
                'issuer' => "Let's Encrypt (R3)",
                'ssl_valid_from' => now()->subDays(78),
                'ssl_expires_at' => now()->addDays(12),
                'days_remaining' => 12,
                'status' => 'warning',
                'last_checked_at' => now(),
                'is_active' => true,
            ]);

            MonitoredDomain::query()->create([
                'domain_name' => 'sso-internal.instansi.go.id',
                'port' => 443,
                'issuer' => "Let's Encrypt (R3)",
                'ssl_valid_from' => now()->subDays(85),
                'ssl_expires_at' => now()->addDays(5),
                'days_remaining' => 5,
                'status' => 'critical',
                'last_checked_at' => now(),
                'is_active' => true,
            ]);
        }

        if (ServerActionLog::query()->count() === 0) {
            ServerActionLog::query()->create([
                'user_id' => $admin->id,
                'action_type' => ServerActionLog::ACTION_RELOAD_WEBSERVER,
                'status' => 'success',
                'output' => "[Lodis Runner] Target: Laragon Web Server (Apache2.4)\n[INFO] Waiting 3 seconds grace period...\n[INFO] Reloading Apache httpd / Laragon service...\n[SUCCESS] Web server reloaded cleanly (ExitCode: 0).",
                'started_at' => now()->subMinutes(15),
                'finished_at' => now()->subMinutes(15)->addSeconds(4),
            ]);
        }
    }
}
