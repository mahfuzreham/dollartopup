<?php
declare(strict_types=1);
$config=require __DIR__.'/../config/config.php';
try{
$db=require __DIR__.'/../config/database.php';$token=(string)$config['telegram_bot_token'];$admins=array_map('strval',$config['admin_telegram_ids']??[]);
$u=json_decode(file_get_contents('php://input')?:'',true,512,JSON_THROW_ON_ERROR);$m=$u['message']??[];$chat=(string)($m['chat']['id']??'');$uid=(string)($m['from']['id']??'');$text=trim((string)($m['text']??''));$from=$m['from']??[];
function menuT($t,$c,$x){$url='https://api.telegram.org/bot'.$t.'/sendMessage';$d=['chat_id'=>$c,'text'=>$x,'parse_mode'=>'HTML','reply_markup'=>json_encode(['keyboard'=>[[['text'=>'💳 Buy Dollar'],['text'=>'👤 My Profile']],[['text'=>'📊 Last 15 Days'],['text'=>'🆘 Support Team']]],'resize_keyboard'=>true])];if(function_exists('curl_init')){$h=curl_init($url);curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$d,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$r=curl_exec($h);curl_close($h);return $r;}return null;}
function sendT($t,$c,$x){$url='https://api.telegram.org/bot'.$t.'/sendMessage';$d=['chat_id'=>$c,'text'=>$x,'parse_mode'=>'HTML'];if(function_exists('curl_init')){$h=curl_init($url);curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$d,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$r=curl_exec($h);curl_close($h);return $r;}return @file_get_contents($url,false,stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($d),'timeout'=>15]]));}
function sv(PDO $d,$k,$v){$s=$d->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$s->execute([$k,$v]);}
function gv(PDO $d,$k,$x=''){$s=$d->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$s->execute([$k]);return (string)($s->fetchColumn()?:$x);}
function sess(PDO $d,$uid,$chat,$step,$data){$s=$d->prepare("INSERT INTO telegram_order_sessions(telegram_user_id,chat_id,step,data_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),step=VALUES(step),data_json=VALUES(data_json)");$s->execute([$uid,$chat,$step,json_encode($data)]);}
function getsess(PDO $d,$uid){$s=$d->prepare('SELECT step,data_json FROM telegram_order_sessions WHERE telegram_user_id=?');$s->execute([$uid]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?[$r['step'],json_decode($r['data_json'],true)?:[]]:null;}
function clearsess(PDO $d,$uid){$s=$d->prepare('DELETE FROM telegram_order_sessions WHERE telegram_user_id=?');$s->execute([$uid]);}
if($chat===''||$uid===''){echo'OK';exit;}
$up=$db->prepare("INSERT INTO telegram_users(telegram_user_id,chat_id,username,first_name,last_name,last_seen_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),username=VALUES(username),first_name=VALUES(first_name),last_name=VALUES(last_name),last_seen_at=NOW()");
$up->execute([$uid,$chat,(string)($from['username']??''),(string)($from['first_name']??''),(string)($from['last_name']??'')]);
$isAdmin=in_array($uid,$admins,true);
if(!$isAdmin){
 if($text==='/start'){clearsess($db,$uid);menuT($token,$chat,"💳 <b>Dollar Topup Card</b>\n\nনিচের menu থেকে service নির্বাচন করুন।");}
 elseif($text==='/buy'||$text==='💳 Buy Dollar'){sess($db,$uid,$chat,'usd',[]);sendT($token,$chat,"💳 <b>Dollar Topup Card</b>\n\nআপনি কত USD কিনতে চান? শুধু amount লিখুন।\nউদাহরণ: <b>10</b>");}
 elseif($text==='/cancel'){clearsess($db,$uid);sendT($token,$chat,'❌ Order cancelled.');}
 elseif($text==='/profile'||$text==='👤 My Profile'){$s=$db->prepare("SELECT username,first_name,created_at FROM telegram_users WHERE telegram_user_id=?");$s->execute([$uid]);$p=$s->fetch(PDO::FETCH_ASSOC)?:[];$s=$db->prepare("SELECT COUNT(*) orders,COALESCE(SUM(o.usd_amount),0) usd,COALESCE(SUM(o.total_bdt),0) bdt FROM orders o JOIN telegram_order_contacts c ON c.order_no=o.order_no WHERE c.telegram_user_id=?");$s->execute([$uid]);$v=$s->fetch(PDO::FETCH_ASSOC);sendT($token,$chat,"👤 <b>My Profile</b>\nName: ".htmlspecialchars((string)($p['first_name']??'User'))."\nUsername: @".htmlspecialchars((string)($p['username']??'N/A'))."\n\n📦 Total Orders: <b>{$v['orders']}</b>\n💵 Total Trx Volume: <b>$".number_format((float)$v['usd'],2)." USD</b>\n💰 Total BDT: <b>".number_format((float)$v['bdt'],2)." BDT</b>");}
 elseif($text==='/history'||$text==='📊 Last 15 Days'){$s=$db->prepare("SELECT o.order_no,o.usd_amount,o.total_bdt,o.status,o.created_at FROM orders o JOIN telegram_order_contacts c ON c.order_no=o.order_no WHERE c.telegram_user_id=? AND o.created_at>=NOW()-INTERVAL 15 DAY ORDER BY o.id DESC LIMIT 30");$s->execute([$uid]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$out="📊 <b>Last 15 Days History</b>\n";if(!$rows)$out.="\nNo transactions found.";foreach($rows as $r)$out.="\n\n{$r['order_no']}\n💵 $".number_format((float)$r['usd_amount'],2)." | 💰 ".number_format((float)$r['total_bdt'],2)." BDT\n📌 {$r['status']} | {$r['created_at']}";sendT($token,$chat,$out);}
 elseif($text==='/support'||$text==='🆘 Support Team'){$info=gv($db,'support_team','Support team is not configured yet.');sendT($token,$chat,"🆘 <b>Support Team</b>\n\n".htmlspecialchars($info));}
 else{$z=getsess($db,$uid);if(!$z){sendT($token,$chat,'💳 Order করতে /buy লিখুন।');}else{[$step,$d]=$z;
  if($step==='usd'){if(!is_numeric($text)||(float)$text<=0||(float)$text>10000){sendT($token,$chat,'সঠিক USD amount দিন।');}else{$d['usd']=(float)$text;$rate=(float)gv($db,'dollar_price_bdt','130');$d['rate']=$rate;$d['total']=round($d['usd']*$rate,2);sess($db,$uid,$chat,'method',$d);sendT($token,$chat,"💵 USD: {$d['usd']}\n💰 Total: <b>{$d['total']} BDT</b>\n\nPayment method লিখুন: <b>bkash</b> অথবা <b>bank</b>");}}
  elseif($step==='method'){if(!in_array(strtolower($text),['bkash','bank'],true)){sendT($token,$chat,'bkash অথবা bank লিখুন।');}else{$d['method']=strtolower($text);$ins=gv($db,$d['method'].'_instructions','');sess($db,$uid,$chat,'phone',$d);sendT($token,$chat,($ins?"🏦 <b>Payment Instructions</b>\n$ins\n\n":'')."আপনার Phone Number দিন।");}}
  elseif($step==='phone'){if(!preg_match('/^[0-9+()\-\s]{7,30}$/',$text)){sendT($token,$chat,'সঠিক phone number দিন।');}else{$d['phone']=$text;sess($db,$uid,$chat,'trxid',$d);sendT($token,$chat,'Payment করার পর TrxID / Reference দিন।');}}
  elseif($step==='trxid'){if(strlen($text)<3||strlen($text)>100){sendT($token,$chat,'সঠিক TrxID দিন।');}else{$d['trxid']=$text;sess($db,$uid,$chat,'address',$d);sendT($token,$chat,'এখন আপনার <b>USDT BEP20 Address</b> দিন। (0x...)');}}
  elseif($step==='address'){if(!preg_match('/^0x[a-fA-F0-9]{40}$/',$text)){sendT($token,$chat,'সঠিক BEP20 address দিন। এটি 0x দিয়ে শুরু হবে।');}else{$d['address']=$text;$no='DTC-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$deadline=date('Y-m-d H:i:s',time()+2700);$q=$db->prepare("INSERT INTO orders(order_no,usd_amount,dollar_price_bdt,total_bdt,phone_number,bkash_trxid,payment_method,payment_reference,bep20_address,payment_deadline,status) VALUES(?,?,?,?,?,?,?,?,?,?,'pending')");$q->execute([$no,$d['usd'],$d['rate'],$d['total'],$d['phone'],$d['trxid'],$d['method'],$d['trxid'],$d['address'],$deadline]);$link=$db->prepare('INSERT INTO telegram_order_contacts(order_no,telegram_user_id,chat_id) VALUES(?,?,?)');$link->execute([$no,$uid,$chat]);clearsess($db,$uid);sendT($token,$chat,"✅ <b>Order Submitted</b>\n\nOrder ID: <b>$no</b>\n💵 USD: {$d['usd']}\n💰 Total: <b>{$d['total']} BDT</b>\n📌 Status: <b>Pending verification</b>\n⏱️ সময়সীমা: <b>45 মিনিট</b>\n\nPayment verification শেষে আপনাকে Telegram-এ update দেওয়া হবে।");foreach($admins as $aid)sendT($token,$aid,"🔔 <b>NEW ORDER</b>\nOrder: <b>$no</b>\nUSD: {$d['usd']}\nBDT: {$d['total']}\nMethod: {$d['method']}\nTrxID: {$d['trxid']}\nBEP20: {$d['address']}\n\n/approve $no\n/reject $no");}}
 }}echo'OK';exit;}
$parts=preg_split('/\s+/',$text)?:[];$cmd=strtolower(explode('@',$parts[0]??'')[0]);$arg=trim(implode(' ',array_slice($parts,1)));
switch($cmd){
case '/start':case '/help':sendT($token,$chat,"💳 <b>Dollar Topup Admin</b>\n/setprice 130\n/setbkash INFO\n/setbank INFO\n/orders\n/approve ORDER\n/reject ORDER\n/manualsent ORDER TX_HASH\n/queue\n/history");break;
case '/setprice':$v=(float)($parts[1]??0);if($v>0){sv($db,'dollar_price_bdt',(string)$v);sendT($token,$chat,'✅ Price updated');}else sendT($token,$chat,'Usage: /setprice 130');break;
case '/setbkash':case '/setbank':if($arg==='')sendT($token,$chat,"Usage: $cmd payment details");else{sv($db,$cmd==='/setbkash'?'bkash_instructions':'bank_instructions',$arg);sendT($token,$chat,'✅ Saved');}break;
case '/orders':$r=$db->query("SELECT order_no,total_bdt,status FROM orders WHERE created_at>=NOW()-INTERVAL 90 DAY ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);$o=$r?"📋 <b>Orders</b>\n":'No orders';foreach($r as $x)$o.="\n{$x['order_no']} | {$x['total_bdt']} BDT | {$x['status']}";sendT($token,$chat,$o);break;
case '/approve':case '/reject':
if($arg===''){sendT($token,$chat,"Usage: $cmd ORDER");break;}
if($cmd==='/reject'){$s=$db->prepare("UPDATE orders SET status='rejected' WHERE order_no=? AND status='pending'");$s->execute([$arg]);sendT($token,$chat,$s->rowCount()?"❌ $arg → rejected":'❌ Not found/already processed');break;}
$db->beginTransaction();try{
$s=$db->prepare("SELECT order_no,usd_amount,bep20_address FROM orders WHERE order_no=? AND status='pending' FOR UPDATE");$s->execute([$arg]);$o=$s->fetch(PDO::FETCH_ASSOC);if(!$o)throw new RuntimeException('Order not found');
$q=$db->prepare("INSERT INTO withdrawal_requests(order_no,destination_address,amount,status) VALUES(?,?,?,'queued')");$q->execute([$o['order_no'],$o['bep20_address'],$o['usd_amount']]);
$db->prepare("UPDATE orders SET status='approved',withdrawal_status='queued',withdrawal_requested_at=NOW() WHERE order_no=?")->execute([$arg]);$db->commit();$n=$db->prepare('SELECT chat_id FROM telegram_order_contacts WHERE order_no=? LIMIT 1');$n->execute([$arg]);$uc=$n->fetchColumn();if($uc)sendT($token,(string)$uc,"✅ <b>Your order $arg is approved.</b>\n⏱️ Processing started. You will receive a confirmation after delivery.");sendT($token,$chat,"✅ $arg approved. 💸 Withdrawal status: QUEUED");
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Approve queue: '.$e->getMessage());sendT($token,$chat,'❌ Could not approve/queue order.');}break;

case '/manualsent':
$a=preg_split('/\s+/',$arg);$ord=$a[0]??'';$tx=$a[1]??'';
if($ord===''||!preg_match('/^0x[a-fA-F0-9]{64}$/',$tx)){sendT($token,$chat,'Usage: /manualsent ORDER TX_HASH');break;}
$s=$db->prepare("UPDATE withdrawal_requests SET status='verifying',tx_hash=?,updated_at=NOW() WHERE order_no=? AND status IN ('queued','processing')");$s->execute([$tx,$ord]);
$db->prepare("UPDATE orders SET withdrawal_status='verifying' WHERE order_no=?")->execute([$ord]);
sendT($token,$chat,$s->rowCount()?"🔎 $ord TX saved. BSC confirmation check started.":'❌ No active withdrawal found');break;

case '/queue':
$r=$db->query("SELECT w.order_no,w.amount,w.status FROM withdrawal_requests w ORDER BY w.id ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);$o=$r?"💸 <b>Withdrawal Queue</b>\n":'Queue empty';foreach($r as $x)$o.="\n{$x['order_no']} | {$x['amount']} USDT | {$x['status']}";sendT($token,$chat,$o);break;
case '/received':
if($arg===''){sendT($token,$chat,'Usage: /received ORDER');break;}
sendT($token,$chat,'Admin command only: user confirmation is handled automatically after delivery status is recorded.');
break;
default:sendT($token,$chat,'Use /help');
} }catch(Throwable $e){
error_log('Telegram error: '.$e->getMessage().' | '.substr($e->getTraceAsString(),0,1000));
if(isset($token,$chat) && $chat!=='' && function_exists('sendT')){
  sendT($token,$chat,'⚠️ Order process করা যায়নি। দয়া করে আবার /start দিয়ে চেষ্টা করুন।');
}
}echo'OK';