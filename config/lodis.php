<?php

return [
    'internal_api_token' => env('INTERNAL_API_TOKEN', 'lodis-internal-secret-token'),
    'admin_ip_whitelist' => env('ADMIN_IP_WHITELIST', ''),
    'webserver_target' => env('WEBSERVER_TARGET', 'Laragon (Apache / Nginx)'),
    'webserver_service_name' => env('WEBSERVER_SERVICE_NAME', 'Apache2.4'),
    'win_acme_path' => env('WIN_ACME_PATH', 'C:\win-acme\wacs.exe'),
    'win_acme_task_name' => env('WIN_ACME_TASK_NAME', ''),
    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'telegram_chat_id' => env('TELEGRAM_CHAT_ID', ''),
    'notification_channel' => env('SSL_NOTIFICATION_CHANNEL', 'both'),
    'notification_email' => env('SSL_NOTIFICATION_EMAIL', 'admin@lodis.local'),
    'ssl_reminder_thresholds' => env('SSL_REMINDER_THRESHOLDS', '14,7,3,1'),
];
