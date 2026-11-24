<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLodisSettingsRequest;
use App\Models\AppSetting;
use App\Services\TelegramNotifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LodisSettingController extends Controller
{
    /**
     * Display the Lodis Notification & Server Settings page.
     */
    public function index(): Response
    {
        $settings = [
            'telegram_bot_token' => (string) AppSetting::getValue('telegram_bot_token', (string) config('lodis.telegram_bot_token', '')),
            'telegram_chat_id' => (string) AppSetting::getValue('telegram_chat_id', (string) config('lodis.telegram_chat_id', '')),
            'notification_channel' => (string) AppSetting::getValue('notification_channel', (string) config('lodis.notification_channel', 'both')),
            'notification_email' => (string) AppSetting::getValue('notification_email', (string) config('lodis.notification_email', 'admin@lodis.local')),
            'ssl_reminder_thresholds' => (string) AppSetting::getValue('ssl_reminder_thresholds', (string) config('lodis.ssl_reminder_thresholds', '14,7,3,1')),
            'webserver_service_name' => (string) AppSetting::getValue('webserver_service_name', (string) config('lodis.webserver_service_name', 'Apache2.4')),
            'win_acme_path' => (string) AppSetting::getValue('win_acme_path', (string) config('lodis.win_acme_path', 'C:\win-acme\wacs.exe')),
            'win_acme_task_name' => (string) AppSetting::getValue('win_acme_task_name', (string) config('lodis.win_acme_task_name', '')),
            'admin_ip_whitelist' => (string) config('lodis.admin_ip_whitelist', ''),
        ];

        return Inertia::render('LodisSettings/Index', [
            'settings' => $settings,
        ]);
    }

    /**
     * Update Lodis settings in the `app_settings` table.
     */
    public function update(UpdateLodisSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        if (isset($validated['telegram_bot_token'])) {
            $validated['telegram_bot_token'] = (string) preg_replace('/^bot/i', '', trim((string) $validated['telegram_bot_token']));
        }

        foreach ($validated as $key => $value) {
            AppSetting::setValue($key, $value !== null ? trim((string) $value) : '');
        }

        return back()->with('success', 'Pengaturan Lodis berhasil disimpan.');
    }

    /**
     * Trigger a test notification to Telegram Bot API and/or SMTP Email.
     */
    public function testNotification(Request $request, TelegramNotifierService $notifier): JsonResponse
    {
        if ($request->filled('telegram_bot_token')) {
            $cleanToken = (string) preg_replace('/^bot/i', '', trim((string) $request->input('telegram_bot_token')));
            AppSetting::setValue('telegram_bot_token', $cleanToken);
        }

        if ($request->filled('telegram_chat_id')) {
            AppSetting::setValue('telegram_chat_id', trim((string) $request->input('telegram_chat_id')));
        }

        if ($request->filled('notification_channel')) {
            AppSetting::setValue('notification_channel', (string) $request->input('notification_channel'));
        }

        if ($request->filled('notification_email')) {
            AppSetting::setValue('notification_email', trim((string) $request->input('notification_email')));
        }

        $result = $notifier->sendTestNotification();

        return response()->json($result, 200);
    }
}
