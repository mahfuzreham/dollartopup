<?php
declare(strict_types=1);
$config=require __DIR__.'/../config/config.php';
try{
$db=require __DIR__.'/../config/database.php';$token=(string)$config['telegram_bot_token'];$admins=array_map('strval',$config['admin_telegram_ids']??[]);
$u=json_decode(file_get_contents('php://input')?:'',true,512,JSON_THROW_ON_ERROR);$m=$u['message']??[];$chat=(string)($m['chat']['id']??'');$uid=(string)($m['from']['id']??'');$text=trim((string)($m['text']??''));
function sendT($t,$c,$x){$d=http_build_query(['chat_id'=>$c,'text'=>$x,'parse_mode'=>'HTML']);@file_get_contents('https://api.telegram.org/bot'.$t.'/sendMessage',false,stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$d,'timeout'=>12]]));}
function sv(PDO $d,$k,$v){$s=$d->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$s->execute([$k,$v]);}
function gv(PDO $d,$k,$x=''){$s=$d->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$s->execute([$k]);return (string)($s->fetchColumn()?:$x);}
function sess(PDO $d,$uid,$chat,$step,$data){$s=$d->prepare("INSERT INTO telegram_order_sessions(telegram_user_id,chat_id,step,data_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),step=VALUES(step),data_json=VALUES(data_json)");$s->execute([$uid,$chat,$step,json_encode($data)]);}
function getsess(PDO $d,$uid){$s=$d->prepare('SELECT step,data_json FROM telegram_order_sessions WHERE telegram_user_id=?');$s->execute([$uid]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?[$r['step'],json_decode($r['data_json'],true)?:[]]:null;}
function clearsess(PDO $d,$uid){$s=$d->prepare('DELETE FROM telegram_order_sessions WHERE telegram_user_id=?');$s->execute([$uid]);}
if($chat===''||$uid===''){echo'OK';exit;}
$isAdmin=in_array($uid,$admins,true);
if(!$isAdmin){
 if($text==='/start'||$text==='/buy'){sess($db,$uid,$chat,'usd',[]);sendT($token,$chat,"💳 <b>Dollar Topup Card</b>\n\nআপনি কত USD কিনতে চান? শুধু amount লিখুন।\nউদাহরণ: <b>10</b>");}
 elseif($text==='/cancel'){clearsess($db,$uid);sendT($token,$chat,'❌ Order cancelled.');}
 else{$z=getsess($db,$uid);if(!$z){sendT($token,$chat,'💳 Order করতে /buy লিখুন।');}else{[$step,$d]=$z;
  if($step==='usd'){if(!is_numeric($text)||(float)$text<=0||(float)$text>10000){sendT($token,$chat,'সঠিক USD amount দিন।');}else{$d['usd']=(float)$text;$rate=(float)gv($db,'dollar_price_bdt','130');$d['rate']=$rate;$d['total']=round($d['usd']*$rate,2);sess($db,$uid,$chat,'method',$d);sendT($token,$chat,"💵 USD: {$d['usd']}\n💰 Total: <b>{$d['total']} BDT</b>\n\nPayment method লিখুন: <b>bkash</b> অথবা <b>bank</b>");}}
  elseif($step==='method'){if(!in_array(strtolower($text),['bkash','bank'],true)){sendT($token,$chat,'bkash অথবা bank লিখুন।');}else{$d['method']=strtolower($text);$ins=gv($db,$d['method'].'_instructions','');sess($db,$uid,$chat,'phone',$d);sendT($token,$chat,($ins?"🏦 <b>Payment Instructions</b>\n$ins\n\n":'')."আপনার Phone Number দিন।");}}
  elseif($step==='phone'){if(!preg_match('/^[0-9+()\-\s]{7,30}$/',$text)){sendT($token,$chat,'সঠিক phone number দিন।');}else{$d['phone']=$text;sess($db,$uid,$chat,'trxid',$d);sendT($token,$chat,'Payment করার পর TrxID / Reference দিন।');}}
  elseif($step==='trxid'){if(strlen($text)<3||strlen($text)>100){sendT($token,$chat,'সঠিক TrxID দিন।');}else{$d['trxid']=$text;sess($db,$uid,$chat,'address',$d);sendT($token,$chat,'এখন আপনার <b>USDT BEP20 Address</b> দিন। (0x...)');}}
  elseif($step==='address'){if(!preg_match('/^0x[a-fA-F0-9]{40}$/',$text)){sendT($token,$chat,'সঠিক BEP20 address দিন। এটি 0x দিয়ে শুরু হবে।');}else{$d['address']=$text;$no='DTC-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$deadline=date('Y-m-d H:i:s',time()+1800);$q=$db->prepare("INSERT INTO orders(order_no,usd_amount,dollar_price_bdt,total_bdt,phone_number,bkash_trxid,payment_method,payment_reference,bep20_address,payment_deadline,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending')");$q->execute([$no,$d['usd'],$d['rate'],$d['total'],$d['phone'],$d['trxid'],$d['method'],$d['trxid'],$d['address'],$deadline]);clearsess($db,$uid);sendT($token,$chat,"🧾 <b>Invoice Created</b>\nOrder: <b>$no</b>\nUSD: {$d['usd']}\nTotal: <b>{$d['total']} BDT</b>\nStatus: Pending verification\n⏱️ Invoice valid for 30 minutes.");foreach($admins as $aid)sendT($token,$aid,"🔔 <b>NEW ORDER</b>\nOrder: <b>$no</b>\nUSD: {$d['usd']}\nBDT: {$d['total']}\nMethod: {$d['method']}\nTrxID: {$d['trxid']}\nBEP20: {$d['address']}\n\n/approve $no\n/reject $no");}}
 }}echo'OK';exit;}
$parts=preg_split('/\s+/',$text)?:[];$cmd=strtolower(explode('@',$parts[0]??'')[0]);$arg=trim(implode(' ',array_slice($parts,1)));
switch($cmd){
case '/start':case '/help':sendT($token,$chat,"💳 <b>Dollar Topup Admin</b>\n/setprice 130\n/setbkash INFO\n/setbank INFO\n/orders\n/approve ORDER\n/reject ORDER\n/withdraw ORDER\n/queue\n/history");break;
case '/setprice':$v=(float)($parts[1]??0);if($v>0){sv($db,'dollar_price_bdt',(string)$v);sendT($token,$chat,'✅ Price updated');}else sendT($token,$chat,'Usage: /setprice 130');break;
case '/setbkash':case '/setbank':if($arg==='')sendT($token,$chat,"Usage: $cmd payment details");else{sv($db,$cmd==='/setbkash'?'bkash_instructions':'bank_instructions',$arg);sendT($token,$chat,'✅ Saved');}break;
case '/orders':$r=$db->query("SELECT order_no,total_bdt,status FROM orders WHERE created_at>=NOW()-INTERVAL 90 DAY ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);$o=$r?"📋 <b>Orders</b>\n":'No orders';foreach($r as $x)$o.="\n{$x['order_no']} | {$x['total_bdt']} BDT | {$x['status']}";sendT($token,$chat,$o);break;
case '/approve':case '/reject':if($arg===''){sendT($token,$chat,"Usage: $cmd ORDER");break;}$st=$cmd==='/approve'?'approved':'rejected';$s=$db->prepare("UPDATE orders SET status=? WHERE order_no=? AND status='pending'");$s->execute([$st,$arg]);sendT($token,$chat,$s->rowCount()?"✅ $arg → $st":'❌ Not found/already processed');break;
default:sendT($token,$chat,'Use /help');
} }catch(Throwable $e){error_log('Telegram error: '.$e->getMessage());}echo'OK';