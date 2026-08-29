<?php
declare(strict_types=1);

// Telegram webhook handler.
// Set TELEGRAM_BOT_TOKEN and ADMIN_TELEGRAM_IDS in environment variables.
// Webhook example: https://pay.resellnom.com/dollar/bot/telegram.php

$config = require __DIR__ . '/../config/config.php';
$db = require __DIR__ . '/../config/database.php';

$token = (string)($config['telegram_bot_token'] ?? '');
$admins = array_map('strval', $config['admin_telegram_ids'] ?? []);
if ($token === '') { http_response_code(500); exit('Telegram token not configured'); }

$update = json_decode(file_get_contents('php://input'), true);
if (!is_array($update) || !isset($update['message'])) { http_response_code(200); exit('OK'); }

$message = $update['message'];
$chatId = (string)($message['chat']['id'] ?? '');
$userId = (string)($message['from']['id'] ?? '');
$text = trim((string)($message['text'] ?? ''));

function tg(string $token, string $chatId, string $text): void {
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = http_build_query(['chat_id'=>$chatId, 'text'=>$text, 'parse_mode'=>'HTML']);
    $ctx = stream_context_create(['http'=>[
        'method'=>'POST',
        'header'=>"Content-Type: application/x-www-form-urlencoded\r\n",
        'content'=>$payload,
        'timeout'=>10
    ]]);
    @file_get_contents($url, false, $ctx);
}

if (!in_array($userId, $admins, true)) {
    tg($token, $chatId, '⛔ Unauthorized');
    http_response_code(200); exit('OK');
}

$parts = preg_split('/\s+/', $text);
$command = strtolower(explode('@', $parts[0] ?? '')[0]);

switch ($command) {
    case '/start':
    case '/help':
        tg($token, $chatId,
            "💳 <b>Dollar Topup Card Admin</b>\n\n" .
            "/price - Current dollar price\n" .
            "/setprice 125 - Set BDT price per USD\n" .
            "/orders - Recent 20 orders\n" .
            "/history - Last 90 days summary\n" .
            "/approve ORDER_NO - Approve order\n" .
            "/reject ORDER_NO - Reject order"
        );
        break;

    case '/price':
        $stmt=$db->prepare("SELECT setting_value FROM settings WHERE setting_key='dollar_price_bdt' LIMIT 1");
        $stmt->execute();
        $price=(float)($stmt->fetchColumn() ?: 0);
        tg($token,$chatId,"💵 Current Dollar Price: <b>".number_format($price,2)." BDT</b>");
        break;

    case '/setprice':
        $price=(float)($parts[1] ?? 0);
        if ($price <= 0) { tg($token,$chatId,"Usage: /setprice 125"); break; }
        $stmt=$db->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('dollar_price_bdt',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $stmt->execute([(string)$price]);
        tg($token,$chatId,"✅ Dollar price updated: <b>".number_format($price,2)." BDT/USD</b>");
        break;

    case '/orders':
        $rows=$db->query("SELECT order_no,usd_amount,total_bdt,status,created_at FROM orders WHERE created_at >= NOW() - INTERVAL 90 DAY ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { tg($token,$chatId,"📭 No orders found."); break; }
        $out="📋 <b>Recent Orders</b>\n\n";
        foreach ($rows as $r) $out .= "<b>{$r['order_no']}</b>\n$".$r['usd_amount']." | ".$r['total_bdt']." BDT | ".$r['status']."\n".$r['created_at']."\n\n";
        tg($token,$chatId,$out);
        break;

    case '/history':
        $stmt=$db->query("SELECT COUNT(*) total_orders, COALESCE(SUM(usd_amount),0) total_usd, COALESCE(SUM(total_bdt),0) total_bdt FROM orders WHERE created_at >= NOW() - INTERVAL 90 DAY");
        $r=$stmt->fetch(PDO::FETCH_ASSOC);
        tg($token,$chatId,"📊 <b>Last 90 Days History</b>\n\nOrders: {$r['total_orders']}\nUSD: $".number_format((float)$r['total_usd'],2)."\nBDT: ".number_format((float)$r['total_bdt'],2));
        break;

    case '/approve':
    case '/reject':
        $orderNo=trim($parts[1] ?? '');
        if ($orderNo === '') { tg($token,$chatId,"Usage: $command ORDER_NO"); break; }
        $status=$command === '/approve' ? 'approved' : 'rejected';
        $stmt=$db->prepare("UPDATE orders SET status=? WHERE order_no=? AND created_at >= NOW() - INTERVAL 90 DAY");
        $stmt->execute([$status,$orderNo]);
        tg($token,$chatId,$stmt->rowCount() ? "✅ $orderNo → <b>$status</b>" : "❌ Order not found in the last 90 days.");
        break;

    default:
        tg($token,$chatId,"Unknown command. Use /help");
}
http_response_code(200);
echo 'OK';