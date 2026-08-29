CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO settings (setting_key, setting_value) VALUES ('dollar_price_bdt', '120')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(50) NOT NULL UNIQUE,
  usd_amount DECIMAL(12,2) NOT NULL,
  dollar_price_bdt DECIMAL(12,4) NOT NULL,
  total_bdt DECIMAL(14,2) NOT NULL,
  phone_number VARCHAR(30) NOT NULL,
  bkash_trxid VARCHAR(100) NOT NULL,
  status ENUM('pending','paid','failed','approved','rejected') NOT NULL DEFAULT 'pending',
  provider_payload LONGTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_trxid (bkash_trxid),
  INDEX idx_created_at (created_at)
);

-- Retention policy: keep transaction history for 90 days.
-- Recommended cron job (daily):
-- DELETE FROM orders WHERE created_at < (NOW() - INTERVAL 90 DAY);

CREATE EVENT IF NOT EXISTS purge_orders_older_than_90_days
ON SCHEDULE EVERY 1 DAY
DO DELETE FROM orders WHERE created_at < (NOW() - INTERVAL 90 DAY);