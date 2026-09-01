# Dollar TopUp Telegram Bot

Telegram-based USDT purchase and payout management system.

> **User orders are handled through Telegram Bot.** The main operational controls are also available to the authorized Telegram Admin.

## Features

### User Side
- 🌐 Bangla / English language selection
- 💵 Buy USDT order flow
- 🌐 USDT (BEP20) destination address submission
- ⏱️ Payment deadline / countdown flow
- 📱 Payment method buttons:
  - bKash
  - bKash Auto (shown only when enabled)
  - Nagad
  - Bank
- 📋 Copy-friendly payment information
- 👤 User profile
- 📊 Total transaction volume
- 🕒 Recent transaction history
- 🆘 Support Team access
- 🔔 Telegram order status notifications

### Admin Side
- 🔔 New order notification
- 📋 Order approval and rejection
- 💸 Withdrawal queue management
- 👤 Manual release from Telegram buttons
- ⏸️ Hold queued withdrawals
- 🔄 Re-queue held withdrawals
- 🤖 Automatic Binance withdrawal mode
- 👨‍💼 Manual withdrawal mode
- 🔍 Submitted withdrawal status synchronization
- 💳 Payment instruction management
- 🤖 bKash Auto button ON/OFF control

---

# Installation

## 1. Clone / update

```bash
cd /home//public_html/dollar
git pull origin main
```

## 2. Configure environment

Copy the example file if required:

```bash
cp .env.example .env
```

Edit `.env` and configure:

```env
DB_DSN=mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4
DB_USER=YOUR_DATABASE_USER
DB_PASS=YOUR_DATABASE_PASSWORD

TELEGRAM_BOT_TOKEN=YOUR_BOT_TOKEN
ADMIN_TELEGRAM_IDS=YOUR_NUMERIC_TELEGRAM_ID

WEBHOOK_SECRET=CHANGE_TO_A_LONG_RANDOM_SECRET

BINANCE_AUTO_WITHDRAW=false
BINANCE_API_KEY=
BINANCE_API_SECRET=
BINANCE_USDT_NETWORK=BSC
BINANCE_BASE_URL=https://api.binance.com
BINANCE_WALLET_TYPE=0
BINANCE_RECV_WINDOW=5000

ADMIN_WEB_USER=
ADMIN_WEB_PASS=
```

> Never commit the real `.env` file, API keys, bot tokens, database passwords, or secrets.

## 3. Run migration

```bash
php migrate.php
```

## 4. Syntax check

```bash
php -l bot/telegram.php
php -l lib/withdrawals.php
php -l process_withdrawals.php
```

---

# Telegram Webhook

The Telegram webhook must point to the correct public endpoint.

Check it:

```bash
TOKEN=$(grep '^TELEGRAM_BOT_TOKEN=' .env | cut -d '=' -f2- | tr -d '"')
curl -s "https://api.telegram.org/bot${TOKEN}/getWebhookInfo"
```

If the webhook URL is wrong, register the correct HTTPS endpoint for your installation.

---

# User Flow

```text
/start
   ↓
Language Selection
   ↓
Buy Dollar
   ↓
Enter USD Amount
   ↓
Select Payment Method
   ↓
bKash / bKash Auto / Nagad / Bank
   ↓
Payment Instructions
   ↓
Phone Number
   ↓
Transaction ID
   ↓
USDT (BEP20) Address
   ↓
Order Submitted
   ↓
Admin Approval
   ↓
Withdrawal Queue
   ↓
Manual or Automatic Processing
```

---

# Admin Telegram Commands

Only IDs configured in `ADMIN_TELEGRAM_IDS` can use admin commands.

## Orders

### View pending orders

```text
/orders
```

### Approve an order

```text
/approve ORDER_ID
```

Example:

```text
/approve DTC-20260901-12345678
```

Approval creates or enables the withdrawal processing flow.

### Reject an order

```text
/reject ORDER_ID
```

---

# Withdrawal Queue

## Show queue

```text
/queue
```

The queue shows active withdrawal items and their status.

### Status flow

