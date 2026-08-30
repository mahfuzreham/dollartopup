<?php
declare(strict_types=1);

function binanceSignedRequest(array $cfg,string $method,string $path,array $params):array{
  $params['timestamp']=(string)floor(microtime(true)*1000);
  $params['recvWindow']=(string)min(60000,max(1000,(int)($cfg['binance_recv_window']??5000)));
  ksort($params);
  $query=http_build_query($params,'','&',PHP_QUERY_RFC3986);
  $sig=hash_hmac('sha256',$query,(string)$cfg['binance_api_secret']);
  $url=rtrim((string)$cfg['binance_base_url'],'/').$path.'?'.$query.'&signature='.$sig;
  $h=curl_init($url);
  curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['X-MBX-APIKEY: '.$cfg['binance_api_key']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10]);
  $raw=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$err=curl_error($h);curl_close($h);
  $json=is_string($raw)?json_decode($raw,true):null;
  if(!is_array($json))$json=['raw'=>$raw,'curl_error'=>$err];
  return ['code'=>$code,'body'=>$json];
}
function binanceWithdraw(array $cfg,string $orderNo,string $address,float $amount):array{
  if(empty($cfg['binance_api_key'])||empty($cfg['binance_api_secret']))return ['ok'=>false,'error'=>'Binance API credentials are not configured'];
  $r=binanceSignedRequest($cfg,'POST','/sapi/v1/capital/withdraw/apply',[
    'coin'=>'USDT','network'=>$cfg['binance_usdt_network']?:'BSC','address'=>$address,
    'amount'=>number_format($amount,8,'.',''),'withdrawOrderId'=>$orderNo,'walletType'=>(string)($cfg['binance_wallet_type']??0)
  ]);
  if($r['code']>=200&&$r['code']<300&&isset($r['body']['id']))return ['ok'=>true,'id'=>(string)$r['body']['id'],'body'=>$r['body']];
  return ['ok'=>false,'error'=>(string)($r['body']['msg']??$r['body']['code']??'Binance withdrawal failed'),'body'=>$r['body']];
}
function processWithdrawals(PDO $db,array $cfg,int $limit=10):array{
   $override=null;try{$s=$db->prepare("SELECT setting_value FROM settings WHERE setting_key='auto_withdraw_enabled'");$s->execute();$v=$s->fetchColumn();if($v!==false)$override=in_array(strtolower((string)$v),['1','true','on','yes'],true);}catch(Throwable $e){} if($override===false||($override===null&&empty($cfg['binance_auto_withdraw'])))return ['processed'=>0,'sent'=>0,'failed'=>0,'message'=>'Auto withdrawal is OFF'];
  $rows=$db->query("SELECT id,order_no,destination_address,amount FROM withdrawal_requests WHERE status='queued' ORDER BY id ASC LIMIT ".max(1,min(50,$limit)))->fetchAll(PDO::FETCH_ASSOC);
  $out=['processed'=>0,'sent'=>0,'failed'=>0,'message'=>''];
  foreach($rows as $w){
    $claim=$db->prepare("UPDATE withdrawal_requests SET status='processing' WHERE id=? AND status='queued'");
    $claim->execute([$w['id']]); if($claim->rowCount()!==1)continue;
    $out['processed']++;
    $res=binanceWithdraw($cfg,$w['order_no'],$w['destination_address'],(float)$w['amount']);
    if($res['ok']){
      $db->prepare("UPDATE withdrawal_requests SET status='submitted',binance_withdraw_id=?,verification_error=NULL WHERE id=?")->execute([$res['id'],$w['id']]);
      $db->prepare("UPDATE orders SET withdrawal_status='submitted' WHERE order_no=?")->execute([$w['order_no']]);
      $out['sent']++;
    }else{
      $err=substr($res['error'],0,255);
      $db->prepare("UPDATE withdrawal_requests SET status='hold',verification_error=? WHERE id=?")->execute([$err,$w['id']]);
      $db->prepare("UPDATE orders SET withdrawal_status='hold' WHERE order_no=?")->execute([$w['order_no']]);
      $out['failed']++;
    }
  }
  $out['message']="Processed {$out['processed']}, submitted {$out['sent']}, held {$out['failed']}";
  return $out;
}
