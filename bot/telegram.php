<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
$db = require __DIR__ . '/../config/database.php';

$token = (string)($config['telegram_bot_token'] ?? '');
$admins = array_map('strval', $config['admin_telegram_ids'] ?? []);
if ($token === '') { http_response_code(500); exit('Bot not configured'); }

$update = json_decode(file_get_contents('php://input') ?: '', true);
$message = $update['message'] ?? null;
if (!is_array($message)) { echo 'OK'; exit; }

$chatId = (string)($message['chat']['id'] ?? '');
$userId = (string)($message['from']['id'] ?? '');
$text = trim((string)($message['text'] ?? ''));

function tgSend(string $token, string $chatId, string $text): void {
    $payload = http_build_query(['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML']);
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$payload,'timeout'=>15]]);
    @file_get_contents('https://api.telegram.org/bot'.$token.'/sendMessage', false, $ctx);
}
function setting(PDO $db, string $key, string $default=''): string {
    $s=$db->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');$s->execute([$key]);
    return (string)($s->fetchColumn() ?: $default);
}
function saveSetting(PDO $db, string $key, string $value): void {
    $s=$db->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $s->execute([$key,$value]);
}

if (!in_array($userId, $admins, true)) { tgSend($token,$chatId,'⛔ Unauthorized'); echo 'OK'; exit; }

$parts=preg_split('/\s+/', $text);
$cmd=strtolower(explode('@',$parts[0]??'')[0]);
$arg=trim(implode(' ',array_slice($parts,1)));

switch($cmd){
case '/start': case '/help':
 tgSend($token,$chatId,"💳 <b>Dollar Topup Admin</b>\n\n💵 /price\n✏️ /setprice 125\n📋 /orders\n📊 /history\n🔎 /order ORDER_NO\n✅ /approve ORDER_NO\n❌ /reject ORDER_NO\n\n⚙️ <b>Bot Setup</b>\n/webhookstatus\n/settings"); break;
case '/price':
 $p=(float)setting($db,'dollar_price_bdt','120'); tgSend($token,$chatId,'💵 <b>Dollar Price:</b> '.number_format($p,2).' BDT/USD'); break;
case '/setprice':
 $p=(float)($parts[1]??0); if($p<=0){tgSend($token,$chatId,'Usage: /setprice 125');break;}
 saveSetting($db,'dollar_price_bdt',(string)$p); tgSend($token,$chatId,'✅ Price updated: <b>'.number_format($p,2).' BDT/USD</b>'); break;
case '/orders':
 $rows=$db->query("SELECT order_no,usd_amount,total_bdt,status,created_at FROM orders WHERE created_at>=NOW()-INTERVAL 90 DAY ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
 $out=$rows?"📋 <b>Latest Orders</b>\n\n":'📭 No orders.';
 foreach($rows as $r)$out.="<b>{$r['order_no']}</b>\n$".$r['usd_amount']." | {$r['total_bdt']} BDT | {$r['status']}\n{$r['created_at']}\n\n";
 tgSend($token,$chatId,$out);break;
case '/order':
 if($arg===''){tgSend($token,$chatId,'Usage: /order ORDER_NO');break;}
 $s=$db->prepare("SELECT * FROM orders WHERE order_no=? AND created_at>=NOW()-INTERVAL 90 DAY");$s->execute([$arg]);$r=$s->fetch(PDO::FETCH_ASSOC);
 tgSend($token,$chatId,$r?"<b>{$r['order_no']}</b>\nUSD: $".$r['usd_amount']."\nBDT: {$r['total_bdt']}\nPhone: {$r['phone_number']}\nTrxID: {$r['bkash_trxid']}\nStatus: <b>{$r['status']}</b>":'❌ Order not found.');break;
case '/history':
 $r=$db->query("SELECT COUNT(*) c,COALESCE(SUM(usd_amount),0) u,COALESCE(SUM(total_bdt),0) b FROM orders WHERE created_at>=NOW()-INTERVAL 90 DAY")->fetch(PDO::FETCH_ASSOC);
 tgSend($token,$chatId,"📊 <b>Last 90 Days</b>\nOrders: {$r['c']}\nUSD: $".number_format((float)$r['u'],2)."\nBDT: ".number_format((float)$r['b'],2));break;
case '/approve': case '/reject':
 if($arg===''){tgSend($token,$chatId,"Usage: $cmd ORDER_NO");break;}
 $status=$cmd==='/approve'?'approved':'rejected';$s=$db->prepare("UPDATE orders SET status=? WHERE order_no=? AND created_at>=NOW()-INTERVAL 90 DAY");$s->execute([$status,$arg]);
 tgSend($token,$chatId,$s->rowCount()?"✅ $arg → <b>$status</b>":'❌ Order not found.');break;
case '/webhookstatus':
 tgSend($token,$chatId,'🔗 <b>Webhook Endpoint</b>\nhttps://pay.resellnom.com/dollar/bot/telegram.php\n\nIf Telegram webhook is configured to this URL, the bot is ready.');break;
case '/settings':
 $p=setting($db,'dollar_price_bdt','120');
 tgSend($token,$chatId,"⚙️ <b>Current Settings</b>\nDollar Price: {$p} BDT/USD\nHistory Retention: 90 days\nAdmin access: Telegram ID allowlist");break;
default: tgSend($token,$chatId,'Unknown command. Use /help');
}
echo 'OK';