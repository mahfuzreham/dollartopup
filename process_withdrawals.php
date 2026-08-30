<?php
declare(strict_types=1);
$config=require __DIR__.'/config/config.php';
$db=require __DIR__.'/config/database.php';
require __DIR__.'/lib/withdrawals.php';
$r=processWithdrawals($db,$config,10);
echo $r['message'].PHP_EOL;
