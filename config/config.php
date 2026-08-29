<?php
declare(strict_types=1);

return [
    'app_name' => 'Dollar Topup Card',
    'webhook_secret' => getenv('WEBHOOK_SECRET') ?: '',
    'telegram_bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
    'admin_telegram_ids' => array_values(array_filter(array_map('trim', explode(',', getenv('ADMIN_TELEGRAM_IDS') ?: '')))),
];