<?php
declare(strict_types=1);
$db=require __DIR__.'/config/database.php';
$stmt=$db->prepare("SELECT setting_value FROM settings WHERE setting_key='dollar_price_bdt' LIMIT 1");$stmt->execute();
$price=(float)($stmt->fetchColumn()?:130); if($price<=0)$price=130;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dollar Topup Card</title>
<style>body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;padding:24px}.wrap{max-width:560px;margin:auto}.card{background:#fff;padding:24px;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08)}label{display:block;font-weight:600;margin-top:14px}input,select,button{width:100%;box-sizing:border-box;padding:13px;margin-top:7px;border:1px solid #d8dce5;border-radius:10px;font-size:16px}input[readonly]{background:#f3f4f6}button{border:0;background:#1677ff;color:#fff;font-weight:700;cursor:pointer}.hint{color:#667085;font-size:14px}.timer{font-weight:700;color:#b42318}</style></head><body><div class="wrap"><div class="card"><h1>💳 Dollar Topup Card</h1><p class="hint">Current rate: <b id="rate"><?=htmlspecialchars(number_format($price,2))?> BDT/USD</b></p>
<form method="post" action="api/create-order.php" autocomplete="off">
<label>Dollar Amount (USD)</label><input id="usd_amount" name="usd_amount" type="number" min="1" max="10000" step="0.01" required>
<label>Total Amount (BDT)</label><input id="total_bdt" readonly placeholder="0.00 BDT">
<label>Payment Method</label><select name="payment_method" required><option value="bkash">bKash</option><option value="bank">Bank Transfer</option></select>
<label>Phone Number</label><input name="phone_number" type="tel" maxlength="30" required>
<label>Payment TrxID / Reference</label><input name="bkash_trxid" maxlength="100" required>
<label>USDT BEP20 Address</label><input name="bep20_address" minlength="42" maxlength="128" placeholder="0x..." required>
<p class="hint">After submission, your invoice is valid for <b>30 minutes</b>. Payment approval is handled by the admin.</p>
<button type="submit">Create Invoice</button></form></div></div>
<script>const rate=<?=json_encode($price)?>,usd=document.getElementById('usd_amount'),total=document.getElementById('total_bdt');usd.addEventListener('input',()=>{const v=parseFloat(usd.value)||0;total.value=(v*rate).toFixed(2)+' BDT';});</script></body></html>