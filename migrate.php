<?php
declare(strict_types=1);
$db=require __DIR__.'/config/database.php';
function col(PDO $db,string $table,string $name,string $ddl):void{
  $s=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $s->execute([$table,$name]);
  if(!(int)$s->fetchColumn()){$db->exec("ALTER TABLE ".$table." ADD COLUMN ".$ddl);echo "Added ".$table.".".$name.PHP_EOL;}
}
$db->exec("CREATE TABLE IF NOT EXISTS telegram_order_sessions (telegram_user_id BIGINT NOT NULL PRIMARY KEY, chat_id BIGINT NOT NULL, step VARCHAR(30) NOT NULL, data_json TEXT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS telegram_order_contacts (order_no VARCHAR(100) NOT NULL PRIMARY KEY, telegram_user_id BIGINT NOT NULL, chat_id BIGINT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS telegram_users (telegram_user_id BIGINT NOT NULL PRIMARY KEY, chat_id BIGINT NOT NULL, username VARCHAR(100) NULL, first_name VARCHAR(150) NULL, last_name VARCHAR(150) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS withdrawal_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_no VARCHAR(100) NOT NULL, destination_address VARCHAR(64) NOT NULL, amount DECIMAL(18,8) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'queued', tx_hash VARCHAR(66) NULL, verification_error VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_withdrawal_order(order_no))");
col($db,'orders','payment_method','payment_method VARCHAR(20) NULL');
col($db,'orders','payment_reference','payment_reference VARCHAR(100) NULL');
col($db,'orders','bep20_address','bep20_address VARCHAR(64) NULL');
col($db,'orders','payment_deadline','payment_deadline DATETIME NULL');
col($db,'orders','withdrawal_status','withdrawal_status VARCHAR(30) NULL');
col($db,'orders','withdrawal_requested_at','withdrawal_requested_at DATETIME NULL');
echo "Database schema OK".PHP_EOL;
