# Jengo Hardware

A web-based point-of-sale and e-commerce platform for **Jengo Hardware Store**, built to manage product inventory, customer orders, and payments — both in-store and online. The system supports M-Pesa (STK Push) and Paystack payment integrations, with role-based access for customers, attendants, and administrators.

---

## Table of Contents

- [Project Overview](#project-overview)
- [Tech Stack](#tech-stack)
- [Directory Structure](#directory-structure)
- [Role Definitions](#role-definitions)
- [Local Setup Guide](#local-setup-guide)
- [Environment Variables](#environment-variables)
- [Payment Integrations](#payment-integrations)
- [License](#license)

---

## Project Overview

Jengo Hardware is a full-stack PHP application that serves three types of users:

- **Customers** can browse products, add items to a cart, and pay online via M-Pesa or Paystack.
- **Attendants** manage in-store sales, view active orders, and confirm payments at the counter.
- **Admins** have full control over products, orders, users, and payment records.

The application is designed to run on a standard LAMP/XAMPP stack and uses **ngrok** for exposing local payment callback URLs during development.

---

## Tech Stack

| Layer        | Technology                          |
|--------------|--------------------------------------|
| Backend      | PHP 8.x (procedural + PDO)          |
| Database     | MySQL 8.x                           |
| Frontend     | Tailwind CSS (CDN), Vanilla JS      |
| Payments     | M-Pesa Daraja API, Paystack API     |
| Local Server | XAMPP (Apache + MySQL)              |
| Tunneling    | ngrok (for payment callbacks)       |

---

## Directory Structure

```
jengo_hardware/
├── .env                          # Environment variables (API keys, DB credentials)
├── README.md                     # Project documentation
│
├── database/
│   └── schema.sql                # Full database schema (tables, relationships)
│
├── config/
│   └── database.php              # PDO database connection
│
├── includes/
│   ├── header.php                # Shared page header / navigation
│   ├── footer.php                # Shared page footer
│   ├── mpesa_b2c.php             # Daraja B2C salary transfer client
│   └── auth.php                  # Session checks and role-based access control
│
├── assets/
│   ├── css/
│   │   └── style.css             # Custom styles (supplements Tailwind)
│   └── js/
│       └── main.js               # Frontend JavaScript utilities
│
├── payments/
│   ├── mpesa_push.php            # Initiates M-Pesa STK Push
│   ├── mpesa_callback.php        # Receives M-Pesa payment callback
│   ├── paystack_init.php         # Initialises a Paystack transaction
│   ├── paystack_verify.php       # Verifies Paystack payment after redirect
│   └── paystack_webhook.php      # Receives Paystack webhook events
│
├── api/
│   ├── mpesa_b2c_result.php      # Receives B2C salary transfer results
│   └── mpesa_b2c_timeout.php     # Receives B2C salary transfer timeouts
│
├── admin/
│   ├── index.php                 # Admin dashboard (sales summary, stats)
│   ├── products.php              # Add / edit / delete products
│   ├── orders.php                # View and manage all orders
│   ├── admin_gallery.php         # Admin gallery upload and management
│   ├── users.php                 # Manage customers and attendants
│   └── payments_record.php       # Full payment transaction history
│
├── gallery.php                   # Public photo gallery
│
├── attendant/
│   ├── index.php                 # Attendant POS dashboard
│   └── gallery.php               # Attendant gallery manager
│
├── customer/
│   └── dashboard.php             # Customer order history and account
│
├── index.php                     # Landing / home page
├── shop.php                      # Product listing page
├── cart.php                      # Shopping cart
├── checkout.php                  # Checkout and payment selection
├── register.php                  # New user registration
└── login.php                     # User login
```

---

## Role Definitions

### Customer
- Self-registers via `register.php`.
- Browses products on `shop.php` and adds them to `cart.php`.
- Completes purchases through `checkout.php` using M-Pesa or Paystack.
- Views order history and account details on `customer/dashboard.php`.

### Attendant
- Created by an Admin — cannot self-register.
- Accesses the POS interface at `attendant/index.php`.
- Processes walk-in sales, views pending orders, and confirms cash or mobile payments.
- Has no access to admin-only pages (product management, user management).

### Admin
- Full access to all pages including the `admin/` directory.
- Manages the product catalogue (`admin/products.php`).
- Views and updates all orders (`admin/orders.php`).
- Creates and deactivates user accounts (`admin/users.php`).
- Reviews all payment transactions (`admin/payments_record.php`).
- Accesses the summary dashboard at `admin/index.php`.

### Owner
- The intended user account is granted the `owner` role by its confirmed email
  address after the `database/system_owner.sql` migration is applied.
- Signs in through the regular `login.php` page and opens `admin/owner_dashboard.php`.
- Views read-only sales, pending-order, and inventory metrics, and can enable or
  disable maintenance mode. The owner role is not assignable from the regular
  user-management form.

---

## Local Setup Guide

### Prerequisites

- [XAMPP](https://www.apachefriends.org/) (PHP 8.x + MySQL 8.x)
- [Composer](https://getcomposer.org/) *(if dependencies are added later)*
- [ngrok](https://ngrok.com/) account and CLI installed

---

### Step 1 — Clone the Repository

```bash
git clone https://github.com/your-username/jengo_hardware.git
```

Place the project inside your XAMPP web root:

```
C:\xampp\htdocs\GitHub\jengo_hardware\
```

---

### Step 2 — Import the Database

1. Start **XAMPP** and ensure Apache and MySQL are running.
2. Open **phpMyAdmin** at `http://localhost/phpmyadmin`.
3. Create a new database named `jengo_hardware`.
4. Click **Import**, select `database/schema.sql`, and run it.

Alternatively, via MySQL CLI:

```bash
mysql -u root -p jengo_hardware < database/schema.sql
```

For an existing installation, import `database/product_reviews.sql` into the
`jengo_hardware` database to add the product reviews table.
If that table already exists from an earlier version, instead run
`database/product_reviews_photo.sql` once to add the nullable photo column.
Only signed-in customers with a completed order or delivered item can submit a
review.

For an existing installation, apply `database/system_owner.sql` to add the
owner role and maintenance-mode setting. The migration assigns owner only to
`david@jengohardware.co.ke` if that account exists; rerun it after creating the
account if it does not yet exist. If `backend/` uses a separate database, apply
`backend/database/system_owner.sql` there as well. Maintenance mode blocks
normal storefront and staff pages with HTTP 503; sign-in/out, the owner role,
application APIs, and payment callbacks remain available.

#### Attendant POS Payments

For an existing database, import `database/attendant_pos.sql` to ensure the POS
tables and `sales.cancelled_reason` column exist. Configure the Daraja sandbox
credentials and a publicly reachable HTTPS `MPESA_CALLBACK_URL` in `.env` before
using M-Pesa at the register. POS M-Pesa sales reserve inventory while pending;
cashier cancellation, customer abort, gateway failure, or the 60-second timeout
cancels the sale and releases the reserved stock. A successful Daraja callback
marks the sale completed. Cash and card sales continue to complete immediately.

#### Separate Admin Portal

Admin portal credentials are stored separately from customer and attendant accounts.
For an existing installation, select the `jengo_hardware` database in phpMyAdmin and
import `database/admin_auth.sql` to create the `admin_users` table. Create an admin
record with a password hash generated locally using PHP's `password_hash()`; never
store the plain-text password in the database. Then sign in at
`http://localhost/jengo_hardware/admin/login.php`. Admin access does not use the
regular `/login.php` flow.

#### Employees and Deliveries

The admin portal includes an employee roster at `/admin/employees.php` and a
delivery board at `/admin/deliveries.php`. Employee age is calculated from date
of birth; monthly salaries are stored in KES. Hiring, termination, and re-hiring
update the employee record and add an employment-history event. Marking a delivery
as delivered moves it into the delivery records view.

For an existing database, import `database/operations_management.sql` after
`database/admin_auth.sql`. Fresh installations receive these tables from
`database/schema.sql`. If the employees table already exists, apply
`database/link_employee_users.sql` and `database/employees_optional_profile.sql`
once to add the employee-to-login link and allow unknown personal details.
For an existing installation, also apply `database/approval_requests.sql` to
enable attendant requests and the admin approval panel. Approval records the
decision; the attendant must then proceed with the requested action.
When adding an employee with an email, an existing user account with that email
is linked; otherwise a login account is created. Newly created accounts use
the default password `password123`; change it after the first sign-in.
Creating an attendant or admin through `/admin/add_user.php` also creates an
employed roster entry; date of birth and sex remain unset until provided on the
employee edit form.

---

### Step 3 — Configure the `.env` File

Copy the example below into your `.env` file and fill in your values:

```env
# Database
DB_HOST=localhost
DB_NAME=jengo_hardware
DB_USER=root
DB_PASS=

# M-Pesa Daraja API
MPESA_CONSUMER_KEY=your_consumer_key
MPESA_CONSUMER_SECRET=your_consumer_secret
MPESA_SHORTCODE=174379
MPESA_PASSKEY=your_lipa_na_mpesa_passkey
MPESA_CALLBACK_URL=https://your-ngrok-url.ngrok-free.app/payments/mpesa_callback.php
MPESA_ENV=sandbox
MPESA_B2C_INITIATOR_NAME=your_b2c_initiator_name
MPESA_B2C_SECURITY_CREDENTIAL=your_encrypted_initiator_password
MPESA_B2C_RESULT_URL=https://your-ngrok-url.ngrok-free.app/api/mpesa_b2c_result.php
MPESA_B2C_TIMEOUT_URL=https://your-ngrok-url.ngrok-free.app/api/mpesa_b2c_timeout.php
MPESA_B2C_CALLBACK_TOKEN=generate_a_random_secret_at_least_32_characters_long

# Paystack
PAYSTACK_SECRET_KEY=sk_test_your_secret_key
PAYSTACK_PUBLIC_KEY=pk_test_your_public_key
PAYSTACK_CALLBACK_URL=https://your-ngrok-url.ngrok-free.app/payments/paystack_verify.php
PAYSTACK_WEBHOOK_URL=https://your-ngrok-url.ngrok-free.app/payments/paystack_webhook.php

# App
APP_URL=http://localhost/GitHub/jengo_hardware
APP_ENV=development
```

> **Never commit `.env` to version control.** It is already listed in `.gitignore`.

---

### Step 4 — Start ngrok for Payment Callbacks

Payment gateways (M-Pesa, Paystack) require a publicly accessible HTTPS URL to send callbacks. Use ngrok to tunnel your local server during development.

1. Start your XAMPP Apache server.
2. In a terminal, run:

```bash
ngrok http 80
```

3. Copy the generated HTTPS URL (e.g. `https://a1b2-102-0-0-1.ngrok-free.app`).
4. Update `MPESA_CALLBACK_URL`, `PAYSTACK_CALLBACK_URL`, and `PAYSTACK_WEBHOOK_URL` in your `.env` with this URL.

> Restart ngrok generates a **new URL** each time (on the free plan). Update `.env` on every restart.

---

### Step 5 — Open the App

Visit the app in your browser:

```
http://localhost/GitHub/jengo_hardware/
```

---

## Payment Integrations

### M-Pesa (Safaricom Daraja)
- Uses the **Lipa Na M-Pesa Online (STK Push)** API.
- Sandbox credentials available at [developer.safaricom.co.ke](https://developer.safaricom.co.ke).
- Customer checkout uses `mpesa_push.php`; attendant POS payments use
  `attendant/api_start_pos_payment.php`.
- `payments/mpesa_callback.php` listens for checkout and POS confirmations.
- Admin payroll uses Daraja B2C with the B2C initiator name and encrypted
  security credential in `.env`. Configure both public HTTPS callback URLs and
  a random callback token; salary transfers remain processing until the result
  callback confirms the receipt. Keep `MPESA_ENV=sandbox` until production
  credentials and shortcode are approved by Safaricom.
- Apply `database/payroll_records.sql` for a new installation. For an existing
  payroll table, apply `database/payroll_mpesa.sql` once to add employee M-Pesa
  numbers and Daraja callback fields; old non-Daraja payroll entries are kept
  as `Legacy Record`. Staff M-Pesa numbers are maintained from Employees and
  must use `2547XXXXXXXX`. The dashboard only permits whole-KES salaries and
  one pending or confirmed salary transfer per employee each calendar month.
- POS payments can be cancelled from the register; unanswered prompts time out
  after 60 seconds and release the inventory reservation.

### Paystack
- Uses the **Paystack Transactions API**.
- Test keys available at [dashboard.paystack.com](https://dashboard.paystack.com).
- `paystack_init.php` starts the transaction; `paystack_verify.php` confirms it after redirect; `paystack_webhook.php` handles server-to-server events.

### Photo Gallery
- Apply `database/gallery.sql` to create the album and photo tables and migrate
  existing flat-gallery photos into an `Imported Gallery` album. The migration
  is safe to rerun. Fresh installations also receive the gallery tables from
  `database/schema.sql`.
- Admins and attendants can create albums and manage their photos from
  `admin/admin_gallery.php` and `attendant/gallery.php`. Album covers and photos
  can be uploaded as JPEG, PNG, GIF or WebP images (up to 5 MB) or supplied as
  HTTP/HTTPS image URLs. Captions are optional. Local uploads are stored under
  `uploads/gallery/`; the public `gallery.php` page lists albums and shows the
  photos for the selected album. Deleting an album also deletes its photo
  records and managed local image files.

---

## License

This project is licensed under the [MIT License](LICENSE).
