# Jengo Hardware — Go-Live Production Checklist

Work through every item top to bottom before launching.  
Tick each box as you complete it. Don't skip any step. 🚀

---

## Table of Contents

1. [Before You Start](#1-before-you-start)
2. [Database Migration](#2-database-migration)
3. [Server & File Setup](#3-server--file-setup)
4. [Environment Variables (.env)](#4-environment-variables-env)
5. [Switch API Keys to Live](#5-switch-api-keys-to-live)
6. [Enforce HTTPS](#6-enforce-https)
7. [Security Hardening](#7-security-hardening)
8. [Final Tests](#8-final-tests)
9. [After Launch](#9-after-launch)

---

## 1. Before You Start

These are the things you need ready before touching the server.

- [ ] You have access to your live hosting control panel (cPanel, Plesk, etc.)
- [ ] You have FTP/SFTP credentials or Git access to the server
- [ ] Your domain name is pointed to the server's IP address (DNS propagated)
- [ ] You have a Safaricom Daraja **production** app approved
- [ ] You have a Paystack account with **live** keys activated
- [ ] You have run the full app locally without errors on the latest code

---

## 2. Database Migration

### 2a. Export from your local XAMPP

Open **phpMyAdmin** on your local machine (`http://localhost/phpmyadmin`):

1. Select the `jengo_hardware` database on the left
2. Click the **Export** tab
3. Choose **Custom** export method
4. Under **Format** select `SQL`
5. Check **Add DROP TABLE** (clears old tables on import)
6. Check **Add CREATE DATABASE** if the live DB doesn't exist yet
7. Click **Go** — save the `.sql` file

Or use the MySQL command line:

```bash
mysqldump -u root -p jengo_hardware > jengo_hardware_export.sql
```

---

### 2b. Create the live database

In your hosting control panel (cPanel example):

1. Go to **MySQL Databases**
2. Create a new database — e.g. `yourusername_jengo`
3. Create a database user with a **strong password**
4. Add the user to the database with **All Privileges**
5. Note down: database name, username, and password — you'll need them for `.env`

---

### 2c. Import to the live database

In the hosting **phpMyAdmin**:

1. Select your new live database
2. Click the **Import** tab
3. Choose your exported `.sql` file
4. Click **Go**

Or via SSH:

```bash
mysql -u db_username -p db_name < jengo_hardware_export.sql
```

---

### 2d. Verify the import

- [ ] All tables exist: `users`, `products`, `categories`, `orders`, `order_items`, `payments`
- [ ] The seed admin account is present in the `users` table
- [ ] At least the 6 sample categories are present in `categories`
- [ ] Row counts look correct compared to your local database

> **Change the default admin password immediately after going live.**  
> Default credentials (`admin@jengohardware.co.ke` / `Admin@1234`) are public in the README.

---

## 3. Server & File Setup

### 3a. Upload project files

Upload everything **except** the following — they should never go on the server via FTP:

```
❌ Do NOT upload:
   .env                  ← contains secrets
   .git/                 ← version control folder
   logs/                 ← will be created by the app
```

Upload method options:
- **Git** (recommended): `git pull` on the server via SSH
- **FTP/SFTP**: Use FileZilla or your hosting file manager
- **cPanel File Manager**: Zip the project, upload, extract

---

### 3b. Set correct folder permissions

Connect via SSH or use your hosting file manager:

```bash
# Web files — readable by server, not writable
find /path/to/jengo_hardware -type f -exec chmod 644 {} \;
find /path/to/jengo_hardware -type d -exec chmod 755 {} \;

# Logs folder — must be writable by PHP
mkdir -p /path/to/jengo_hardware/logs
chmod 775 /path/to/jengo_hardware/logs

# Product images upload folder — must be writable by PHP
mkdir -p /path/to/jengo_hardware/assets/img/products
chmod 775 /path/to/jengo_hardware/assets/img/products
```

---

### 3c. Set the document root

Point your domain's document root to the `jengo_hardware/` folder.  
In cPanel → **Domains** → set the document root path.

---

## 4. Environment Variables (.env)

Create a fresh `.env` file **directly on the server** — do not upload your local one.

Connect via SSH or use cPanel file manager, then create `/path/to/jengo_hardware/.env`:

```env
# ─── APPLICATION ─────────────────────────────────────────────────────────────
APP_NAME="Jengo Hardware"
APP_ENV=production           # ← CHANGE from 'development'
APP_URL=https://www.jengohardware.co.ke   # ← your real domain with https://
APP_DEBUG=false              # ← CHANGE to false — never expose errors in prod

# ─── DATABASE ────────────────────────────────────────────────────────────────
DB_HOST=localhost
DB_PORT=3306
DB_NAME=yourusername_jengo   # ← your live DB name from cPanel
DB_USER=yourusername_dbuser  # ← your live DB username
DB_PASS=YourStr0ngDBPassword # ← your live DB password
DB_CHARSET=utf8mb4

# ─── M-PESA DARAJA (PRODUCTION) ──────────────────────────────────────────────
MPESA_ENV=production                      # ← CHANGE from 'sandbox'
MPESA_CONSUMER_KEY=your_live_consumer_key
MPESA_CONSUMER_SECRET=your_live_consumer_secret
MPESA_SHORTCODE=your_live_paybill_or_till
MPESA_PASSKEY=your_live_passkey
MPESA_CALLBACK_URL=https://www.jengohardware.co.ke/payments/mpesa_callback.php
MPESA_SANDBOX_URL=https://sandbox.safaricom.co.ke
MPESA_PRODUCTION_URL=https://api.safaricom.co.ke

# ─── PAYSTACK (LIVE) ─────────────────────────────────────────────────────────
PAYSTACK_ENV=live                         # ← CHANGE from 'test'
PAYSTACK_PUBLIC_KEY=pk_live_xxxxxxxxxxxx  # ← CHANGE from pk_test_
PAYSTACK_SECRET_KEY=sk_live_xxxxxxxxxxxx  # ← CHANGE from sk_test_
PAYSTACK_CALLBACK_URL=https://www.jengohardware.co.ke/payments/paystack_verify.php
PAYSTACK_WEBHOOK_URL=https://www.jengohardware.co.ke/payments/paystack_webhook.php
PAYSTACK_BASE_URL=https://api.paystack.co

# ─── SESSION ─────────────────────────────────────────────────────────────────
SESSION_LIFETIME=3600
```

### Critical `.env` changes checklist

- [ ] `APP_ENV` changed from `development` → `production`
- [ ] `APP_DEBUG` changed from `true` → `false`
- [ ] `APP_URL` uses your real domain with `https://`
- [ ] All `DB_*` values point to the live database
- [ ] `MPESA_ENV` changed from `sandbox` → `production`
- [ ] `MPESA_CALLBACK_URL` uses your real domain
- [ ] All `PAYSTACK_*` keys changed from `test` to `live` values
- [ ] All Paystack URLs use your real domain

---

## 5. Switch API Keys to Live

### 5a. M-Pesa Daraja — Go Live

1. Log in to [developer.safaricom.co.ke](https://developer.safaricom.co.ke)
2. Go to **My Apps** → select your app
3. Apply for **Go Live** — Safaricom reviews your app before approving
4. Once approved, copy the **Live** Consumer Key and Consumer Secret
5. Update `.env`:
   ```env
   MPESA_ENV=production
   MPESA_CONSUMER_KEY=your_live_key
   MPESA_CONSUMER_SECRET=your_live_secret
   MPESA_SHORTCODE=your_registered_paybill_or_till
   MPESA_PASSKEY=your_live_passkey
   ```
6. Register your callback URL with Safaricom in the portal:
   ```
   https://www.jengohardware.co.ke/payments/mpesa_callback.php
   ```

> ⚠️ Daraja Go Live requires Safaricom approval. Allow 3–5 business days.  
> In the meantime you can keep `MPESA_ENV=sandbox` for testing.

---

### 5b. Paystack — Switch to Live Keys

1. Log in to [dashboard.paystack.com](https://dashboard.paystack.com)
2. Click the toggle at the top: **Test Mode → Live Mode**
3. Go to **Settings → API Keys & Webhooks**
4. Copy the **Live** Public Key and Secret Key
5. Update `.env`:
   ```env
   PAYSTACK_ENV=live
   PAYSTACK_PUBLIC_KEY=pk_live_xxxxxxxxxxxx
   PAYSTACK_SECRET_KEY=sk_live_xxxxxxxxxxxx
   ```
6. Register your webhook URL in the Paystack dashboard:
   ```
   https://www.jengohardware.co.ke/payments/paystack_webhook.php
   ```
7. Under webhook events, enable at minimum:
   - `charge.success`
   - `charge.failed`
   - `refund.processed`

- [ ] Paystack dashboard switched to **Live Mode**
- [ ] Live Public Key copied to `.env`
- [ ] Live Secret Key copied to `.env`
- [ ] Webhook URL registered in Paystack dashboard
- [ ] Remove ngrok URL from Paystack webhook settings

---

## 6. Enforce HTTPS

All payment callbacks must use HTTPS. Here's how to enforce it.

### 6a. Install an SSL certificate

Most modern hosts provide a **free Let's Encrypt certificate**:

- In cPanel → **SSL/TLS** → **Let's Encrypt SSL** → Issue certificate for your domain
- Or use **AutoSSL** if your host supports it (runs automatically)

---

### 6b. Force HTTPS via .htaccess

Create or update the `.htaccess` file in your project root:

```apache
# Force all traffic to HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Prevent directory listing
Options -Indexes

# Block access to sensitive files
<FilesMatch "^\.env$|^\.git|^logs/">
    Order allow,deny
    Deny from all
</FilesMatch>

# PHP error display — off in production
php_flag display_errors off
php_flag log_errors on
```

---

### 6c. Verify HTTPS is working

- [ ] Visit `http://www.jengohardware.co.ke` — it should redirect to `https://`
- [ ] Browser shows a padlock 🔒 with no warnings
- [ ] Visit [SSL Labs](https://www.ssllabs.com/ssltest/) and run a test — aim for grade A
- [ ] `APP_URL` in `.env` starts with `https://`, not `http://`

---

### 6d. HSTS header (optional but recommended)

Add to `.htaccess` to tell browsers to always use HTTPS:

```apache
# HTTP Strict Transport Security — 1 year
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

---

## 7. Security Hardening

### 7a. Protect sensitive files

- [ ] `.env` is **not** accessible from the browser — test by visiting `https://yourdomain.com/.env` (should return 403 or 404)
- [ ] `logs/` folder is **not** accessible from the browser
- [ ] `database/schema.sql` is **not** accessible from the browser
- [ ] `.htaccess` blocks are in place (see Section 6b)

---

### 7b. PHP configuration

Add to `.htaccess` or `php.ini` on the server:

```apache
# Hide PHP version from response headers
php_flag expose_php off

# Turn off error display (errors go to log file only)
php_flag display_errors off
php_value error_log /path/to/jengo_hardware/logs/php_errors.log
```

---

### 7c. Admin account

- [ ] Default admin password (`Admin@1234`) has been changed
- [ ] Admin email changed from `admin@jengohardware.co.ke` to your real email
- [ ] Admin account uses a strong, unique password (16+ characters)

---

### 7d. Database

- [ ] Database user has only the permissions it needs (no SUPER or FILE)
- [ ] Database password is strong and not reused elsewhere
- [ ] Remote database access is disabled (localhost only)

---

## 8. Final Tests

Run these tests on the **live site** before announcing the launch.

### 8a. Core pages

- [ ] Home page loads correctly at `https://yourdomain.com`
- [ ] Shop page displays products from the live database
- [ ] Register page creates a new customer account
- [ ] Login page logs in and redirects to the customer dashboard
- [ ] Logout clears the session

### 8b. Cart & Checkout

- [ ] Products can be added to the cart
- [ ] Cart page shows correct quantities and totals
- [ ] Checkout page loads with fulfillment and payment options
- [ ] Placing an order saves it to the `orders` table with status `pending`

### 8c. M-Pesa Payment

- [ ] STK Push sends a prompt to a real phone (small test amount: KES 1)
- [ ] Entering the PIN confirms the payment
- [ ] `payments` table row changes to `status = 'success'`
- [ ] `orders` table row changes to `status = 'paid'`
- [ ] Customer dashboard shows the order as Paid

### 8d. Paystack Payment

- [ ] Paystack payment page loads with live keys
- [ ] A real card/M-Pesa payment completes successfully
- [ ] Redirect returns to `paystack_verify.php` and updates the DB
- [ ] Webhook fires and is logged in `logs/paystack_webhook_*.log`

### 8e. Admin panel

- [ ] Admin dashboard loads at `/admin/index.php`
- [ ] Products can be added, edited, and hidden
- [ ] Order status can be updated manually
- [ ] Users page shows all registered accounts
- [ ] Payments Record page shows all transactions

### 8f. Attendant dashboard

- [ ] Attendant can log in and see the POS dashboard
- [ ] Paid orders appear in the "Ready to Pack" list
- [ ] Marking an order as Packed updates the status to `completed`

---

## 9. After Launch

Things to do in the first week after going live.

- [ ] Monitor `logs/mpesa_callback_*.log` for any callback errors
- [ ] Monitor `logs/paystack_webhook_*.log` for any webhook failures
- [ ] Check `logs/php_errors.log` daily for unexpected errors
- [ ] Set up automated database backups (weekly minimum) in cPanel
- [ ] Register your domain for email alerts in Paystack and Daraja dashboards
- [ ] Test the "Forgot Password" flow if implemented
- [ ] Remove any test/seed orders from the database

---

## Quick Reference — Key Differences: Dev vs Production

| Setting | Development | Production |
|---------|-------------|------------|
| `APP_ENV` | `development` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `APP_URL` | `http://localhost/...` | `https://yourdomain.com` |
| `MPESA_ENV` | `sandbox` | `production` |
| M-Pesa shortcode | `174379` (sandbox) | Your registered shortcode |
| Paystack keys | `pk_test_` / `sk_test_` | `pk_live_` / `sk_live_` |
| Callback URLs | ngrok HTTPS URL | Your real HTTPS domain |
| SSL certificate | Not required | Required (payment gateways demand it) |
| Error display | On | Off |
| `.env` location | Local file | Created directly on server |

---

*Jengo Hardware — Kenya's Trusted Hardware Store 🔨*  
*Good luck with the launch!*