```text
queued
  ↓
processing
  ↓
submitted
  ↓
sent
```

Other possible status:

```text
hold
```

---

## Manual Release

The easiest method is:

1. Send:

```text
/queue
```

2. Press:

```text
💸 Manual Release
```

3. Send USDT manually.

4. Paste the real transaction hash when the bot requests it.

The system marks the withdrawal:

```text
SENT
```

The user receives a notification.

### Manual command alternative

```text
/sent ORDER_ID TX_HASH
```

Example:

```text
/sent DTC-20260901-12345678 0xYOUR_TRANSACTION_HASH
```

Use a real transaction hash/reference only.

---

## Hold

From `/queue`, press:

```text
⏸ Hold
```

The withdrawal changes to:

```text
hold
```

---

## Re-queue

For held orders, press:

```text
🔄 Re-queue
```

The withdrawal returns to:

```text
queued
```

---

# Automatic / Manual Withdrawal Mode

## Automatic mode

```text
/mode auto
```

Queued withdrawals can be processed by the configured Binance API worker.

## Manual mode

```text
/mode manual
```

Automatic processing is disabled and the admin manually releases withdrawals.

## Legacy auto controls

```text
/auto on
/auto off
/auto status
```

## Process queue

```text
/process
```

Or:

```text
/process 5
```

---

# Payment Method Management

## Set bKash instructions

```text
/setbkash bKash Number: 017XXXXXXXX
```

## Set Nagad instructions

```text
/setnagad Nagad Number: 018XXXXXXXX
```

## Set Bank instructions

For multiple values, send separate lines:

```text
/setbank Bank Name: Example Bank
Account Name: Example Name
Account Number: 123456789
Branch: Example Branch
```

The bot formats colon-separated values in separate copy-friendly boxes.

---

# bKash Auto Button

bKash Auto is prepared for future API integration.

## Enable the button

```text
/bkashauto on
```

## Disable the button

```text
/bkashauto off
```

## Check status

```text
/bkashauto status
```

## Set instructions

```text
/setbkashauto Payment Number: 017XXXXXXXX
```

> Enabling the button alone does **not** activate live bKash API payment verification. Live merchant/API credentials and secure server-side integration are required.

---

# Cron Job

For automatic queue processing, configure a cPanel cron job:

```cron
* * * * * cd /home//public_html/dollar && /usr/bin/php process_withdrawals.php >/dev/null 2>&1
```

Do not paste `crontab -e` commands into the cPanel cron command field.

Manual mode does not require automatic withdrawal processing.

---

# Testing

## Check environment loading

```bash
php -r 'require "config/config.php"; echo "DSN=".getenv("DB_DSN").PHP_EOL; echo "USER=".getenv("DB_USER").PHP_EOL; echo "PASS LENGTH=".strlen((string)getenv("DB_PASS")).PHP_EOL;'
```

## Run worker manually

```bash
php process_withdrawals.php
```

## Check queue

Use the Admin Telegram Bot:

```text
/queue
```

---

# Database Cleanup for Demo/Test Data

Before deleting data, take a database backup.

The application data model may evolve. Prefer clearing only confirmed test orders and their dependent records rather than blindly deleting every table.

Run migrations again after maintenance:

```bash
php migrate.php
```

---

# Security

- Keep `.env` outside Git.
- Never share database passwords, Telegram bot tokens, Binance API secrets, or webhook secrets.
- Restrict Binance API permissions to only what is required.
- Use withdrawal address controls available in your exchange account.
- Verify transaction hashes before marking manual withdrawals as sent.
- Admin access is restricted by Telegram numeric ID.
- Use HTTPS for Telegram webhooks.
- Review server and webhook logs regularly.

---

# Update Deployment

```bash
cd /home//public_html/dollar
git pull origin main
php migrate.php
php -l bot/telegram.php
php -l lib/withdrawals.php
php -l process_withdrawals.php
```

Then test:

```text
/start
```

and from the authorized admin account:

```text
/queue
```

---

## Support

For deployment-specific configuration, keep credentials and secrets only on the server environment.
