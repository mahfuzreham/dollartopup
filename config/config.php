<?php
declare(strict_types=1);

function dollarLoadEnv(string $path): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    if (!is_file($path) || !is_readable($path)) return;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pair = explode('=', $line, 2);
        if (count($pair) !== 2) continue;
        $key = trim($pair[0]);
        $value = trim($pair[1]);
        if ($key === '') continue;
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

dollarLoadEnv(dirname(__DIR__) . '/.env');

return [
    'app_name' => 'Dollar Topup Card',
    'webhook_secret' => getenv('WEBHOOK_SECRET') ?: '',
    'telegram_bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
    'admin_telegram_ids' => array_values(array_filter(array_map('trim', explode(',', getenv('ADMIN_TELEGRAM_IDS') ?: '')))),
];