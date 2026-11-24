<?php

namespace App\Console\Commands;

use App\Services\TelegramNotifierService;
use Illuminate\Console\Command;

class TelegramSetWebhookCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:set-webhook {--url= : Custom public HTTPS base URL or full webhook URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register APP_URL/api/telegram/webhook along with TELEGRAM_WEBHOOK_SECRET to the Telegram Bot API.';

    /**
     * Execute the console command.
     */
    public function handle(TelegramNotifierService $telegram): int
    {
        $customUrl = trim((string) $this->option('url'));
        $baseUrl = $customUrl !== ''
            ? rtrim($customUrl, '/')
            : rtrim((string) config('app.url', 'http://localhost:8000'), '/');

        $webhookUrl = str_ends_with($baseUrl, '/api/telegram/webhook')
            ? $baseUrl
            : "{$baseUrl}/api/telegram/webhook";

        $secretToken = (string) config('services.telegram.webhook_secret', '');

        if ($secretToken === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET belum diatur di .env atau config/services.php.');

            return self::FAILURE;
        }

        $this->info("Mendaftarkan Telegram Webhook ke: {$webhookUrl}");

        $result = $telegram->setWebhook($webhookUrl, $secretToken);

        if ($result['success']) {
            $this->info("Berhasil! {$result['message']}");

            return self::SUCCESS;
        }

        $this->error($result['message']);

        return self::FAILURE;
    }
}
