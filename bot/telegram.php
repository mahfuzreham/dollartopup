<?php
declare(strict_types=1);

/*
 * Telegram webhook endpoint.
 * Optional security: set TELEGRAM_WEBHOOK_SECRET in .env and register the
 * webhook with Telegram secret_token using the same value.
 */
$config = require __DIR__ . '/../config/config.php';

$secret = (string)(getenv('TELEGRAM_WEBHOOK_SECRET') ?: '');
if ($secret !== '') {
    $provided = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

try {
    $db = require __DIR__ . '/../config/database.php';
    $token = (string)($config['telegram_bot_token'] ?? '');
    $admins = array_values(array_filter(array_map('strval', $config['admin_telegram_ids'] ?? [])));

    if ($token === '' || $admins === []) {
        throw new RuntimeException('Telegram bot configuration missing');
    }

    $raw = file_get_contents('php://input') ?: '';
    $update = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $message = $update['message'] ?? null;

    if (!is_array($message)) {
        echo 'OK';
        exit;
    }

    $chatId = (string)($message['chat']['id'] ?? '');
    $userId = (string)($message['from']['id'] ?? '');
    $text = trim((string)($message['text'] ?? ''));

    function tgSend(string $token, string $chatId, string $text): void {
        if ($chatId === '') return;
        $payload = http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
            'content' => $payload,
            'timeout' => 12,
            'ignore_errors' => true,
        ]]);
        @file_get_contents('https://api.telegram.org/bot' . $token . '/sendMessage', false, $context);
    }

    function esc(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function setting(PDO $db, string $key, string $default = ''): string {
        $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }

    function saveSetting(PDO $db, string $key, string $value): void {
        $stmt = $db->prepare(
            'INSERT INTO settings(setting_key,setting_value) VALUES(?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);
    }

    if (!in_array($userId, $admins, true)) {
        tgSend($token, $chatId, '⛔ Unauthorized');
        echo 'OK';
        exit;
    }

    $parts = preg_split('/\s+/', $text) ?: [];
    $command = strtolower(explode('@', $parts[0] ?? '')[0]);
    $arg = trim(implode(' ', array_slice($parts, 1)));

    switch ($command) {
        case '/start':
        case '/help':
            tgSend($token, $chatId, "💳 <b>Dollar Topup Admin</b>\n\n💵 /price\n/setprice 125\n🏦 /setbkash PAYMENT_TEXT\n/setbank PAYMENT_TEXT\n/paymentmethods\n📋 /orders\n/order ORDER_NO\n📊 /history\n✅ /approve ORDER_NO\n❌ /reject ORDER_NO\n💸 /withdraw ORDER_NO\n/queue\n/withdrawstatus ORDER_NO");
            break;

        case '/price':
            tgSend($token, $chatId, '💵 Price: <b>' . esc(setting($db, 'dollar_price_bdt', '120')) . ' BDT/USD</b>');
            break;

        case '/setprice':
            $price = (float)($parts[1] ?? 0);
            if ($price <= 0 || $price > 1000000) {
                tgSend($token, $chatId, 'Usage: /setprice 125');
                break;
            }
            saveSetting($db, 'dollar_price_bdt', number_format($price, 4, '.', ''));
            tgSend($token, $chatId, '✅ Price updated');
            break;

        case '/setbkash':
        case '/setbank':
            if ($arg === '') {
                tgSend($token, $chatId, 'Usage: ' . $command . ' PAYMENT_TEXT');
                break;
            }
            saveSetting($db, $command === '/setbkash' ? 'bkash_instructions' : 'bank_instructions', $arg);
            tgSend($token, $chatId, '✅ Payment instructions saved');
            break;

        case '/paymentmethods':
            tgSend($token, $chatId, "🏦 <b>Payment Methods</b>\n\nbKash: " . (setting($db, 'bkash_instructions') !== '' ? 'Configured' : 'Not set') . "\nBank: " . (setting($db, 'bank_instructions') !== '' ? 'Configured' : 'Not set'));
            break;

        case '/orders':
            $rows = $db->query("SELECT order_no,total_bdt,status,withdrawal_status FROM orders WHERE created_at >= NOW()-INTERVAL 90 DAY ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            $out = $rows ? "📋 <b>Orders</b>\n\n" : '📭 No orders.';
            foreach ($rows as $row) {
                $out .= '<b>' . esc((string)$row['order_no']) . '</b> | ' . number_format((float)$row['total_bdt'], 2) . ' BDT | ' . esc((string)$row['status']) . ' | WD: ' . esc((string)($row['withdrawal_status'] ?? 'not_requested')) . "\n";
            }
            tgSend($token, $chatId, $out);
            break;

        case '/approve':
        case '/reject':
            if ($arg === '') { tgSend($token, $chatId, "Usage: $command ORDER_NO"); break; }
            $status = $command === '/approve' ? 'approved' : 'rejected';
            $stmt = $db->prepare("UPDATE orders SET status = ? WHERE order_no = ? AND status IN ('pending','paid','failed')");
            $stmt->execute([$status, $arg]);
            tgSend($token, $chatId, $stmt->rowCount() ? "✅ " . esc($arg) . " → $status" : '❌ Order not found or already finalized');
            break;

        case '/withdraw':
            if ($arg === '') { tgSend($token, $chatId, 'Usage: /withdraw ORDER_NO'); break; }
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT order_no,usd_amount,bep20_address FROM orders WHERE order_no = ? AND status='approved' AND bep20_address IS NOT NULL AND bep20_address <> '' AND withdrawal_status='not_requested' FOR UPDATE");
                $stmt->execute([$arg]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) throw new RuntimeException('Order is not eligible for withdrawal');

                $queue = $db->prepare("INSERT INTO withdrawal_requests(order_no,destination_address,amount,status) VALUES(?,?,?,'queued')");
                $queue->execute([$order['order_no'], $order['bep20_address'], $order['usd_amount']]);

                $update = $db->prepare("UPDATE orders SET withdrawal_status='queued',withdrawal_requested_at=NOW() WHERE order_no=? AND withdrawal_status='not_requested'");
                $update->execute([$arg]);
                $db->commit();
                tgSend($token, $chatId, '💸 Withdrawal request queued: <b>' . esc($arg) . '</b>\nStatus: queued');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Dollar Topup withdrawal queue: ' . $e->getMessage());
                tgSend($token, $chatId, '❌ Withdrawal could not be queued. Check order status.');
            }
            break;

        case '/queue':
            $rows = $db->query("SELECT order_no,amount,status FROM withdrawal_requests WHERE status IN ('queued','processing') ORDER BY id ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            $out = $rows ? "💸 <b>Withdrawal Queue</b>\n\n" : 'Queue empty.';
            foreach ($rows as $row) $out .= esc((string)$row['order_no']) . ' | ' . esc((string)$row['amount']) . ' USDT | ' . esc((string)$row['status']) . "\n";
            tgSend($token, $chatId, $out);
            break;

        case '/withdrawstatus':
            if ($arg === '') { tgSend($token, $chatId, 'Usage: /withdrawstatus ORDER_NO'); break; }
            $stmt = $db->prepare('SELECT status,provider_reference,error_message FROM withdrawal_requests WHERE order_no=? LIMIT 1');
            $stmt->execute([$arg]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            tgSend($token, $chatId, $row ? "💸 <b>" . esc($arg) . '</b>\nStatus: ' . esc((string)$row['status']) . '\nRef: ' . esc((string)($row['provider_reference'] ?: '-')) : '❌ No withdrawal request');
            break;

        case '/history':
            $row = $db->query("SELECT COUNT(*) c, COALESCE(SUM(total_bdt),0) b FROM orders WHERE created_at >= NOW()-INTERVAL 90 DAY")->fetch(PDO::FETCH_ASSOC);
            tgSend($token, $chatId, '📊 <b>Last 90 Days</b>\nOrders: ' . (int)$row['c'] . '\nBDT: ' . number_format((float)$row['b'], 2));
            break;

        default:
            tgSend($token, $chatId, 'Use /help');
    }
} catch (Throwable $e) {
    error_log('Dollar Topup Telegram webhook error: ' . $e->getMessage());
}
echo 'OK';