# Dollar Topup Card

PHP starter for a bKash top-up request workflow.

User submits Dollar amount (USD), phone number and bKash TrxID. The server calculates the BDT total from the admin-configured USD price.

Webhook endpoint:
`https://pay.resellnom.com/dollar/api/webhook`

## 90-Day Transaction History

- All dollar transaction records are stored in the database for the latest **90 days**.
- Telegram bot history should only query records where `created_at >= NOW() - INTERVAL 90 DAY`.
- Records older than 90 days are automatically removed by the included MySQL daily event.
- If MySQL events are disabled on the hosting, use a daily cron job to run:
  `DELETE FROM orders WHERE created_at < (NOW() - INTERVAL 90 DAY);`

## Deploy
1. Import `database/schema.sql` into MySQL.
2. Configure environment variables from `.env.example`.
3. Upload the project under the `/dollar/` web root.
4. Point the payment provider webhook to `/dollar/api/webhook` and configure its documented signature/secret.
5. Ensure the MySQL Event Scheduler is enabled, or configure the daily cleanup cron job.

Never hard-code Telegram bot tokens, database passwords or webhook secrets.