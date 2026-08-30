<?php
declare(strict_types=1);

function binanceSignedRequest(array $cfg,string $method,string $path,array $params):array{
  $base=rtrim((string)($cfg['binance_base_url']??'https://api.binance.com'),'/');
  if(!filter_var($base,FILTER_VALIDATE_URL))return ['code'=>0,'body'=>['msg'=>'Invalid Binance base URL']];
  $params['timestamp']=(string)floor(microtime(true)*1000);
  $params['recvWindow']=(string)min(60000,max(1000,(int)($cfg['binance_recv_window']??5000)));
  ksort($params);
  $query=http_build_query($params,'','&',PHP_QUERY_RFC3986);
  $secret=(string)($cfg['binance_api_secret']??'');
  $signature=hash_hmac('sha256',$query,$secret);
  $url=$base.$path.'?'.$query.'&signature='.$signature;
  $h=curl_init($url);
  if($h===false)return ['code'=>0,'body'=>['msg'=>'Unable to initialize cURL']];
  curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['X-MBX-APIKEY: '.(string)($cfg['binance_api_key']??'')],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_FAILONERROR=>false]);
  $raw=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$err=curl_error($h);curl_close($h);
  $json=is_string($raw)&&$raw!==''?json_decode($raw,true):null;
  if(!is_array($json))$json=['msg'=>$err!==''?$err:'Invalid or empty Binance response'];
  return ['code'=>$code,'body'=>$json];
}
function binanceWithdraw(array $cfg,string $orderNo,string $address,float $amount):array{
  if(empty($cfg['binance_api_key'])||empty($cfg['binance_api_secret']))return ['ok'=>false,'error'=>'Binance API credentials are not configured'];
  if(!preg_match('/^0x[a-fA-F0-9]{40}$/',$address))return ['ok'=>false,'error'=>'Invalid BEP20 address'];
  if(!is_finite($amount)||$amount<=0)return ['ok'=>false,'error'=>'Invalid withdrawal amount'];
  $network=(string)($cfg['binance_usdt_network']??'BSC');
  $r=binanceSignedRequest($cfg,'POST','/sapi/v1/capital/withdraw/apply',[
    'coin'=>'USDT','network'=>$network!==''?$network:'BSC','address'=>$address,
    'amount'=>number_format($amount,8,'.',''),'withdrawOrderId'=>$orderNo,
    'walletType'=>(string)($cfg['binance_wallet_type']??0)
  ]);
  if($r['code']>=200&&$r['code']<300&&isset($r['body']['id'])&&(string)$r['body']['id']!=='')return ['ok'=>true,'id'=>(string)$r['body']['id'],'body'=>$r['body']];
  return ['ok'=>false,'error'=>substr((string)($r['body']['msg']??$r['body']['code']??('HTTP '.$r['code'].' Binance withdrawal failed')),0,255),'body'=>$r['body']];
}
function autoWithdrawEnabled(PDO $db,array $cfg,bool $force):bool{
  if($force)return true;
  $override=null;
  try{$s=$db->prepare("SELECT setting_value FROM settings WHERE setting_key='auto_withdraw_enabled'");$s->execute();$v=$s->fetchColumn();if($v!==false)$override=in_array(strtolower(trim((string)$v)),['1','true','on','yes'],true);}catch(Throwable $e){}
  return $override!==null?$override:!empty($cfg['binance_auto_withdraw']);
}
function processWithdrawals(PDO $db,array $cfg,int $limit=10,bool $force=false):array{
  if(!autoWithdrawEnabled($db,$cfg,$force))return ['processed'=>0,'sent'=>0,'failed'=>0,'message'=>'Auto withdrawal is OFF'];
  // Recover only stale claims; prevents a crashed worker from blocking the queue forever.
  $db->exec("UPDATE withdrawal_requests SET status='queued',verification_error='Recovered stale processing job' WHERE status='processing' AND updated_at < (NOW() - INTERVAL 15 MINUTE)");
  $limit=max(1,min(50,$limit));
  $rows=$db->query("SELECT id,order_no,destination_address,amount FROM withdrawal_requests WHERE status='queued' ORDER BY id ASC LIMIT ".$limit)->fetchAll(PDO::FETCH_ASSOC);
  $out=['processed'=>0,'sent'=>0,'failed'=>0,'message'=>''];
  foreach($rows as $w){
    $claim=$db->prepare("UPDATE withdrawal_requests SET status='processing',verification_error=NULL WHERE id=? AND status='queued'");
    $claim->execute([$w['id']]);if($claim->rowCount()!==1)continue;
    $out['processed']++;
    $res=binanceWithdraw($cfg,(string)$w['order_no'],(string)$w['destination_address'],(float)$w['amount']);
    if($res['ok']){
      $db->prepare("UPDATE withdrawal_requests SET status='submitted',binance_withdraw_id=?,verification_error=NULL WHERE id=?")->execute([$res['id'],$w['id']]);
      $db->prepare("UPDATE orders SET withdrawal_status='submitted' WHERE order_no=?")->execute([$w['order_no']]);
      $out['sent']++;
    }else{
      $err=substr((string)$res['error'],0,255);
      $db->prepare("UPDATE withdrawal_requests SET status='hold',verification_error=? WHERE id=?")->execute([$err,$w['id']]);
      $db->prepare("UPDATE orders SET withdrawal_status='hold' WHERE order_no=?")->execute([$w['order_no']]);
      $out['failed']++;
    }
  }
  $out['message']="Processed {$out['processed']}, submitted {$out['sent']}, held {$out['failed']}";
  return $out;
}
