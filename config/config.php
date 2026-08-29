<?php
declare(strict_types=1);

function dollarLoadEnv(string $path): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    if (!is_file($path) || !is_readable($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        $pair = explode('=', $line, 2);
        if (count($pair) !== 2) continue;

        $key = trim($pair[0]);
        $value = trim($pair[1]);
        if ($key === '') continue;

        // Correctly remove matching surrounding quotes only.
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

dollarLoadEnv(dirname(__DIR__) . '/.env');

return [
    'app_name' => 'Dollar Topup Card',
    'webhook_secret' => (string)(getenv('WEBHOOK_SECRET') ?: ''),
    'telegram_bot_token' => (string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''),
    'admin_telegram_ids' => array_values(array_filter(
        array_map('trim', explode(',', (string)(getenv('ADMIN_TELEGRAM_IDS') ?: '')))
    )),
];