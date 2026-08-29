<?php
declare(strict_types=1);
$db = require __DIR__ . '/config/database.php';
$stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'dollar_price_bdt' LIMIT 1");
$stmt->execute();
$price = (float)($stmt->fetchColumn() ?: 0);
if ($price <= 0) $price = 120;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dollar Topup Card</title>
<style>body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;padding:32px}.wrap{max-width:520px;margin:auto}.card{background:#fff;padding:26px;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08)}h1{margin-top:0}label{display:block;font-weight:600;margin-top:14px}input,button{width:100%;box-sizing:border-box;padding:13px;margin-top:7px;border:1px solid #d8dce5;border-radius:10px;font-size:16px}input[readonly]{background:#f3f4f6}button{border:0;cursor:pointer;font-weight:700}.hint{color:#667085;font-size:14px}.total{font-size:18px;font-weight:700}</style></head>
<body><div class="wrap"><div class="card"><h1>💳 Dollar Topup Card</h1><p class="hint">Current rate: <b id="rate"><?=htmlspecialchars(number_format($price, 2))?> BDT</b> per USD</p>
<form method="post" action="api/create-order.php" autocomplete="off">
<label for="usd_amount">Dollar Amount (USD)</label><input id="usd_amount" name="usd_amount" type="number" min="0.01" step="0.01" required>
<label for="total_bdt">Total Amount (BDT)</label><input id="total_bdt" name="total_bdt_display" readonly placeholder="0.00 BDT">
<label for="phone_number">Phone Number</label><input id="phone_number" name="phone_number" type="tel" maxlength="30" required>
<label for="bkash_trxid">bKash TrxID</label><input id="bkash_trxid" name="bkash_trxid" maxlength="100" required>
<button type="submit">Submit Top-up Request</button></form></div></div>
<script>const rate=<?=json_encode($price)?>; const usd=document.getElementById('usd_amount'); const total=document.getElementById('total_bdt'); usd.addEventListener('input',()=>{const v=parseFloat(usd.value)||0; total.value=(v*rate).toFixed(2)+' BDT';});</script></body></html>