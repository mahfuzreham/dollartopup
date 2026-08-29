<?php
declare(strict_types=1);

$db = require __DIR__ . '/../config/database.php';
$usd = filter_input(INPUT_POST, 'usd_amount', FILTER_VALIDATE_FLOAT);
$phone = trim((string)($_POST['phone_number'] ?? ''));
$trxid = trim((string)($_POST['bkash_trxid'] ?? ''));

if ($usd === false || $usd === null || $usd <= 0 || $phone === '' || $trxid === '') {
    http_response_code(422);
    exit('Invalid request.');
}
if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
    http_response_code(422);
    exit('Invalid phone number.');
}

$stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'dollar_price_bdt' LIMIT 1");
$stmt->execute();
$rate = (float)($stmt->fetchColumn() ?: 0);
if ($rate <= 0) {
    http_response_code(503);
    exit('Dollar price is not configured.');
}

total:
$total = round($usd * $rate, 2);
$orderNo = 'DTC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

$stmt = $db->prepare("INSERT INTO orders (order_no, usd_amount, dollar_price_bdt, total_bdt, phone_number, bkash_trxid) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$orderNo, $usd, $rate, $total, $phone, $trxid]);

?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Order Submitted</title></head>
<body style="font-family:Arial;padding:30px"><h2>✅ Top-up Request Submitted</h2><p><b>Order:</b> <?=htmlspecialchars($orderNo)?></p><p><b>Dollar:</b> <?=htmlspecialchars(number_format($usd,2))?> USD</p><p><b>Rate:</b> <?=htmlspecialchars(number_format($rate,2))?> BDT/USD</p><p><b>Total:</b> <?=htmlspecialchars(number_format($total,2))?> BDT</p><p><b>Status:</b> Pending verification</p></body></html>