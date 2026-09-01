<?php
declare(strict_types=1);
$config=require __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/withdrawals.php';

function tr(string $l,string $bn,string $en):string{return $l==='en'?$en:$bn;}
function apiSend(string $token,string $chat,string $text,?array $keyboard=null):void{
  $d=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML'];
  if($keyboard!==null)$d['reply_markup']=json_encode(['keyboard'=>$keyboard,'resize_keyboard'=>true,'one_time_keyboard'=>false],JSON_UNESCAPED_UNICODE);
  $h=curl_init('https://api.telegram.org/bot'.$token.'/sendMessage');
  curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$d,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
  curl_exec($h);curl_close($h);
}
function apiInline(string $token,string $chat,string $text,array $buttons):void{
  $d=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','reply_markup'=>json_encode(['inline_keyboard'=>$buttons],JSON_UNESCAPED_UNICODE)];
  $h=curl_init('https://api.telegram.org/bot'.$token.'/sendMessage');curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$d,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);curl_exec($h);curl_close($h);
}
function apiAnswerCallback(string $token,string $id,string $text=''):void{
  $h=curl_init('https://api.telegram.org/bot'.$token.'/answerCallbackQuery');curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['callback_query_id'=>$id,'text'=>$text],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);curl_exec($h);curl_close($h);
}
function paymentMethods(PDO $db,string $l):array{
  $k=[[['text'=>'📱 bKash']]];
  if(gv($db,'bkash_auto_enabled','0')==='1')$k[0][]=['text'=>'🤖 bKash Auto'];
  $k[]=[['text'=>'📱 Nagad'],['text'=>'🏦 Bank']];
  return $k;
}
function paymentInstruction(PDO $db,string $method):string{
  $map=['bkash'=>'bkash_instructions','bkash_auto'=>'bkash_auto_instructions','nagad'=>'nagad_instructions','bank'=>'bank_instructions'];
  return gv($db,$map[$method]??'','');
}
function menu(string $token,string $chat,string $l,string $title):void{
  $k=$l==='en'
    ? [[['text'=>'💳 Buy Dollar'],['text'=>'👤 My Profile']],[['text'=>'📊 Last 15 Days'],['text'=>'🆘 Support Team']],[['text'=>'🌐 Language']]]
    : [[['text'=>'💳 ডলার কিনুন'],['text'=>'👤 আমার প্রোফাইল']],[['text'=>'📊 গত ১৫ দিনের হিস্ট্রি'],['text'=>'🆘 সাপোর্ট টিম']],[['text'=>'🌐 ভাষা']]];
  apiSend($token,$chat,$title,$k);
}
function sess(PDO $db,string $uid,string $chat,string $step,array $data):void{$s=$db->prepare("INSERT INTO telegram_order_sessions(telegram_user_id,chat_id,step,data_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),step=VALUES(step),data_json=VALUES(data_json)");$s->execute([$uid,$chat,$step,json_encode($data)]);}
function getsess(PDO $db,string $uid):?array{$s=$db->prepare('SELECT step,data_json FROM telegram_order_sessions WHERE telegram_user_id=?');$s->execute([$uid]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?[$r['step'],json_decode($r['data_json'],true)?:[]]:null;}
function clearsess(PDO $db,string $uid):void{$s=$db->prepare('DELETE FROM telegram_order_sessions WHERE telegram_user_id=?');$s->execute([$uid]);}
function gv(PDO $db,string $k,string $def=''):string{$s=$db->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$s->execute([$k]);$v=$s->fetchColumn();return $v===false?$def:(string)$v;}
function sv(PDO $db,string $k,string $v):void{$s=$db->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$s->execute([$k,$v]);}

try{
  $db=require __DIR__.'/../config/database.php';
  $token=(string)$config['telegram_bot_token'];$admins=array_map('strval',$config['admin_telegram_ids']??[]);
  $u=json_decode(file_get_contents('php://input')?:'',true,512,JSON_THROW_ON_ERROR);
  $cb=$u['callback_query']??null;
  if(is_array($cb)){
    $chat=(string)($cb['message']['chat']['id']??'');$uid=(string)($cb['from']['id']??'');$from=$cb['from']??[];$text='';$cbid=(string)($cb['id']??'');$data=(string)($cb['data']??'');
  }else{$m=$u['message']??[];$chat=(string)($m['chat']['id']??'');$uid=(string)($m['from']['id']??'');$text=trim((string)($m['text']??''));$from=$m['from']??[];}
  if($chat===''||$uid===''){echo 'OK';exit;}

  $up=$db->prepare("INSERT INTO telegram_users(telegram_user_id,chat_id,username,first_name,last_name,last_seen_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),username=VALUES(username),first_name=VALUES(first_name),last_name=VALUES(last_name),last_seen_at=NOW()");
  $up->execute([$uid,$chat,(string)($from['username']??''),(string)($from['first_name']??''),(string)($from['last_name']??'')]);
  $s=$db->prepare('SELECT language FROM telegram_users WHERE telegram_user_id=?');$s->execute([$uid]);$lang=$s->fetchColumn()==='en'?'en':'bn';
  $isAdmin=in_array($uid,$admins,true);

  if(is_array($cb)){
    if(!$isAdmin){apiAnswerCallback($token,$cbid,'Admin only');echo 'OK';exit;}
    $p=explode('|',$data,2);$action=$p[0]??'';$id=(int)($p[1]??0);
    $q=$db->prepare('SELECT id,order_no,status,amount FROM withdrawal_requests WHERE id=?');$q->execute([$id]);$w=$q->fetch(PDO::FETCH_ASSOC);
    if(!$w){apiAnswerCallback($token,$cbid,'Order not found');echo 'OK';exit;}
    if($action==='REL'){
      if($w['status']==='sent'){apiAnswerCallback($token,$cbid,'Already sent');echo 'OK';exit;}
      sess($db,$uid,$chat,'admin_sent',['withdrawal_id'=>$id,'order_no'=>$w['order_no']]);
      apiAnswerCallback($token,$cbid,'Enter TX hash now');
      apiSend($token,$chat,"💸 <b>Manual Release</b>\nOrder: <code>{$w['order_no']}</code>\nAmount: <b>{$w['amount']} USDT</b>\n\nনিজে USDT পাঠানোর পর এখন <b>TX Hash</b> পাঠান।\nCancel: /cancel");
    }elseif($action==='HOLD'){
      $db->prepare("UPDATE withdrawal_requests SET status='hold',verification_error='Manual admin hold' WHERE id=? AND status<>'sent'")->execute([$id]);
      $db->prepare("UPDATE orders SET withdrawal_status='hold' WHERE order_no=?")->execute([$w['order_no']]);
      apiAnswerCallback($token,$cbid,'Moved to HOLD');
      apiSend($token,$chat,"⏸️ <code>{$w['order_no']}</code> moved to HOLD.");
    }elseif($action==='RETRY'){
      $db->prepare("UPDATE withdrawal_requests SET status='queued',verification_error=NULL WHERE id=? AND status='hold'")->execute([$id]);
      $db->prepare("UPDATE orders SET withdrawal_status='queued' WHERE order_no=?")->execute([$w['order_no']]);
      apiAnswerCallback($token,$cbid,'Returned to queue');
      apiSend($token,$chat,"🔄 <code>{$w['order_no']}</code> returned to QUEUE.");
    }else apiAnswerCallback($token,$cbid,'Unknown action');
    echo 'OK';exit;
  }

  if(!$isAdmin){
    if($text==='/start'||$text==='🌐 Language'||$text==='🌐 ভাষা'){
      clearsess($db,$uid);
      apiSend($token,$chat,'🌐 <b>Select Language / ভাষা নির্বাচন করুন</b>',[[['text'=>'🇧🇩 বাংলা'],['text'=>'🇬🇧 English']]]);
    }elseif($text==='🇧🇩 বাংলা'||$text==='🇬🇧 English'){
      $lang=$text==='🇬🇧 English'?'en':'bn';
      $db->prepare('UPDATE telegram_users SET language=? WHERE telegram_user_id=?')->execute([$lang,$uid]);
      menu($token,$chat,$lang,tr($lang,'💳 <b>ডলার টপআপ</b>\n\nনিচের মেনু থেকে একটি সেবা নির্বাচন করুন।','💳 <b>Dollar Topup</b>\n\nChoose a service from the menu below.'));
    }elseif($text==='/buy'||$text==='💳 Buy Dollar'||$text==='💳 ডলার কিনুন'){
      sess($db,$uid,$chat,'usd',[]);
      apiSend($token,$chat,tr($lang,'💳 <b>ডলার কিনুন</b>\n\nআপনি কত USD কিনতে চান?\nউদাহরণ: <b>10</b>','💳 <b>Buy Dollar</b>\n\nHow much USD do you want to buy?\nExample: <b>10</b>'));
    }elseif($text==='/cancel'){
      clearsess($db,$uid);apiSend($token,$chat,tr($lang,'❌ অর্ডার বাতিল করা হয়েছে।','❌ Order cancelled.'));
    }elseif(in_array($text,['/profile','👤 My Profile','👤 আমার প্রোফাইল'],true)){
      $s=$db->prepare("SELECT username,first_name FROM telegram_users WHERE telegram_user_id=?");$s->execute([$uid]);$p=$s->fetch(PDO::FETCH_ASSOC)?:[];
      $s=$db->prepare("SELECT COUNT(*) orders,COALESCE(SUM(o.usd_amount),0) usd,COALESCE(SUM(o.total_bdt),0) bdt FROM orders o JOIN telegram_order_contacts c ON c.order_no=o.order_no WHERE c.telegram_user_id=?");$s->execute([$uid]);$v=$s->fetch(PDO::FETCH_ASSOC);
      apiSend($token,$chat,tr($lang,
        "👤 <b>আমার প্রোফাইল</b>\nনাম: ".htmlspecialchars((string)($p['first_name']??'User'))."\n\n📦 মোট অর্ডার: <b>{$v['orders']}</b>\n💵 মোট ট্রানজেকশন: <b>$".number_format((float)$v['usd'],2)." USD</b>\n💰 মোট BDT: <b>".number_format((float)$v['bdt'],2)." BDT</b>",
        "👤 <b>My Profile</b>\nName: ".htmlspecialchars((string)($p['first_name']??'User'))."\n\n📦 Total Orders: <b>{$v['orders']}</b>\n💵 Total Trx Volume: <b>$".number_format((float)$v['usd'],2)." USD</b>\n💰 Total BDT: <b>".number_format((float)$v['bdt'],2)." BDT</b>"));
    }elseif(in_array($text,['/history','📊 Last 15 Days','📊 গত ১৫ দিনের হিস্ট্রি'],true)){
      $s=$db->prepare("SELECT o.order_no,o.usd_amount,o.total_bdt,o.status,o.created_at FROM orders o JOIN telegram_order_contacts c ON c.order_no=o.order_no WHERE c.telegram_user_id=? AND o.created_at>=NOW()-INTERVAL 15 DAY ORDER BY o.id DESC LIMIT 30");$s->execute([$uid]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
      $out=tr($lang,'📊 <b>গত ১৫ দিনের হিস্ট্রি</b>','📊 <b>Last 15 Days History</b>');if(!$rows)$out.="\n\n".tr($lang,'কোনো ট্রানজেকশন পাওয়া যায়নি।','No transactions found.');
      foreach($rows as $r)$out.="\n\n<code>{$r['order_no']}</code>\n💵 $".number_format((float)$r['usd_amount'],2)." | 💰 ".number_format((float)$r['total_bdt'],2)." BDT\n📌 {$r['status']} | {$r['created_at']}";
      apiSend($token,$chat,$out);
    }elseif(in_array($text,['/support','🆘 Support Team','🆘 সাপোর্ট টিম'],true)){
      $info=gv($db,'support_team',tr($lang,'সাপোর্ট টিম এখনো সেট করা হয়নি।','Support team is not configured yet.'));
      apiSend($token,$chat,tr($lang,'🆘 <b>সাপোর্ট টিম</b>','🆘 <b>Support Team</b>')."\n\n".htmlspecialchars($info));
    }else{
      $z=getsess($db,$uid);
      if(!$z){apiSend($token,$chat,tr($lang,'💳 শুরু করতে <b>ডলার কিনুন</b> চাপুন।','💳 Press <b>Buy Dollar</b> to start.'));echo 'OK';exit;}
      [$step,$d]=$z;
      if($step==='usd'){
        if(!is_numeric($text)||(float)$text<=0||(float)$text>10000)apiSend($token,$chat,tr($lang,'⚠️ সঠিক USD amount দিন।','⚠️ Enter a valid USD amount.'));
        else{$d['usd']=(float)$text;$d['rate']=(float)gv($db,'dollar_price_bdt','130');$d['total']=round($d['usd']*$d['rate'],2);sess($db,$uid,$chat,'method',$d);apiSend($token,$chat,tr($lang,"💵 USD: {$d['usd']}\n💰 মোট: <b>{$d['total']} BDT</b>\n\nনিচের Button থেকে Payment Method নির্বাচন করুন:","💵 USD: {$d['usd']}\n💰 Total: <b>{$d['total']} BDT</b>\n\nChoose a payment method below:"),paymentMethods($db,$lang));}
      }elseif($step==='method'){
        $map=['📱 bKash'=>'bkash','🤖 bKash Auto'=>'bkash_auto','📱 Nagad'=>'nagad','🏦 Bank'=>'bank','bkash'=>'bkash','nagad'=>'nagad','bank'=>'bank'];
        $method=$map[$text]??'';
        if($method==='bkash_auto'&&gv($db,'bkash_auto_enabled','0')!=='1')$method='';
        if($method==='')apiSend($token,$chat,tr($lang,'⚠️ নিচের Button থেকে একটি Payment Method নির্বাচন করুন।','⚠️ Please choose a payment method from the buttons below.'),paymentMethods($db,$lang));
        else{
          $d['method']=$method;$ins=paymentInstruction($db,$method);
          sess($db,$uid,$chat,'phone',$d);
          $title=$method==='bkash_auto'?'🤖 <b>bKash Auto Payment</b>':'🏦 <b>Payment Instructions</b>';
          $copy=trim($ins)!==''?htmlspecialchars($ins):tr($lang,'Admin এখনো payment details সেট করেননি।','Payment details are not configured yet.');
          $hint=tr($lang,'📋 নিচের প্রতিটি তথ্য আলাদা <code>code box</code>-এ দেওয়া আছে। Tap/hold করে আলাদা আলাদা Copy করুন।','📋 Each value is shown separately in a <code>copy-friendly box</code>. Tap/hold to copy individual information.');
          apiSend($token,$chat,$title."\n\n".$copy."\n\n".$hint."\n\n".tr($lang,'আপনার Phone Number দিন।','Enter your phone number.'));
        }
      }elseif($step==='phone'){
        if(!preg_match('/^[0-9+()\-\s]{7,30}$/',$text))apiSend($token,$chat,tr($lang,'⚠️ সঠিক ফোন নম্বর দিন।','⚠️ Enter a valid phone number.'));
        else{$d['phone']=$text;sess($db,$uid,$chat,'trxid',$d);apiSend($token,$chat,tr($lang,'Payment করার পর TrxID / Reference দিন।','After payment, enter your TrxID / Reference.'));}
      }elseif($step==='trxid'){
        if(strlen($text)<3||strlen($text)>100)apiSend($token,$chat,tr($lang,'⚠️ সঠিক TrxID দিন।','⚠️ Enter a valid TrxID.'));
        else{$d['trxid']=$text;sess($db,$uid,$chat,'network',$d);apiSend($token,$chat,tr($lang,'🌐 <b>Network নির্বাচন করুন</b>','🌐 <b>Select Network</b>'),[[['text'=>'🟡 USDT (BEP20)']]]);}
      }elseif($step==='network'){
        if($text!=='🟡 USDT (BEP20)')apiSend($token,$chat,tr($lang,'⚠️ নিচের USDT (BEP20) নির্বাচন করুন।','⚠️ Please select USDT (BEP20) below.'),[[['text'=>'🟡 USDT (BEP20)']]]);
        else{$d['network']='USDT (BEP20)';sess($db,$uid,$chat,'address',$d);apiSend($token,$chat,tr($lang,'🟡 <b>Network: USDT (BEP20)</b>\n\nএখন আপনার BEP20 Wallet Address দিন।\n<code>0x...</code>','🟡 <b>Network: USDT (BEP20)</b>\n\nNow enter your BEP20 wallet address.\n<code>0x...</code>'));}
      }elseif($step==='address'){
        if(!preg_match('/^0x[a-fA-F0-9]{40}$/',$text))apiSend($token,$chat,tr($lang,'⚠️ সঠিক BEP20 address দিন। এটি 0x দিয়ে শুরু হবে।','⚠️ Enter a valid BEP20 address starting with 0x.'));
        else{
          $d['address']=$text;$no='DTC-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$deadline=date('Y-m-d H:i:s',time()+2700);
          $q=$db->prepare("INSERT INTO orders(order_no,usd_amount,dollar_price_bdt,total_bdt,phone_number,bkash_trxid,payment_method,payment_reference,bep20_address,payment_deadline,status) VALUES(?,?,?,?,?,?,?,?,?,?,'pending')");
          $q->execute([$no,$d['usd'],$d['rate'],$d['total'],$d['phone'],$d['trxid'],$d['method'],$d['trxid'],$d['address'],$deadline]);
          $db->prepare('INSERT INTO telegram_order_contacts(order_no,telegram_user_id,chat_id) VALUES(?,?,?)')->execute([$no,$uid,$chat]);clearsess($db,$uid);
          apiSend($token,$chat,tr($lang,"✅ <b>অর্ডার জমা হয়েছে</b>\n\n🆔 Order ID: <code>$no</code>\n🌐 Network: <b>USDT (BEP20)</b>\n💵 USD: {$d['usd']}\n💰 মোট: <b>{$d['total']} BDT</b>\n📌 Status: <b>Verification Pending</b>\n⏱️ সময়সীমা: <b>45 মিনিট</b>\n\nVerification শেষে Telegram-এ update পাবেন।","✅ <b>Order Submitted</b>\n\n🆔 Order ID: <code>$no</code>\n🌐 Network: <b>USDT (BEP20)</b>\n💵 USD: {$d['usd']}\n💰 Total: <b>{$d['total']} BDT</b>\n📌 Status: <b>Pending Verification</b>\n⏱️ Time limit: <b>45 minutes</b>\n\nYou will receive a Telegram update after verification."));
          foreach($admins as $aid)apiSend($token,$aid,"🔔 <b>NEW ORDER</b>\n\n📋 <b>Order ID</b>\n<code>$no</code>\n\n💵 USD: <b>{$d['usd']}</b>\n💰 BDT: <b>{$d['total']}</b>\n🌐 Network: <b>USDT (BEP20)</b>\n🏦 Method: <b>{$d['method']}</b>\n🔖 TrxID: <code>{$d['trxid']}</code>\n\n🎯 <b>BEP20 Address</b>\n<code>{$d['address']}</code>\n\n<code>/approve $no</code>\n<code>/reject $no</code>");
          // Restore the permanent user menu so the temporary BEP20 keyboard does not remain on screen.
          menu($token,$chat,$lang,tr($lang,'🏠 <b>মূল মেনু</b>\nনিচের অপশন থেকে নির্বাচন করুন।','🏠 <b>Main Menu</b>\nChoose an option below.'));
        }
      }
    }
    echo 'OK';exit;
  }

  // Admin manual-release flow: after pressing the Telegram button, the next TX hash completes the queue item.
  $adminSession=getsess($db,$uid);
  if($adminSession && $adminSession[0]==='admin_sent' && $text!=='/cancel'){
    $d=$adminSession[1];$wid=(int)($d['withdrawal_id']??0);$ono=(string)($d['order_no']??'');
    if(!preg_match('/^(0x)?[a-fA-F0-9]{32,128}$/',$text)){apiSend($token,$chat,'⚠️ Valid TX hash/reference দিন অথবা /cancel করুন।');echo 'OK';exit;}
    $s=$db->prepare("UPDATE withdrawal_requests SET status='sent',tx_hash=?,verification_error=NULL WHERE id=? AND status IN ('queued','hold','processing','submitted')");$s->execute([$text,$wid]);
    if($s->rowCount()){$db->prepare("UPDATE orders SET withdrawal_status='sent' WHERE order_no=?")->execute([$ono]);$n=$db->prepare('SELECT chat_id FROM telegram_order_contacts WHERE order_no=?');$n->execute([$ono]);if($uc=$n->fetchColumn())apiSend($token,(string)$uc,"✅ <b>USDT Sent</b>\nOrder: <code>$ono</code>\n🌐 Network: BEP20\n🔗 TX: <code>".htmlspecialchars($text)."</code>");apiSend($token,$chat,"✅ <code>$ono</code> marked SENT.");}else apiSend($token,$chat,'⚠️ Queue item could not be updated.');
    clearsess($db,$uid);echo 'OK';exit;
  }
  if($text==='/cancel' && $adminSession && $adminSession[0]==='admin_sent'){clearsess($db,$uid);apiSend($token,$chat,'❌ Manual release cancelled.');echo 'OK';exit;}
  $parts=preg_split('/\s+/',$text)?:[];$cmd=strtolower(explode('@',$parts[0]??'')[0]);$arg=trim(implode(' ',array_slice($parts,1)));
  switch($cmd){
    case '/start':case '/help':apiSend($token,$chat,"💳 <b>Dollar Topup Admin</b>\n/setprice 130\n/setbkash INFO\n/setnagad INFO\n/setbank INFO\n/setbkashauto INFO\n/bkashauto on|off|status\n/orders\n/approve ORDER\n/reject ORDER\n/queue\n/process [limit]\n/hold ORDER REASON\n/retry ORDER\n/sent ORDER TXHASH\n/auto on|off|status\n/mode auto|manual\n/process [limit]\n/sent ORDER TXHASH");break;
    case '/setprice':$v=(float)($parts[1]??0);if($v>0){sv($db,'dollar_price_bdt',(string)$v);apiSend($token,$chat,'✅ Price updated');}else apiSend($token,$chat,'Usage: /setprice 130');break;
    case '/setbkash':case '/setnagad':case '/setbank':case '/setbkashauto':
      if($arg==='')apiSend($token,$chat,"Usage: $cmd payment details");
      else{$key=['/setbkash'=>'bkash_instructions','/setnagad'=>'nagad_instructions','/setbank'=>'bank_instructions','/setbkashauto'=>'bkash_auto_instructions'][$cmd];sv($db,$key,$arg);apiSend($token,$chat,'✅ Payment details saved.');}
      break;
    case '/bkashauto':
      $mode=strtolower($parts[1]??'status');
      if($mode==='on'){sv($db,'bkash_auto_enabled','1');apiSend($token,$chat,'🤖 bKash Auto button: ON');}
      elseif($mode==='off'){sv($db,'bkash_auto_enabled','0');apiSend($token,$chat,'⛔ bKash Auto button: OFF');}
      else apiSend($token,$chat,'🤖 bKash Auto: '.(gv($db,'bkash_auto_enabled','0')==='1'?'ON':'OFF'));
      break;
    case '/orders':$r=$db->query("SELECT order_no,total_bdt,status FROM orders WHERE created_at>=NOW()-INTERVAL 90 DAY ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);$o=$r?"📋 <b>Orders</b>\n":'No orders';foreach($r as $x)$o.="\n<code>{$x['order_no']}</code> | {$x['total_bdt']} BDT | {$x['status']}";apiSend($token,$chat,$o);break;
    case '/reject':
      if($arg===''){apiSend($token,$chat,'Usage: /reject ORDER');break;}
      $s=$db->prepare("UPDATE orders SET status='rejected' WHERE order_no=? AND status='pending'");$s->execute([$arg]);
      if($s->rowCount()){
        $n=$db->prepare('SELECT chat_id,telegram_user_id FROM telegram_order_contacts WHERE order_no=?');$n->execute([$arg]);
        if($uc=$n->fetch(PDO::FETCH_ASSOC))apiSend($token,(string)$uc['chat_id'],'❌ <b>Your order was rejected.</b> Please contact support if needed.');
      }
      apiSend($token,$chat,$s->rowCount()?"❌ $arg → rejected":'❌ Not found/already processed');break;
    case '/approve':
      if($arg===''){apiSend($token,$chat,'Usage: /approve ORDER');break;}
      $db->beginTransaction();try{$s=$db->prepare("SELECT order_no,usd_amount,bep20_address,payment_deadline FROM orders WHERE order_no=? AND status='pending' FOR UPDATE");$s->execute([$arg]);$o=$s->fetch(PDO::FETCH_ASSOC);if(!$o)throw new RuntimeException('Order not found');if(!empty($o['payment_deadline'])&&strtotime((string)$o['payment_deadline'])<time())throw new RuntimeException('Order payment deadline expired');$db->prepare("INSERT INTO withdrawal_requests(order_no,destination_address,amount,status) VALUES(?,?,?,'queued')")->execute([$o['order_no'],$o['bep20_address'],$o['usd_amount']]);$db->prepare("UPDATE orders SET status='approved',withdrawal_status='queued',withdrawal_requested_at=NOW() WHERE order_no=?")->execute([$arg]);$db->commit();$n=$db->prepare('SELECT c.chat_id,u.language FROM telegram_order_contacts c LEFT JOIN telegram_users u ON u.telegram_user_id=c.telegram_user_id WHERE c.order_no=?');$n->execute([$arg]);if($uc=$n->fetch(PDO::FETCH_ASSOC))apiSend($token,(string)$uc['chat_id'],(($uc['language']??'bn')==='en'?'✅ <b>Your order is approved.</b>\nWithdrawal processing has started.':'✅ <b>আপনার অর্ডার অনুমোদন করা হয়েছে।</b>\nWithdrawal processing শুরু হয়েছে।'));apiSend($token,$chat,"✅ $arg approved. Withdrawal queued.");}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}break;
    case '/queue':
      $r=$db->query("SELECT id,order_no,amount,status,verification_error,tx_hash,binance_withdraw_id FROM withdrawal_requests WHERE status IN ('queued','processing','hold','submitted') ORDER BY id ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
      $auto=strtolower(gv($db,'auto_withdraw_enabled',empty($config['binance_auto_withdraw'])?'0':'1'));
      $o=$r?"💸 <b>Withdrawal Queue</b>\n🤖 Auto: <b>".($auto==='1'?'ON':'OFF')."</b> | 👤 Manual: <b>Available</b>\n":'Queue empty';
      foreach($r as $x){$o.="\n\n<code>{$x['order_no']}</code>\n{$x['amount']} USDT | <b>{$x['status']}</b>";if($x['status']==='queued')$o.="\n🤖 Auto: ".($auto==='1'?'will process':'OFF')." | 👤 Manual: <code>/sent {$x['order_no']} TXHASH</code>";if($x['binance_withdraw_id'])$o.="\nBinance ID: <code>{$x['binance_withdraw_id']}</code>";if($x['verification_error'])$o.="\n⚠️ ".htmlspecialchars($x['verification_error']);}
      apiSend($token,$chat,$o);
      foreach($r as $x){
        $buttons=[];
        if($x['status']!=='sent')$buttons[]=[['text'=>'💸 Manual Release','callback_data'=>'REL|'.$x['id']],['text'=>'⏸ Hold','callback_data'=>'HOLD|'.$x['id']]];
        if($x['status']==='hold')$buttons[]=[['text'=>'🔄 Re-queue','callback_data'=>'RETRY|'.$x['id']]];
        if($buttons)apiInline($token,$chat,"<b>{$x['order_no']}</b> • {$x['amount']} USDT • <b>".htmlspecialchars(strtoupper($x['status']))."</b>",$buttons);
      }
      break;
    case '/mode':
      $mode=strtolower($parts[1]??'');
      if($mode==='auto'){sv($db,'auto_withdraw_enabled','1');apiSend($token,$chat,'🤖 Mode: AUTOMATIC\nQueued orders will be processed by Binance worker.');}
      elseif($mode==='manual'){sv($db,'auto_withdraw_enabled','0');apiSend($token,$chat,'👤 Mode: MANUAL\nUse /sent ORDER TXHASH after sending.');}
      else apiSend($token,$chat,'Usage: /mode auto or /mode manual');
      break;
    case '/auto':
      $mode=strtolower($parts[1]??'status');
      if($mode==='on'){sv($db,'auto_withdraw_enabled','1');apiSend($token,$chat,'🤖 Auto withdrawal: ON');}
      elseif($mode==='off'){sv($db,'auto_withdraw_enabled','0');apiSend($token,$chat,'⛔ Auto withdrawal: OFF');}
      else{apiSend($token,$chat,'🤖 Auto withdrawal: '.(strtolower(gv($db,'auto_withdraw_enabled',empty($config['binance_auto_withdraw'])?'0':'1'))==='1'?'ON':'OFF'));}
      break;
    case '/process':
      $limit=max(1,min(20,(int)($parts[1]??10)));
      $rr=processWithdrawals($db,$config,$limit,true);apiSend($token,$chat,'⚙️ '.$rr['message']);break;
    case '/hold':
      $p=preg_split('/\s+/',trim($arg),2);$ono=$p[0]??'';$reason=$p[1]??'Manual hold';
      if($ono===''){apiSend($token,$chat,'Usage: /hold ORDER REASON');break;}
      $s=$db->prepare("UPDATE withdrawal_requests SET status='hold',verification_error=? WHERE order_no=? AND status IN ('queued','processing','submitted')");$s->execute([$reason,$ono]);
      $db->prepare("UPDATE orders SET withdrawal_status='hold' WHERE order_no=?")->execute([$ono]);apiSend($token,$chat,$s->rowCount()?"⏸️ $ono → hold":'Not found/not changeable');break;
    case '/retry':
      if($arg===''){apiSend($token,$chat,'Usage: /retry ORDER');break;}
      $s=$db->prepare("UPDATE withdrawal_requests SET status='queued',verification_error=NULL WHERE order_no=? AND status='hold'");$s->execute([$arg]);
      $db->prepare("UPDATE orders SET withdrawal_status='queued' WHERE order_no=?")->execute([$arg]);apiSend($token,$chat,$s->rowCount()?"🔄 $arg → queued":'Not found/not on hold');break;
    case '/sent':
      $p=preg_split('/\s+/',trim($arg),2);$ono=$p[0]??'';$tx=$p[1]??'';
      if($ono===''||$tx===''){apiSend($token,$chat,'Usage: /sent ORDER TX_HASH');break;}
      if(!preg_match('/^(0x)?[a-fA-F0-9]{32,128}$/',$tx)){apiSend($token,$chat,'Invalid TX hash/reference.');break;}$s=$db->prepare("UPDATE withdrawal_requests SET status='sent',tx_hash=?,verification_error=NULL WHERE order_no=? AND status IN ('queued','hold','processing','submitted')");$s->execute([$tx,$ono]);
      $db->prepare("UPDATE orders SET withdrawal_status='sent' WHERE order_no=?")->execute([$ono]);
      $n=$db->prepare('SELECT chat_id FROM telegram_order_contacts WHERE order_no=?');$n->execute([$ono]);if($uc=$n->fetchColumn())apiSend($token,(string)$uc,"✅ <b>USDT Sent</b>\nOrder: <code>$ono</code>\n🌐 Network: BEP20\n🔗 TX: <code>".htmlspecialchars($tx)."</code>");
      apiSend($token,$chat,$s->rowCount()?"✅ $ono → sent":'Not found/not changeable');break;
    default:apiSend($token,$chat,'Use /help');
  }
}catch(Throwable $e){
  error_log('Telegram error: '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
  if(isset($token,$chat)&&$chat!=='')apiSend($token,$chat,'⚠️ Order process করা যায়নি। দয়া করে আবার /start দিয়ে চেষ্টা করুন।');
}
echo 'OK';
