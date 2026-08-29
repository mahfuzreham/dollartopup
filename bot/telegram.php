<?php
declare(strict_types=1);
$config=require __DIR__.'/../config/config.php';
$db=require __DIR__.'/../config/database.php';
$token=(string)($config['telegram_bot_token']??'');
$admins=array_map('strval',$config['admin_telegram_ids']??[]);
$u=json_decode(file_get_contents('php://input')?:'',true);$m=$u['message']??null;
if(!is_array($m)||$token===''){echo 'OK';exit;}
$chat=(string)($m['chat']['id']??'');$uid=(string)($m['from']['id']??'');$text=trim((string)($m['text']??''));
function tg(string $t,string $c,string $x):void{$d=http_build_query(['chat_id'=>$c,'text'=>$x,'parse_mode'=>'HTML']);@file_get_contents('https://api.telegram.org/bot'.$t.'/sendMessage',false,stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$d,'timeout'=>15]]));}
function setv(PDO $db,string $k,string $v):void{$s=$db->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$s->execute([$k,$v]);}
function getv(PDO $db,string $k,string $d=''):string{$s=$db->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$s->execute([$k]);return (string)($s->fetchColumn()?:$d);}
if(!in_array($uid,$admins,true)){tg($token,$chat,'⛔ Unauthorized');echo'OK';exit;}
$p=preg_split('/\s+/',$text);$cmd=strtolower(explode('@',$p[0]??'')[0]);$arg=trim(implode(' ',array_slice($p,1)));
switch($cmd){
case '/start':case '/help':tg($token,$chat,"💳 <b>Dollar Topup Admin</b>\n\n💵 /price\n/setprice 125\n🏦 /setbkash PAYMENT_TEXT\n/setbank PAYMENT_TEXT\n/paymentmethods\n📋 /orders\n/order ORDER_NO\n📊 /history\n✅ /approve ORDER_NO\n❌ /reject ORDER_NO\n💸 /withdraw ORDER_NO\n/queue\n/withdrawstatus ORDER_NO");break;
case '/price':tg($token,$chat,'💵 Price: <b>'.getv($db,'dollar_price_bdt','120').' BDT/USD</b>');break;
case '/setprice':$v=(float)($p[1]??0);if($v<=0){tg($token,$chat,'Usage: /setprice 125');break;}setv($db,'dollar_price_bdt',(string)$v);tg($token,$chat,'✅ Price updated');break;
case '/setbkash':if($arg===''){tg($token,$chat,'Usage: /setbkash PAYMENT_TEXT');break;}setv($db,'bkash_instructions',$arg);tg($token,$chat,'✅ bKash instructions saved');break;
case '/setbank':if($arg===''){tg($token,$chat,'Usage: /setbank PAYMENT_TEXT');break;}setv($db,'bank_instructions',$arg);tg($token,$chat,'✅ Bank instructions saved');break;
case '/paymentmethods':tg($token,$chat,"🏦 <b>Payment Methods</b>\n\nbKash: ".(getv($db,'bkash_instructions')?'Configured':'Not set')."\nBank: ".(getv($db,'bank_instructions')?'Configured':'Not set'));break;
case '/orders':$r=$db->query("SELECT order_no,total_bdt,status,withdrawal_status FROM orders WHERE created_at>=NOW()-INTERVAL 90 DAY ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);$o=$r?"📋 <b>Orders</b>\n\n":'📭 No orders.';foreach($r as $x)$o.="<b>{$x['order_no']}</b> | {$x['total_bdt']} BDT | {$x['status']} | WD: {$x['withdrawal_status']}\n";tg($token,$chat,$o);break;
case '/approve':case '/reject':if($arg===''){tg($token,$chat,"Usage: $cmd ORDER_NO");break;}$st=$cmd==='/approve'?'approved':'rejected';$s=$db->prepare('UPDATE orders SET status=? WHERE order_no=? AND created_at>=NOW()-INTERVAL 90 DAY');$s->execute([$st,$arg]);tg($token,$chat,$s->rowCount()?"✅ $arg → $st":'❌ Order not found');break;
case '/withdraw':if($arg===''){tg($token,$chat,'Usage: /withdraw ORDER_NO');break;}$s=$db->prepare("SELECT * FROM orders WHERE order_no=? AND status='approved' AND bep20_address IS NOT NULL AND withdrawal_status='not_requested'");$s->execute([$arg]);$o=$s->fetch(PDO::FETCH_ASSOC);if(!$o){tg($token,$chat,'❌ Approved eligible order not found or already requested.');break;}$q=$db->prepare("INSERT INTO withdrawal_requests(order_no,destination_address,amount,status) VALUES(?,?,?,'queued')");$q->execute([$o['order_no'],$o['bep20_address'],$o['usd_amount']]);$db->prepare("UPDATE orders SET withdrawal_status='queued',withdrawal_requested_at=NOW() WHERE order_no=?")->execute([$arg]);tg($token,$chat,"💸 Withdrawal request queued: <b>$arg</b>\nStatus: queued");break;
case '/queue':$r=$db->query("SELECT order_no,amount,status FROM withdrawal_requests WHERE status IN ('queued','processing') ORDER BY id ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);$o=$r?"💸 <b>Withdrawal Queue</b>\n\n":'Queue empty.';foreach($r as $x)$o.="{$x['order_no']} | {$x['amount']} USDT | {$x['status']}\n";tg($token,$chat,$o);break;
case '/withdrawstatus':if($arg===''){tg($token,$chat,'Usage: /withdrawstatus ORDER_NO');break;}$s=$db->prepare('SELECT status,provider_reference,error_message FROM withdrawal_requests WHERE order_no=?');$s->execute([$arg]);$r=$s->fetch(PDO::FETCH_ASSOC);tg($token,$chat,$r?"💸 <b>$arg</b>\nStatus: {$r['status']}\nRef: ".($r['provider_reference']?:'-'):'❌ No withdrawal request');break;
case '/history':$r=$db->query("SELECT COUNT(*) c,COALESCE(SUM(total_bdt),0)b FROM orders WHERE created_at>=NOW()-INTERVAL 90 DAY")->fetch(PDO::FETCH_ASSOC);tg($token,$chat,"📊 90 Days\nOrders: {$r['c']}\nBDT: ".number_format((float)$r['b'],2));break;
default:tg($token,$chat,'Use /help');
}echo'OK';