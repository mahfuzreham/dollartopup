-- Run once on existing installations.
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20) NULL AFTER total_bdt,
  ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(120) NULL AFTER bkash_trxid,
  ADD COLUMN IF NOT EXISTS bep20_address VARCHAR(128) NULL AFTER payment_reference,
  ADD COLUMN IF NOT EXISTS payment_deadline DATETIME NULL AFTER status,
  ADD COLUMN IF NOT EXISTS withdrawal_status ENUM('not_requested','queued','processing','completed','failed') NOT NULL DEFAULT 'not_requested' AFTER payment_deadline,
  ADD COLUMN IF NOT EXISTS withdrawal_reference VARCHAR(120) NULL AFTER withdrawal_status,
  ADD COLUMN IF NOT EXISTS withdrawal_requested_at DATETIME NULL AFTER withdrawal_reference;

CREATE TABLE IF NOT EXISTS withdrawal_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(50) NOT NULL UNIQUE,
  asset VARCHAR(20) NOT NULL DEFAULT 'USDT',
  network VARCHAR(20) NOT NULL DEFAULT 'BSC',
  destination_address VARCHAR(128) NOT NULL,
  amount DECIMAL(18,8) NOT NULL,
  status ENUM('queued','processing','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  provider_reference VARCHAR(120) NULL,
  error_message VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_withdrawal_status (status)
);