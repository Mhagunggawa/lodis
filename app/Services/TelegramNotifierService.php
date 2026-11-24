<?php

namespace App\Services;

use App\Mail\SslExpiringMail;
use App\Models\AppSetting;
use App\Models\MonitoredDomain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TelegramNotifierService
{
    /**
     * Send a formatted message via Telegram Bot API.
     *
     * @return array{success: bool, message: string}
     */
    public function sendTelegramMessage(string $text, ?string $overrideToken = null, ?string $overrideChatId = null): array
    {
        $rawToken = $overrideToken ?? AppSetting::getValue('telegram_bot_token', (string) config('lodis.telegram_bot_token', ''));
        $token = (string) preg_replace('/^bot/i', '', trim((string) $rawToken));
        $chatId = trim((string) ($overrideChatId ?? AppSetting::getValue('telegram_chat_id', (string) config('lodis.telegram_chat_id', ''))));

        if (empty($token) || empty($chatId)) {
            return [
                'success' => false,
                'message' => 'Telegram Bot Token atau Chat ID belum dikonfigurasi.',
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful() && $response->json('ok') === true) {
                return [
                    'success' => true,
                    'message' => 'Notifikasi Telegram berhasil dikirim.',
                ];
            }

            $description = (string) $response->json('description', 'Respon Telegram API tidak valid.');

            return [
                'success' => false,
                'message' => "Gagal mengirim Telegram: {$description}",
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Koneksi ke Telegram API gagal: '.$exception->getMessage(),
            ];
        }
    }

    /**
     * Send an SSL expiration alert via configured channels (Telegram Bot API, SMTP Email, or both).
     *
     * @return array{success: bool, channels: array<string, bool>, message: string}
     */
    public function sendDomainExpiryReminder(MonitoredDomain $domain): array
    {
        $channel = (string) AppSetting::getValue('notification_channel', (string) config('lodis.notification_channel', 'both'));
        $recipientEmail = (string) AppSetting::getValue('notification_email', (string) config('lodis.notification_email', 'admin@lodis.local'));

        $days = (int) ($domain->days_remaining ?? 0);
        $expiresDate = $domain->ssl_expires_at?->format('Y-m-d H:i') ?? 'Tidak diketahui';
        $severityLabel = $days <= 7 ? 'KRITIS' : 'PERINGATAN';

        $telegramText = "<b>[LODIS - {$severityLabel} SSL]</b>\n\n"
            ."Domain: <code>{$domain->domain_name}:{$domain->port}</code>\n"
            ."Sisa Masa Aktif: <b>{$days} hari</b>\n"
            ."Kedaluwarsa Pada: <code>{$expiresDate}</code>\n"
            .'Penerbit (Issuer): '.e((string) ($domain->issuer ?? 'N/A'))."\n\n"
            .'Segera lakukan pembaruan sertifikat SSL melalui dashboard Lodis (Renew SSL / win-acme).';

        $channelsResult = [];
        $messages = [];

        if (in_array($channel, ['telegram', 'both'], true)) {
            $tgResult = $this->sendTelegramMessage($telegramText);
            $channelsResult['telegram'] = $tgResult['success'];
            $messages[] = $tgResult['message'];
        }

        if (in_array($channel, ['email', 'both'], true) && $recipientEmail !== '') {
            try {
                Mail::to($recipientEmail)->send(new SslExpiringMail($domain));
                $channelsResult['email'] = true;
                $messages[] = "Email reminder terkirim ke {$recipientEmail}.";
            } catch (Throwable $exception) {
                $channelsResult['email'] = false;
                $messages[] = 'Gagal mengirim email: '.$exception->getMessage();
            }
        }

        $anySuccess = in_array(true, $channelsResult, true);

        return [
            'success' => $anySuccess,
            'channels' => $channelsResult,
            'message' => implode(' | ', $messages),
        ];
    }

    /**
     * Send a test notification across the configured channel(s).
     *
     * @return array{success: bool, message: string}
     */
    public function sendTestNotification(?string $channel = null): array
    {
        $activeChannel = $channel ?? (string) AppSetting::getValue('notification_channel', 'both');
        $recipientEmail = (string) AppSetting::getValue('notification_email', 'admin@lodis.local');

        $results = [];
        $succeeded = false;

        if (in_array($activeChannel, ['telegram', 'both'], true)) {
            $testText = "<b>[Lodis — Web Server &amp; SSL Manager]</b>\n"
                ."Notifikasi uji coba berhasil terhubung!\n"
                .'Server Target: <code>Laragon Windows Server</code>'
                ."\nWaktu Server: <code>".now()->format('Y-m-d H:i:s').'</code>';

            $tgResult = $this->sendTelegramMessage($testText);
            $results[] = $tgResult['message'];
            if ($tgResult['success']) {
                $succeeded = true;
            }
        }

        if (in_array($activeChannel, ['email', 'both'], true)) {
            try {
                $dummyDomain = new MonitoredDomain([
                    'domain_name' => 'test-lodis.instansi.go.id',
                    'port' => 443,
                    'issuer' => "Let's Encrypt (Test Notification)",
                    'ssl_valid_from' => now()->subDays(83),
                    'ssl_expires_at' => now()->addDays(7),
                    'days_remaining' => 7,
                    'status' => 'critical',
                ]);

                Mail::to($recipientEmail)->send(new SslExpiringMail($dummyDomain, true));
                $results[] = "Email uji coba berhasil dikirim ke {$recipientEmail}.";
                $succeeded = true;
            } catch (Throwable $exception) {
                $results[] = 'Gagal mengirim email uji coba: '.$exception->getMessage();
            }
        }

        return [
            'success' => $succeeded,
            'message' => implode(' ', $results),
        ];
    }
}
