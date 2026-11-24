<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoredDomain;
use App\Models\ServerActionLog;
use App\Models\TelegramSession;
use App\Models\User;
use App\Services\ServerActionService;
use App\Services\ServerMetricsService;
use App\Services\SslCheckerService;
use App\Services\TelegramNotifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramNotifierService $telegram,
        protected ServerMetricsService $metricsService,
        protected SslCheckerService $sslChecker,
        protected ServerActionService $actionService,
    ) {}

    /**
     * Handle incoming Telegram Bot Webhook updates (`POST /api/telegram/webhook`).
     */
    public function handle(Request $request): JsonResponse
    {
        $expectedSecret = (string) config('services.telegram.webhook_secret', '');
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expectedSecret === '' || $providedSecret === '' || ! hash_equals($expectedSecret, $providedSecret)) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized: invalid X-Telegram-Bot-Api-Secret-Token.',
            ], 401);
        }

        if ($request->has('callback_query')) {
            /** @var array<string, mixed> $callbackQuery */
            $callbackQuery = (array) $request->input('callback_query', []);
            $this->handleCallbackQuery($callbackQuery);

            return response()->json(['ok' => true]);
        }

        if ($request->has('message')) {
            /** @var array<string, mixed> $message */
            $message = (array) $request->input('message', []);
            $this->handleMessage($message);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Handle incoming text message or slash command from Telegram user.
     *
     * @param  array<string, mixed>  $message
     */
    protected function handleMessage(array $message): void
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = isset($chat['id']) ? (string) $chat['id'] : '';
        $messageId = isset($message['message_id']) ? (int) $message['message_id'] : 0;
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === '' || $text === '') {
            return;
        }

        $session = TelegramSession::query()->firstOrCreate(
            ['chat_id' => $chatId],
            [
                'state' => TelegramSession::STATE_IDLE,
                'failed_attempts' => 0,
            ],
        );

        if ($session->isLocked()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($session->locked_until, false) / 60));

            if ($session->state === TelegramSession::STATE_AWAITING_PASSWORD && $messageId > 0) {
                $this->telegram->deleteMessage($chatId, $messageId);
            }

            $this->telegram->sendChatMessage(
                $chatId,
                "⛔ <b>Akses Terkunci Sementara</b>\nTerlalu banyak percobaan login gagal. Silakan coba kembali dalam <b>{$minutes} menit</b>.",
            );

            return;
        }

        if (str_starts_with($text, '/logout')) {
            $this->performLogout($session);

            return;
        }

        if (str_starts_with($text, '/start') || str_starts_with($text, '/login')) {
            if ($session->isAuthenticated()) {
                $this->sendMainMenu($session, '✅ Anda sudah masuk sebagai <b>'.e((string) $session->user?->name).'</b>.');

                return;
            }

            $session->update([
                'state' => TelegramSession::STATE_AWAITING_EMAIL,
                'temp_email' => null,
            ]);

            $this->telegram->sendChatMessage(
                $chatId,
                "🟣 <b>Selamat Datang di Lodis — Web Server &amp; SSL Manager</b>\n\nSilakan masukkan <b>Email Administrator</b> Anda untuk masuk:",
            );

            return;
        }

        if ($session->state === TelegramSession::STATE_AWAITING_EMAIL) {
            if (! filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $this->telegram->sendChatMessage(
                    $chatId,
                    '⚠️ Format email tidak valid. Silakan masukkan alamat email administrator yang benar:',
                );

                return;
            }

            $session->update([
                'temp_email' => strtolower($text),
                'state' => TelegramSession::STATE_AWAITING_PASSWORD,
            ]);

            $this->telegram->sendChatMessage(
                $chatId,
                '🔑 Email diterima (<code>'.e(strtolower($text))."</code>).\nSekarang masukkan <b>Kata Sandi (Password)</b> Anda:\n<i>(Pesan password Anda akan langsung dihapus otomatis demi keamanan)</i>",
            );

            return;
        }

        if ($session->state === TelegramSession::STATE_AWAITING_PASSWORD) {
            // Immediately delete user's plaintext password message from Telegram chat
            if ($messageId > 0) {
                $this->telegram->deleteMessage($chatId, $messageId);
            }

            $user = User::query()
                ->where('email', (string) $session->temp_email)
                ->first();

            if (! $user || ! Hash::check($text, $user->password)) {
                $attempts = $session->failed_attempts + 1;

                if ($attempts >= 5) {
                    $session->update([
                        'state' => TelegramSession::STATE_IDLE,
                        'temp_email' => null,
                        'failed_attempts' => $attempts,
                        'locked_until' => now()->addMinutes(15),
                    ]);

                    $this->telegram->sendChatMessage(
                        $chatId,
                        "⛔ <b>Login Gagal 5 Kali!</b>\nSesi Telegram Anda dikunci selama <b>15 menit</b> demi keamanan server.",
                    );

                    return;
                }

                $remaining = 5 - $attempts;
                $session->update([
                    'state' => TelegramSession::STATE_AWAITING_EMAIL,
                    'temp_email' => null,
                    'failed_attempts' => $attempts,
                ]);

                $this->telegram->sendChatMessage(
                    $chatId,
                    "❌ <b>Email atau Kata Sandi salah!</b> (Sisa kesempatan: <b>{$remaining}x</b>).\n\nSilakan masukkan kembali <b>Email Administrator</b> Anda:",
                );

                return;
            }

            $session->update([
                'user_id' => $user->id,
                'state' => TelegramSession::STATE_AUTHENTICATED,
                'temp_email' => null,
                'failed_attempts' => 0,
                'locked_until' => null,
                'authenticated_at' => now(),
                'expires_at' => now()->addHours(24),
            ]);

            $user->update(['last_login_at' => now()]);

            $this->sendMainMenu(
                $session,
                "✅ <b>Autentikasi Berhasil!</b>\nSelamat datang, <b>".e($user->name)."</b>.\nSesi aktif selama 24 jam (hingga <code>".now()->addHours(24)->format('d M Y H:i').'</code>).',
            );

            return;
        }

        if ($session->isAuthenticated()) {
            $this->sendMainMenu($session, 'Silakan pilih menu kendali server di bawah ini:');

            return;
        }

        $this->telegram->sendChatMessage(
            $chatId,
            "🔒 Sesi Anda belum terautentikasi atau telah berakhir.\nKetik /login atau /start untuk masuk ke panel kendali Lodis.",
        );
    }

    /**
     * Handle inline keyboard callback query actions.
     *
     * @param  array<string, mixed>  $callbackQuery
     */
    protected function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $data = (string) ($callbackQuery['data'] ?? '');
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = isset($chat['id']) ? (string) $chat['id'] : '';

        if ($chatId === '') {
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $session = TelegramSession::query()->where('chat_id', $chatId)->first();

        if (! $session || ! $session->isAuthenticated()) {
            if ($session && $session->state === TelegramSession::STATE_AUTHENTICATED) {
                $session->update([
                    'state' => TelegramSession::STATE_IDLE,
                    'user_id' => null,
                ]);
            }

            $this->telegram->sendChatMessage(
                $chatId,
                '🔒 Sesi Anda telah berakhir (24 jam). Silakan ketik /login untuk masuk kembali.',
            );

            return;
        }

        switch ($data) {
            case 'menu_domains':
                $this->sendDomainsList($session);
                break;

            case 'menu_metrics':
                $this->sendServerMetrics($session);
                break;

            case 'menu_scan_ssl':
                $this->runSslRescan($session);
                break;

            case 'menu_audit_logs':
                $this->sendRecentAuditLogs($session);
                break;

            case 'menu_refresh_confirm':
                $this->sendRefreshConfirmation($session);
                break;

            case 'action_refresh_yes':
                $this->queueWebserverReload($session);
                break;

            case 'action_refresh_cancel':
                $this->sendMainMenu($session, '❎ Aksi Refresh Web Server dibatalkan.');
                break;

            case 'menu_logout':
                $this->performLogout($session);
                break;

            default:
                $this->sendMainMenu($session);
                break;
        }
    }

    /**
     * Send the main inline keyboard control menu to an authenticated Telegram session.
     */
    protected function sendMainMenu(TelegramSession $session, ?string $headerText = null): void
    {
        $text = ($headerText ? $headerText."\n\n" : '')
            ."🟣 <b>Panel Kendali Lodis — Single-Server Windows</b>\n"
            .'Pilih aksi yang ingin Anda jalankan:';

        $this->telegram->sendChatMessage(
            $session->chat_id,
            $text,
            $this->mainInlineKeyboard(),
        );
    }

    /**
     * Build the main inline keyboard structure.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    protected function mainInlineKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔒 Daftar Domain & SSL', 'callback_data' => 'menu_domains'],
                    ['text' => '📊 Metrik Server', 'callback_data' => 'menu_metrics'],
                ],
                [
                    ['text' => '🔍 Scan Ulang SSL', 'callback_data' => 'menu_scan_ssl'],
                    ['text' => '📜 Audit Log Terbaru', 'callback_data' => 'menu_audit_logs'],
                ],
                [
                    ['text' => '🔄 Refresh Web Server', 'callback_data' => 'menu_refresh_confirm'],
                    ['text' => '🚪 Logout', 'callback_data' => 'menu_logout'],
                ],
            ],
        ];
    }

    /**
     * Send list of monitored domains and SSL validity.
     */
    protected function sendDomainsList(TelegramSession $session): void
    {
        $domains = MonitoredDomain::query()
            ->orderByRaw('CASE WHEN days_remaining IS NULL THEN 1 ELSE 0 END')
            ->orderBy('days_remaining')
            ->get();

        if ($domains->isEmpty()) {
            $this->sendMainMenu($session, 'ℹ️ Belum ada domain yang terdaftar di database.');

            return;
        }

        $lines = ["🔒 <b>Daftar Domain &amp; Status SSL ({$domains->count()})</b>\n"];

        foreach ($domains as $domain) {
            $badge = match ($domain->status) {
                'valid' => '🟢',
                'warning' => '🟡',
                default => '🔴',
            };
            $days = $domain->days_remaining !== null ? "{$domain->days_remaining} hari" : 'N/A';
            $exp = $domain->ssl_expires_at?->format('d M Y') ?? '-';
            $lines[] = "{$badge} <code>{$domain->domain_name}:{$domain->port}</code> — <b>{$days}</b> (Exp: {$exp})";
        }

        $this->sendMainMenu($session, implode("\n", $lines));
    }

    /**
     * Send Windows server resource metrics read from `storage/app/lodis-metrics.json` (zero cmd.exe).
     */
    protected function sendServerMetrics(TelegramSession $session): void
    {
        $metrics = $this->metricsService->getMetrics();
        $statusBadge = $metrics['webserver_online'] ? '🟢 ONLINE' : '🔴 OFFLINE';

        $text = "📊 <b>Metrik Resource Server Windows</b>\n\n"
            ."• <b>Status Web Server:</b> {$statusBadge} (<code>".e($metrics['webserver_name'])."</code>)\n"
            .'• <b>Sistem Operasi:</b> '.e($metrics['os_name'])."\n"
            ."• <b>CPU Load:</b> <b>{$metrics['cpu_percent']}%</b>\n"
            ."• <b>RAM Memory:</b> <b>{$metrics['ram_percent']}%</b> ({$metrics['ram_used_gb']} / {$metrics['ram_total_gb']} GB)\n"
            ."• <b>Penyimpanan Disk:</b> <b>{$metrics['disk_percent']}%</b> ({$metrics['disk_used_gb']} / {$metrics['disk_total_gb']} GB)\n"
            ."• <b>Uptime:</b> {$metrics['uptime_hours']} jam\n"
            ."• <b>Diperbarui:</b> <code>{$metrics['collected_at']}</code>";

        $this->sendMainMenu($session, $text);
    }

    /**
     * Re-scan all active monitored domains via native PHP OpenSSL (`SslCheckerService`).
     */
    protected function runSslRescan(TelegramSession $session): void
    {
        $domains = $this->sslChecker->checkAllActiveDomains();

        $this->sendMainMenu(
            $session,
            "🔍 <b>Pemindaian SSL Selesai!</b>\nBerhasil memindai ulang <b>{$domains->count()} domain aktif</b> menggunakan PHP OpenSSL.",
        );
    }

    /**
     * Send the 5 most recent entries from `server_action_logs`.
     */
    protected function sendRecentAuditLogs(TelegramSession $session): void
    {
        $logs = ServerActionLog::query()
            ->with('user:id,name')
            ->latest('id')
            ->limit(5)
            ->get();

        if ($logs->isEmpty()) {
            $this->sendMainMenu($session, '📜 Belum ada riwayat aksi pada Audit Log.');

            return;
        }

        $lines = ["📜 <b>5 Riwayat Audit Log Terbaru</b>\n"];

        foreach ($logs as $log) {
            $icon = match ($log->status) {
                'success' => '✅',
                'failed' => '❌',
                default => '⏳',
            };
            $time = $log->created_at?->format('d M H:i:s') ?? '-';
            $actor = e((string) ($log->user?->name ?? 'System'));
            $lines[] = "{$icon} <b>#{$log->id}</b> <code>{$log->action_type}</code> ({$log->status}) — {$actor} [<code>{$time}</code>]";
        }

        $this->sendMainMenu($session, implode("\n", $lines));
    }

    /**
     * Prompt confirmation Yes/Cancel before queuing a web server reload.
     */
    protected function sendRefreshConfirmation(TelegramSession $session): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Ya, Refresh Sekarang', 'callback_data' => 'action_refresh_yes'],
                    ['text' => '❎ Batal', 'callback_data' => 'action_refresh_cancel'],
                ],
            ],
        ];

        $this->telegram->sendChatMessage(
            $session->chat_id,
            "⚠️ <b>Konfirmasi Refresh Web Server</b>\n\nApakah Anda yakin ingin menjadwalkan <code>reload_webserver</code> ke antrean Background Worker?",
            $keyboard,
        );
    }

    /**
     * Queue `reload_webserver` via `ServerActionService::dispatchAction()` into `lodis-queue.json` (zero cmd.exe).
     */
    protected function queueWebserverReload(TelegramSession $session): void
    {
        $log = $this->actionService->dispatchAction(
            ServerActionLog::ACTION_RELOAD_WEBSERVER,
            $session->user,
        );

        $this->sendMainMenu(
            $session,
            "🔄 <b>Refresh Web Server Dijadwalkan!</b>\n"
            ."• <b>ID Log:</b> <code>#{$log->id}</code>\n"
            ."• <b>Status:</b> <code>{$log->status}</code>\n"
            .'Tugas telah ditulis ke <code>storage/app/lodis-queue.json</code> dan akan dieksekusi oleh <code>lodis-worker.ps1</code> dalam &le; 3 detik.',
        );
    }

    /**
     * Log out the current Telegram session.
     */
    protected function performLogout(TelegramSession $session): void
    {
        $session->update([
            'user_id' => null,
            'state' => TelegramSession::STATE_IDLE,
            'temp_email' => null,
            'authenticated_at' => null,
            'expires_at' => null,
        ]);

        $this->telegram->sendChatMessage(
            $session->chat_id,
            "🚪 <b>Anda telah keluar (Logout).</b>\nKetik /login atau /start kapan saja untuk masuk kembali.",
        );
    }
}
