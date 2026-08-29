<?php
declare(strict_types=1);
$db=require __DIR__.'/../config/database.php';
$usd=filter_input(INPUT_POST,'usd_amount',FILTER_VALIDATE_FLOAT);
$phone=trim((string)($_POST['phone_number']??''));
$trxid=trim((string)($_POST['bkash_trxid']??''));
$method=trim((string)($_POST['payment_method']??''));
$address=trim((string)($_POST['bep20_address']??''));
if($usd===false||$usd===null||$usd<=0||$phone===''||$trxid===''||!in_array($method,['bkash','bank'],true)){http_response_code(422);exit('Invalid request.');}
if(!preg_match('/^[0-9+()\-\s]{7,30}$/',$phone)){http_response_code(422);exit('Invalid phone number.');}
if(!preg_match('/^0x[a-fA-F0-9]{40}$/',$address)){http_response_code(422);exit('Invalid BEP20 address.');}
$stmt=$db->prepare("SELECT setting_value FROM settings WHERE setting_key='dollar_price_bdt' LIMIT 1");$stmt->execute();$rate=(float)($stmt->fetchColumn()?:130);if($rate<=0)$rate=130;
$total=round($usd*$rate,2);$orderNo='DTC-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$deadline=date('Y-m-d H:i:s',time()+1800);
$stmt=$db->prepare("INSERT INTO orders(order_no,usd_amount,dollar_price_bdt,total_bdt,phone_number,bkash_trxid,payment_method,payment_reference,bep20_address,payment_deadline,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending')");
$stmt->execute([$orderNo,$usd,$rate,$total,$phone,$trxid,$method,$trxid,$address,$deadline]);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoice</title><style>body{font-family:Arial;padding:30px;max-width:600px;margin:auto}.timer{font-size:22px;font-weight:bold;color:#b42318}</style></head><body><h2>🧾 Invoice Created</h2><p><b>Order:</b> <?=htmlspecialchars($orderNo)?></p><p><b>USD:</b> <?=number_format($usd,2)?></p><p><b>Total:</b> <?=number_format($total,2)?> BDT</p><p><b>Payment:</b> <?=htmlspecialchars(strtoupper($method))?></p><p><b>Status:</b> Pending admin verification</p><p>Invoice expires in: <span class="timer" id="timer">30:00</span></p><p>After payment is verified, the order can be processed for withdrawal to the submitted address.</p><script>let s=1800;setInterval(()=>{s=Math.max(0,s-1);let m=Math.floor(s/60),x=s%60;document.getElementById('timer').textContent=String(m).padStart(2,'0')+':'+String(x).padStart(2,'0');},1000);</script></body></html>