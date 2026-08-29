# Dollar Topup Card

PHP starter for a bKash top-up request workflow.

User submits Dollar amount (USD), phone number and bKash TrxID. The server calculates the BDT total from the admin-configured USD price.

Webhook endpoint:
`https://pay.resellnom.com/dollar/api/webhook`

## Deploy
1. Import `database/schema.sql` into MySQL.
2. Configure environment variables from `.env.example`.
3. Upload the project under the `/dollar/` web root.
4. Point the payment provider webhook to `/dollar/api/webhook` and configure its documented signature/secret.

Never hard-code Telegram bot tokens, database passwords or webhook secrets.