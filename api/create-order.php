<?php
declare(strict_types=1);

$db = require __DIR__ . '/../config/database.php';
$config = require __DIR__ . '/../config/config.php';

function invoiceTextImage(array $d): string {
    $w=1200;$h=900;
    $img=imagecreatetruecolor($w,$h);
    $bg=imagecolorallocate($img,245,247,251);
    $dark=imagecolorallocate($img,24,32,48);
    $blue=imagecolorallocate($img,22,119,255);
    $muted=imagecolorallocate($img,90,100,120);
    $white=imagecolorallocate($img,255,255,255);
    imagefill($img,0,0,$bg);
    imagefilledrectangle($img,50,45,$w-50,$h-45,$white);
    imagefilledrectangle($img,50,45,$w-50,160,$blue);
    imagestring($img,5,90,85,'DOLLAR TOPUP CARD - PAYMENT INVOICE',$white);
    $lines=[
      'Invoice: '.$d['order'],
      'USD Amount: $'.number_format((float)$d['usd'],2),
      'Total: '.number_format((float)$d['total'],2).' BDT',
      'Method: '.strtoupper($d['method']),
      'Phone: '.$d['phone'],
      'TrxID / Reference: '.$d['trxid'],
      'BEP20 Address: '.$d['address'],
      'Status: PENDING VERIFICATION',
      'Expires: '.$d['deadline'].' (30 minutes)',
    ];
    $y=210;
    foreach($lines as $line){imagestring($img,5,90,$y,substr($line,0,125),$dark);$y+=65;}
    imagestring($img,3,90,805,'After payment verification, processing will begin according to your configured workflow.',$muted);
    $dir=dirname(__DIR__).'/storage/invoices';
    if(!is_dir($dir)) @mkdir($dir,0750,true);
    $file=$dir.'/'.$d['order'].'.png';
    imagepng($img,$file,6);imagedestroy($img);
    return $file;
}

function tgInvoicePhoto(string $token,string $chatId,string $file,string $caption): ?string {
    if($token===''||$chatId===''||!is_file($file)) return null;
    $ch=curl_init('https://api.telegram.org/bot'.$token.'/sendPhoto');
    $post=['chat_id'=>$chatId,'caption'=>$caption,'photo'=>new CURLFile($file,'image/png',basename($file))];
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    $raw=curl_exec($ch);curl_close($ch);
    $json=is_string($raw)?json_decode($raw,true):null;
    return !empty($json['ok']) ? (string)($json['result']['message_id']??'') : null;
}

$usd=filter_input(INPUT_POST,'usd_amount',FILTER_VALIDATE_FLOAT);
$phone=trim((string)($_POST['phone_number']??''));
$trxid=trim((string)($_POST['bkash_trxid']??''));
$method=trim((string)($_POST['payment_method']??''));
$address=trim((string)($_POST['bep20_address']??''));

if($usd===false||$usd===null||$usd<=0||$usd>10000||$phone===''||$trxid===''||!in_array($method,['bkash','bank'],true)){http_response_code(422);exit('Invalid request.');}
if(!preg_match('/^[0-9+()\-\s]{7,30}$/',$phone)){http_response_code(422);exit('Invalid phone number.');}
if(!preg_match('/^0x[a-fA-F0-9]{40}$/',$address)){http_response_code(422);exit('Invalid BEP20 address.');}

$stmt=$db->prepare("SELECT setting_value FROM settings WHERE setting_key='dollar_price_bdt' LIMIT 1");
$stmt->execute();$rate=(float)($stmt->fetchColumn()?:130);if($rate<=0)$rate=130;
$total=round($usd*$rate,2);
$orderNo='DTC-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
$deadline=date('Y-m-d H:i:s',time()+1800);

$stmt=$db->prepare("INSERT INTO orders(order_no,usd_amount,dollar_price_bdt,total_bdt,phone_number,bkash_trxid,payment_method,payment_reference,bep20_address,payment_deadline,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending')");
$stmt->execute([$orderNo,$usd,$rate,$total,$phone,$trxid,$method,$trxid,$address,$deadline]);

// Send invoice picture to every configured Telegram admin.
$photoSent=false;
if(function_exists('imagecreatetruecolor') && function_exists('curl_init')){
    try{
        $file=invoiceTextImage(['order'=>$orderNo,'usd'=>$usd,'total'=>$total,'method'=>$method,'phone'=>$phone,'trxid'=>$trxid,'address'=>$address,'deadline'=>$deadline]);
        foreach($config['admin_telegram_ids'] as $adminId){
            $mid=tgInvoicePhoto((string)$config['telegram_bot_token'],(string)$adminId,$file,"🧾 <b>New Invoice</b>\nOrder: <b>{$orderNo}</b>\nUSD: $".number_format($usd,2)."\nTotal: ".number_format($total,2)." BDT\nStatus: Pending");
            if($mid!==null)$photoSent=true;
        }
    }catch(Throwable $e){error_log('Invoice Telegram photo error: '.$e->getMessage());}
}

?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoice</title><style>body{font-family:Arial;padding:30px;max-width:600px;margin:auto}.timer{font-size:22px;font-weight:bold;color:#b42318}</style></head><body><h2>🧾 Invoice Created</h2><p><b>Order:</b> <?=htmlspecialchars($orderNo)?></p><p><b>USD:</b> <?=number_format($usd,2)?></p><p><b>Total:</b> <?=number_format($total,2)?> BDT</p><p><b>Payment:</b> <?=htmlspecialchars(strtoupper($method))?></p><p><b>Status:</b> Pending admin verification</p><p>Invoice expires in: <span class="timer" id="timer">30:00</span></p><p><?= $photoSent ? '📨 Invoice picture sent to the Telegram admin.' : 'Invoice created successfully.' ?></p><script>let s=1800;setInterval(()=>{s=Math.max(0,s-1);let m=Math.floor(s/60),x=s%60;document.getElementById('timer').textContent=String(m).padStart(2,'0')+':'+String(x).padStart(2,'0');},1000);</script></body></html>