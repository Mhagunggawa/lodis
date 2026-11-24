<?php

use App\Mail\SslExpiringMail;
use App\Models\AppSetting;
use App\Models\MonitoredDomain;
use App\Models\ServerActionLog;
use App\Models\TelegramSession;
use App\Models\User;
use App\Services\ServerActionService;
use App\Services\SslCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('health check endpoint returns 200 ok', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

test('ip whitelist middleware allows access when empty and blocks non-whitelisted ip', function () {
    $user = User::factory()->create();

    config(['lodis.admin_ip_whitelist' => '']);
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    config(['lodis.admin_ip_whitelist' => '10.99.99.1, 10.99.99.2']);
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden();

    config(['lodis.admin_ip_whitelist' => '127.0.0.1']);
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('strict command whitelisting accepts reload_webserver and renew_ssl with 202 accepted, writes queue file without cmd.exe, and rejects arbitrary commands', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('server-actions.trigger'), [
            'action' => 'reload_webserver',
        ])
        ->assertStatus(202)
        ->assertJsonPath('action', 'reload_webserver')
        ->assertJsonPath('status', 'queued');

    $logId = (int) $response->json('log_id');
    $queueFilePath = app(ServerActionService::class)->getQueueFilePath();
    expect(is_file($queueFilePath))->toBeTrue();

    $queueContents = json_decode((string) file_get_contents($queueFilePath), true);
    expect($queueContents)->toBeArray()
        ->and(collect($queueContents)->contains(fn ($item) => (int) ($item['log_id'] ?? 0) === $logId && ($item['action'] ?? '') === 'reload_webserver'))->toBeTrue();

    $this->actingAs($user)
        ->postJson(route('server-actions.trigger'), [
            'action' => 'renew_ssl',
        ])
        ->assertStatus(202)
        ->assertJsonPath('action', 'renew_ssl');

    $this->actingAs($user)
        ->postJson(route('server-actions.trigger'), [
            'action' => 'rm -rf / && calc.exe',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['action']);
});

test('internal pending-actions and action-callback endpoints work with X-Internal-Token and clean up queue trigger file', function () {
    config(['lodis.internal_api_token' => 'secret-callback-token']);

    $log = ServerActionLog::factory()->create([
        'action_type' => 'reload_webserver',
        'status' => 'queued',
    ]);

    app(ServerActionService::class)->writeQueueTriggerFile($log);

    $this->withHeader('X-Internal-Token', 'secret-callback-token')
        ->getJson('/api/internal/pending-actions')
        ->assertOk()
        ->assertJsonPath('has_pending', true)
        ->assertJsonPath('item.log_id', $log->id);

    $this->withoutHeader('X-Internal-Token')
        ->postJson('/api/internal/action-callback', [
            'log_id' => $log->id,
            'status' => 'success',
            'output' => 'Reloaded Apache2.4 cleanly',
        ])->assertStatus(401);

    $this->withHeader('X-Internal-Token', 'secret-callback-token')
        ->postJson('/api/internal/action-callback', [
            'log_id' => $log->id,
            'status' => 'success',
            'output' => 'Reloaded Apache2.4 cleanly',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $log->refresh();
    expect($log->status)->toBe('success')
        ->and($log->output)->toContain('Reloaded Apache2.4 cleanly')
        ->and($log->finished_at)->not->toBeNull();

    $queueContents = json_decode((string) file_get_contents(app(ServerActionService::class)->getQueueFilePath()), true);
    expect(collect($queueContents)->contains(fn ($item) => (int) ($item['log_id'] ?? 0) === $log->id))->toBeFalse();
});

test('ssl checker service parses certificate timestamps and assigns valid, warning, and critical badges', function () {
    $service = new SslCheckerService;

    expect($service->determineStatus(30))->toBe('valid')
        ->and($service->determineStatus(14))->toBe('warning')
        ->and($service->determineStatus(8))->toBe('warning')
        ->and($service->determineStatus(7))->toBe('critical')
        ->and($service->determineStatus(-2))->toBe('critical')
        ->and($service->determineStatus(null))->toBe('error');

    $parsed = $service->parseCertificateArray([
        'issuer' => ['O' => "Let's Encrypt", 'CN' => 'R3'],
        'validFrom_time_t' => now()->subDays(20)->getTimestamp(),
        'validTo_time_t' => now()->addDays(5)->addHours(2)->getTimestamp(),
    ]);

    expect($parsed['issuer'])->toBe("Let's Encrypt - R3")
        ->and($parsed['days_remaining'])->toBe(5)
        ->and($parsed['status'])->toBe('critical');
});

test('admin can create, update, and delete monitored domains', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('domains.store'), [
            'domain_name' => 'https://Portal.Instansi.go.id/login',
            'port' => 443,
            'is_active' => false,
        ])
        ->assertRedirect();

    $domain = MonitoredDomain::query()->where('domain_name', 'portal.instansi.go.id')->first();
    expect($domain)->not->toBeNull()
        ->and($domain->port)->toBe(443);

    $this->actingAs($user)
        ->put(route('domains.update', $domain), [
            'domain_name' => 'portal-updated.instansi.go.id',
            'port' => 8443,
            'is_active' => false,
        ])
        ->assertRedirect();

    expect($domain->refresh()->domain_name)->toBe('portal-updated.instansi.go.id')
        ->and($domain->port)->toBe(8443);

    $this->actingAs($user)
        ->delete(route('domains.destroy', $domain))
        ->assertRedirect();

    expect(MonitoredDomain::query()->find($domain->id))->toBeNull();
});

test('ssl:check-reminder command sends notifications for expiring domains and enforces daily anti-spam', function () {
    Mail::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    AppSetting::setValue('telegram_bot_token', '123456:TEST_TOKEN');
    AppSetting::setValue('telegram_chat_id', '-100987654');
    AppSetting::setValue('notification_channel', 'both');
    AppSetting::setValue('notification_email', 'admin@lodis.local');
    AppSetting::setValue('ssl_reminder_thresholds', '14,7,3,1');

    $criticalDomain = MonitoredDomain::factory()->critical(7)->create([
        'domain_name' => 'kritis.instansi.go.id',
        'last_reminded_at' => null,
    ]);

    $safeDomain = MonitoredDomain::factory()->create([
        'domain_name' => 'aman.instansi.go.id',
        'days_remaining' => 45,
        'status' => 'valid',
        'last_reminded_at' => null,
    ]);

    $this->artisan('ssl:check-reminder --skip-scan')
        ->assertExitCode(0);

    Mail::assertSent(SslExpiringMail::class, 1);
    expect($criticalDomain->refresh()->last_reminded_at)->not->toBeNull()
        ->and($safeDomain->refresh()->last_reminded_at)->toBeNull();

    // Running a second time on the same day should be skipped due to anti-spam protection
    $this->artisan('ssl:check-reminder --skip-scan')
        ->assertExitCode(0);

    Mail::assertSent(SslExpiringMail::class, 1);
});

test('interactive telegram webhook authenticates admin, deletes password message, handles inline keyboard callbacks, and locks after 5 failed attempts', function () {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'description' => 'Webhook was set'], 200),
    ]);

    config(['services.telegram.webhook_secret' => 'test-webhook-secret']);
    AppSetting::setValue('telegram_bot_token', '123456:TEST_BOT_TOKEN');

    $admin = User::factory()->create([
        'name' => 'Admin Telegram',
        'email' => 'admin@lodis.local',
        'password' => bcrypt('SecretPass123!'),
    ]);

    // 1. Reject webhook call with missing/invalid secret token
    $this->postJson('/api/telegram/webhook', [
        'message' => ['chat' => ['id' => 998877], 'text' => '/start'],
    ])->assertStatus(401);

    // 2. /start command transitions session to awaiting_email
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
        ->postJson('/api/telegram/webhook', [
            'message' => ['message_id' => 10, 'chat' => ['id' => 998877], 'text' => '/start'],
        ])
        ->assertOk();

    $session = TelegramSession::query()->where('chat_id', '998877')->first();
    expect($session)->not->toBeNull()
        ->and($session->state)->toBe(TelegramSession::STATE_AWAITING_EMAIL);

    // 3. Submit email -> transitions to awaiting_password
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
        ->postJson('/api/telegram/webhook', [
            'message' => ['message_id' => 11, 'chat' => ['id' => 998877], 'text' => 'admin@lodis.local'],
        ])
        ->assertOk();

    expect($session->refresh()->state)->toBe(TelegramSession::STATE_AWAITING_PASSWORD)
        ->and($session->temp_email)->toBe('admin@lodis.local');

    // 4. Submit password -> immediately calls deleteMessage and authenticates for 24 hours
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
        ->postJson('/api/telegram/webhook', [
            'message' => ['message_id' => 12, 'chat' => ['id' => 998877], 'text' => 'SecretPass123!'],
        ])
        ->assertOk();

    expect($session->refresh()->state)->toBe(TelegramSession::STATE_AUTHENTICATED)
        ->and($session->user_id)->toBe($admin->id)
        ->and($session->isAuthenticated())->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/deleteMessage')
        && (int) $request['message_id'] === 12);

    // 5. Trigger Refresh Web Server via inline keyboard callback -> writes to lodis-queue.json without cmd.exe
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
        ->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'cb-1',
                'data' => 'action_refresh_yes',
                'message' => ['chat' => ['id' => 998877]],
            ],
        ])
        ->assertOk();

    expect(ServerActionLog::query()->where('user_id', $admin->id)->where('action_type', 'reload_webserver')->exists())->toBeTrue();

    // 6. Logout via /logout command
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
        ->postJson('/api/telegram/webhook', [
            'message' => ['message_id' => 15, 'chat' => ['id' => 998877], 'text' => '/logout'],
        ])
        ->assertOk();

    expect($session->refresh()->state)->toBe(TelegramSession::STATE_IDLE)
        ->and($session->user_id)->toBeNull();

    // 7. Test 5 failed password attempts lock the session for 15 minutes
    for ($i = 1; $i <= 5; $i++) {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
            ->postJson('/api/telegram/webhook', [
                'message' => ['message_id' => 20 + $i, 'chat' => ['id' => 998877], 'text' => '/login'],
            ]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
            ->postJson('/api/telegram/webhook', [
                'message' => ['message_id' => 30 + $i, 'chat' => ['id' => 998877], 'text' => 'admin@lodis.local'],
            ]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
            ->postJson('/api/telegram/webhook', [
                'message' => ['message_id' => 40 + $i, 'chat' => ['id' => 998877], 'text' => 'WrongPassword!'],
            ]);
    }

    expect($session->refresh()->isLocked())->toBeTrue()
        ->and($session->failed_attempts)->toBe(5);

    // 8. Test telegram:set-webhook command
    $this->artisan('telegram:set-webhook --url=https://lodis.instansi.go.id')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/setWebhook')
        && $request['url'] === 'https://lodis.instansi.go.id/api/telegram/webhook'
        && $request['secret_token'] === 'test-webhook-secret');
});
