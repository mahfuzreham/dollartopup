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
    'binance_auto_withdraw' => filter_var((string)(getenv('BINANCE_AUTO_WITHDRAW') ?: 'false'), FILTER_VALIDATE_BOOLEAN),
    'binance_api_key' => (string)(getenv('BINANCE_API_KEY') ?: ''),
    'binance_api_secret' => (string)(getenv('BINANCE_API_SECRET') ?: ''),
    'binance_base_url' => (string)(getenv('BINANCE_BASE_URL') ?: 'https://api.binance.com'),
    'binance_usdt_network' => (string)(getenv('BINANCE_USDT_NETWORK') ?: 'BSC'),
    'binance_wallet_type' => (int)(getenv('BINANCE_WALLET_TYPE') ?: 0),
    'binance_recv_window' => (int)(getenv('BINANCE_RECV_WINDOW') ?: 5000),
    'admin_web_user' => (string)(getenv('ADMIN_WEB_USER') ?: ''),
    'admin_web_pass' => (string)(getenv('ADMIN_WEB_PASS') ?: ''),
    'admin_telegram_ids' => array_values(array_filter(
        array_map('trim', explode(',', (string)(getenv('ADMIN_TELEGRAM_IDS') ?: '')))
    )),
];