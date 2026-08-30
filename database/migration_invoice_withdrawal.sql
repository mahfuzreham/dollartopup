-- Telegram order flow tables
CREATE TABLE IF NOT EXISTS telegram_order_sessions (
 telegram_user_id BIGINT NOT NULL PRIMARY KEY,
 chat_id BIGINT NOT NULL,
 step VARCHAR(30) NOT NULL,
 data_json TEXT NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS telegram_order_contacts (
 order_no VARCHAR(100) NOT NULL PRIMARY KEY,
 telegram_user_id BIGINT NOT NULL,
 chat_id BIGINT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Withdrawal queue
CREATE TABLE IF NOT EXISTS withdrawal_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_no VARCHAR(100) NOT NULL,
 destination_address VARCHAR(64) NOT NULL,
 amount DECIMAL(18,8) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'queued',
 tx_hash VARCHAR(66) NULL,
 verification_error VARCHAR(255) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_withdrawal_order (order_no),
 INDEX idx_withdrawal_status (status)
);

-- Existing installations: add missing order columns one statement at a time.
ALTER TABLE orders ADD COLUMN payment_method VARCHAR(20) NULL;
ALTER TABLE orders ADD COLUMN payment_reference VARCHAR(100) NULL;
ALTER TABLE orders ADD COLUMN bep20_address VARCHAR(64) NULL;
ALTER TABLE orders ADD COLUMN payment_deadline DATETIME NULL;
ALTER TABLE orders ADD COLUMN withdrawal_status VARCHAR(30) NULL;
ALTER TABLE orders ADD COLUMN withdrawal_requested_at DATETIME NULL;

-- Existing withdrawal table upgrades.
ALTER TABLE withdrawal_requests ADD COLUMN tx_hash VARCHAR(66) NULL;
ALTER TABLE withdrawal_requests ADD COLUMN verification_error VARCHAR(255) NULL;
