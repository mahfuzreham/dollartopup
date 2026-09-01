<?php
declare(strict_types=1);
session_start();
$config=require __DIR__.'/../config/config.php';
$user=$config['admin_web_user']??'';$pass=$config['admin_web_pass']??'';
if($user===''||$pass===''){http_response_code(503);exit('Admin web access is not configured. Set ADMIN_WEB_USER and ADMIN_WEB_PASS in .env');}
if(!isset($_SERVER['PHP_AUTH_USER'])||!hash_equals($user,(string)$_SERVER['PHP_AUTH_USER'])||!password_verify((string)$_SERVER['PHP_AUTH_PW'],password_hash($pass,PASSWORD_DEFAULT))){
  header('WWW-Authenticate: Basic realm="Withdrawal Admin"');http_response_code(401);exit('Authentication required');
}
$db=require __DIR__.'/../config/database.php';
if(empty($_SESSION['queue_csrf']))$_SESSION['queue_csrf']=bin2hex(random_bytes(24));
$flash='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['queue_csrf'],(string)($_POST['csrf']??''))){http_response_code(400);exit('Invalid request token');}
  $id=(int)($_POST['id']??0);$action=(string)($_POST['action']??'');
  $s=$db->prepare('SELECT id,order_no,status FROM withdrawal_requests WHERE id=?');$s->execute([$id]);$w=$s->fetch(PDO::FETCH_ASSOC);
  if(!$w){$flash='Order not found.';}
  elseif($action==='sent'){
    $tx=trim((string)($_POST['tx_hash']??''));
    if(!preg_match('/^(0x)?[a-fA-F0-9]{32,128}$/',$tx))$flash='Enter a valid transaction hash/reference.';
    else{$db->beginTransaction();try{$db->prepare("UPDATE withdrawal_requests SET status='sent',tx_hash=?,verification_error=NULL WHERE id=? AND status IN ('queued','hold','processing','submitted')")->execute([$tx,$id]);$db->prepare("UPDATE orders SET withdrawal_status='sent' WHERE order_no=?")->execute([$w['order_no']]);$db->commit();$flash='Withdrawal released and marked SENT.';}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$flash='Update failed.';}}
  }elseif($action==='hold'){
    $reason=substr(trim((string)($_POST['reason']??'Manual hold')),0,255);
    $db->prepare("UPDATE withdrawal_requests SET status='hold',verification_error=? WHERE id=? AND status<>'sent'")->execute([$reason,$id]);$db->prepare("UPDATE orders SET withdrawal_status='hold' WHERE order_no=?")->execute([$w['order_no']]);$flash='Withdrawal put on HOLD.';
  }elseif($action==='requeue'){
    $db->prepare("UPDATE withdrawal_requests SET status='queued',verification_error=NULL WHERE id=? AND status='hold'")->execute([$id]);$db->prepare("UPDATE orders SET withdrawal_status='queued' WHERE order_no=?")->execute([$w['order_no']]);$flash='Withdrawal returned to QUEUE.';
  }
}
$rows=$db->query("SELECT id,order_no,destination_address,amount,status,tx_hash,verification_error,binance_withdraw_id,created_at,updated_at FROM withdrawal_requests ORDER BY FIELD(status,'queued','processing','hold','submitted','sent'),created_at ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Withdrawal Queue</title>
<style>body{font-family:Arial;background:#f4f6f9;margin:0;color:#182230}.wrap{max-width:1300px;margin:auto;padding:22px}h1{margin:0 0 8px}.note{color:#667085}.flash{padding:12px;background:#ecfdf3;border-radius:9px;margin:14px 0}.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px #0001;overflow:auto}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:12px;border-bottom:1px solid #eef0f3;text-align:left;vertical-align:top}.addr{font-family:monospace;word-break:break-all;max-width:300px}.tag{padding:5px 8px;border-radius:999px;background:#eef2ff;font-weight:bold;font-size:12px}.queued{background:#fff7d6}.hold{background:#fee4e2}.sent{background:#dcfae6}.actions form{display:inline-block;margin:3px}button,input{padding:8px;border-radius:7px;border:1px solid #d0d5dd}button{cursor:pointer;font-weight:bold}.release{background:#12b76a;color:#fff;border:0}.holdbtn{background:#f04438;color:#fff;border:0}.queuebtn{background:#175cd3;color:#fff;border:0}.small{font-size:12px;color:#667085}</style></head><body><div class="wrap"><h1>💸 Withdrawal Queue</h1><p class="note">Manual release: after you send USDT yourself, paste the real transaction hash and click <b>Release / Mark Sent</b>. Automatic mode is handled separately by the worker.</p>
<?php if($flash):?><div class="flash"><?=h($flash)?></div><?php endif;?>
<div class="card"><table><thead><tr><th>Order</th><th>Amount</th><th>BEP20 Address</th><th>Status</th><th>Tracking</th><th>Manual Control</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><b><?=h($r['order_no'])?></b><div class="small"><?=h($r['created_at'])?></div></td><td><?=h($r['amount'])?> USDT</td><td class="addr"><?=h($r['destination_address'])?></td><td><span class="tag <?=h($r['status'])?>"><?=h(strtoupper($r['status']))?></span><?php if($r['verification_error']):?><div class="small"><?=h($r['verification_error'])?></div><?php endif;?></td><td class="small"><?php if($r['binance_withdraw_id']):?>Binance: <?=h($r['binance_withdraw_id'])?><br><?php endif;?><?=h($r['tx_hash']??'')?></td><td class="actions">
<?php if($r['status']!=='sent'):?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['queue_csrf'])?>"><input type="hidden" name="id" value="<?=h($r['id'])?>"><input type="hidden" name="action" value="sent"><input name="tx_hash" placeholder="TX Hash" required><button class="release">Release / Mark Sent</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['queue_csrf'])?>"><input type="hidden" name="id" value="<?=h($r['id'])?>"><input type="hidden" name="action" value="hold"><input name="reason" placeholder="Hold reason"><button class="holdbtn">Hold</button></form>
<?php if($r['status']==='hold'):?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['queue_csrf'])?>"><input type="hidden" name="id" value="<?=h($r['id'])?>"><input type="hidden" name="action" value="requeue"><button class="queuebtn">Re-queue</button></form><?php endif;?><?php else:?><b>Completed</b><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></div></body></html>