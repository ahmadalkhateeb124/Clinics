# Nour's Touch — Clinic Management System

Production-ready web app for a multi-service therapy clinic in Jordan:
**Physical Therapy · Lymphatic Drainage · Massage · Cupping/Hijama ·
Body Scrubs · Paid Consultations.**

Built with **vanilla PHP 8 · MySQL 8 · Bootstrap 5**, RTL-first (Arabic) with English
toggle, JOD currency, no framework lock-in.

---

## Table of contents

- [Features](#-features-by-phase)
- [Quick start](#-quick-start)
- [Folder structure](#-folder-structure)
- [Default credentials](#-default-credentials)
- [Roles & permissions](#-roles--permissions)
- [Cron schedule](#-cron-schedule)
- [Security](#-security)
- [Tech stack](#-tech-stack)

---

## ✅ Features by phase

| Phase | Module                                | Status |
|------:|---------------------------------------|--------|
|  1 | Auth · Roles · Permissions · Settings · Activity log | ✅ |
|  2 | Patients · Services · Packages · Medical records · File uploads | ✅ |
|  3 | Appointments · Calendar (FullCalendar) · Drag-drop · **Hard-block rule** | ✅ |
|  4 | Consultations · Treatment plans · Convert → Package | ✅ |
|  5 | Invoices · Payments · Refunds · Expenses · Cash drawer · **PDF (DomPDF)** · P&L | ✅ |
|  6 | HR · Employees · Attendance · Leaves · Advances · **Payroll + Payslip PDF** | ✅ |
|  7 | Analytics dashboard · KPIs · Charts (Chart.js) · Heatmap · Reports · CSV export | ✅ |
|  8 | Public website · CMS · Online booking · SEO (sitemap + schema.org) | ✅ |
|  9 | Email reminders (24h + 2h) · Cron-friendly · Idempotent · Notifications log | ✅ |
| 10 | Master installer · Daily DB backup · System health page · Docs | ✅ |

---

## 🚀 Quick start

### Requirements

- PHP **8.0+** (8.1+ recommended)
- MySQL **8.0+** / MariaDB 10.4+
- Apache with `mod_rewrite` (XAMPP, MAMP, etc.)
- Composer

### One-shot install

```bash
# 1. Clone / unzip into your web root
cd /Applications/XAMPP/xamppfiles/htdocs/nourstouch    # or your path

# 2. Configure DB credentials
cp BusinessPortal/config/database.credentials.example.php BusinessPortal/config/database.credentials.php
cp inc/db.credentials.example.php inc/db.credentials.php
# edit both files with your MySQL host/user/pass/database

# 3. Create the empty database
mysql -u root -e "CREATE DATABASE nourstouch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Install Composer dependencies (DomPDF + PHPMailer)
cd BusinessPortal && composer install --no-dev && cd ..

# 5. Run the master installer (drops + recreates everything + seeds sample data)
php BusinessPortal/config/install.php

# 6. Make uploads/storage/backups writable
chmod -R 0775 uploads BusinessPortal/storage BusinessPortal/backups

# 7. Open in browser
#    Public site:  http://localhost/nourstouch/
#    Admin panel:  http://localhost/nourstouch/BusinessPortal/
```

### Installer flags

| Flag             | What it does |
|------------------|--------------|
| (none)           | Drop all tables, apply all schemas, run all seeders |
| `--schema-only`  | Only apply schemas, skip seed data |
| `--keep`         | Don't drop existing tables (apply schemas only if missing) |

---

## 📂 Folder structure

```
nourstouch/
├── index.php                  Public site router (smart slug → page/blog/cms)
├── sitemap.php                Auto-generated XML sitemap
├── .htaccess                  Friendly URLs + security headers
├── inc/                       Public site DB connection, language, helpers
│   ├── conn.php               $pdo + $base_url + site_setting() + e()
│   ├── lang_function.php      AR/EN switcher
│   ├── ar.php / en.php        Public translations
│   └── check_availability.php Live-availability JSON endpoint for booking
├── pages/                     Public pages (Home, About, Services, Booking, …)
├── parts/                     Public header / footer
├── assets/                    CSS, JS, images
├── uploads/                   Patient files, sliders, blog images, etc.
│
└── BusinessPortal/            ─── Admin panel ───
    ├── index.php              Auth gate
    ├── auth/                  login.php, logout.php, change-password.php
    ├── config/
    │   ├── config.php         Bootstrap (sessions, paths, autoload)
    │   ├── database.php       PDO singleton db()
    │   ├── schema*.sql        Phase 1-9 SQL schemas
    │   ├── seed*.php          Phase 1-8 seeders
    │   └── install.php        ONE-SHOT installer
    ├── includes/
    │   ├── auth.php           Login throttling, sessions
    │   ├── permissions.php    can() / hasRole() / require_can()
    │   ├── activity_log.php   log_activity()
    │   ├── csrf.php           Token generation + check
    │   ├── helpers.php        flash, redirect, e(), pagination, file upload
    │   ├── booking.php        Hard-block rule + conflict detection
    │   ├── finance.php        Invoice/receipt/refund numbering, recompute
    │   ├── hr.php             Commissions + payroll generation
    │   ├── analytics.php      KPIs + comparisons + charts data
    │   └── notifications.php  Email send via PHPMailer + log
    ├── partials/              Sidebar header + footer
    ├── admin/                 30+ CRUD/report pages
    ├── cron/
    │   ├── send-reminders.php Appointment email reminders
    │   └── backup-db.php      Daily DB dump (gzipped, 14-day rotation)
    ├── backups/               (gitignored, http-blocked)
    ├── storage/dompdf/        PDF font cache
    └── vendor/                Composer dependencies
```

---

## 🔐 Default credentials

After running the installer:

| Account     | Email              | Password         |
|-------------|--------------------|--------------------|
| Super Admin | `admin@clinic.com` | `Admin@123` (forced change) |
| Doctor      | `lina@clinic.com`  | `Therapist@123`    |
| Therapist   | `sami@clinic.com`  | `Therapist@123`    |
| Therapist   | `reem@clinic.com`  | `Therapist@123`    |

---

## 🛡 Roles & permissions

70+ granular permissions grouped by module:
`dashboard.view`, `patients.create`, `appointments.override_block`,
`invoices.refund`, `payroll.create`, `cms.publish`, etc.

| Role          | Coverage |
|---------------|----------|
| Super Admin   | Everything (bypasses checks) |
| Admin         | All except `users.delete` & `roles.delete` |
| Doctor        | Patients (view/edit) · Appointments · Consultations |
| Therapist     | Patients (view) · Appointments (view/edit) · Attendance |
| Receptionist  | Patients CRUD · Appointments CRUD · Invoices/Payments (view+create) |
| Accountant    | Invoices · Payments · Expenses · Payroll · Reports |
| Employee      | Self-service (dashboard, attendance, leave/advance request) |

Manage at **Admin → Roles** (Super Admin can create custom roles too).

---

## ⏰ Cron schedule

Add these lines to your server crontab (`crontab -e`):

```cron
# Send appointment reminder emails every 15 minutes (24h-ahead + 2h-ahead)
*/15 * * * * /usr/bin/php /Applications/XAMPP/xamppfiles/htdocs/nourstouch/BusinessPortal/cron/send-reminders.php >> /var/log/nourstouch-cron.log 2>&1

# Daily DB backup at 03:00 (rotated, last 14 retained)
0 3 * * * /usr/bin/php /Applications/XAMPP/xamppfiles/htdocs/nourstouch/BusinessPortal/cron/backup-db.php >> /var/log/nourstouch-backup.log 2>&1
```

To test the reminder cron without sending real emails:
```bash
php BusinessPortal/cron/send-reminders.php --dry
```

Both scripts also appear, ready-to-copy, on **Admin → System Health**.

---

## ⚠ The "hard block" rule

Per the original spec: a patient cannot book a new appointment if they have
**any unpaid invoice past due** OR an **overdue installment**. The booking
page (admin or public) shows a red banner; submit is disabled.

Override requires the permission `appointments.override_block` and a written reason —
both are recorded in the activity log.

Implementation: [`includes/booking.php` → `booking_eligibility()`](BusinessPortal/includes/booking.php).

---

## 🔒 Security

- bcrypt passwords + auto rehash on login
- CSRF token on every POST (`csrf_field()` / `csrf_check()`)
- Login throttling: **5 failed attempts / 15 minutes** per `(email, IP)`
- Forced password change on first login (`must_change_pw`)
- Session cookie: HttpOnly · SameSite=Lax · Secure (HTTPS) · regenerate on login
- Soft deletes (`deleted_at`) on user-facing tables
- Immutable `activity_logs` (no UPDATE/DELETE in app code)
- `.htaccess` blocks dotfiles, `*credentials*`, `config/`, `includes/`, `cron/`, `backups/`
- File-upload validation: extension + MIME (`finfo`) + size cap
- All queries use **prepared statements** (PDO)
- Prepared statements enforce `PDO::ATTR_EMULATE_PREPARES = false`
- Honeypot field on public forms (`website` field, blocks bots)

---

## 🧱 Tech stack

| Concern    | Choice |
|------------|--------|
| Backend    | PHP 8 (vanilla, no framework) |
| Database   | MySQL 8 / MariaDB with FK + indexes |
| ORM        | None — direct PDO with prepared statements |
| Frontend   | Bootstrap 5 (RTL/LTR), Font Awesome 6, AOS |
| Charts     | Chart.js 4 |
| Calendar   | FullCalendar 6 (drag-and-drop reschedule) |
| WYSIWYG    | TinyMCE 6 (CMS) |
| PDF        | DomPDF 2 (invoices + payslips) |
| Email      | PHPMailer 6 (SMTP) |
| Lang       | Arabic (RTL default) + English (`?lang=en`) |
| Currency   | JOD (configurable in Settings) |

---

## 🌐 Public site routes

| Route | Page |
|-------|------|
| `/` | Home (sliders + categories + services + packages + testimonials + blog) |
| `/about` | About (CMS-editable) |
| `/services` · `/services?cat=N` | Services with category filter |
| `/therapists` | Team (auto from `users` with role `doctor`/`therapist`) |
| `/packages` | Packages (with included services) |
| `/gallery` | Gallery |
| `/blog` · `/blog?cat=N` | Blog list |
| `/<slug>` | Blog post or CMS page (smart fallback) |
| `/contact` | Contact form → `contact_messages` |
| `/booking` | Online booking (no signup needed) → `booking_requests` |
| `/sitemap.xml` | Auto-generated SEO sitemap |

---

## 📊 Admin pages (40+)

**Operations** (Phases 2-4): Patients · Appointments · Calendar · Consultations
· Services · Categories · Packages

**Finance** (Phase 5): Invoices · Payments · Expenses · Expense Categories
· Cash Drawer · Reports (P&L + AR aging)

**HR** (Phase 6): Employees · Attendance (daily + monthly) · Leaves · Advances · Payroll

**Analytics** (Phase 7): Dashboard (KPIs · charts · heatmap)
· Employee performance · Patient retention · CSV export

**CMS** (Phase 8): Blog (with TinyMCE) · Blog categories · Sliders · Testimonials
· Gallery · FAQs · Pages · Booking requests · Contact messages

**System** (Phases 1, 9, 10): Users · Roles · Settings · Notifications log
· Activity log · System health · Profile

---

## 🗄️ Database schema

The full schema is split across 8 files in [BusinessPortal/config/](BusinessPortal/config/):

`schema.sql` (Phase 1) → `schema_phase2.sql` → `schema_phase3.sql` → `schema_phase4.sql`
→ `schema_phase5.sql` → `schema_phase6.sql` → `schema_phase8.sql` → `schema_phase9.sql`

All tables include `id`, `created_at`, `updated_at`, and (where appropriate)
`deleted_at`, `created_by`, `updated_by`. Foreign keys + indexes enforced at the DB level.

---

## 🐛 Troubleshooting

### "Unknown database 'nourstouch'"
Make sure the database exists and is reachable from the same MySQL instance Apache uses.
On XAMPP this is the bundled MySQL (port 3306, socket `/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock`).

### PDF generation fails with "Path cannot be empty"
DomPDF needs writable font cache. Make sure `BusinessPortal/storage/dompdf/` exists and is `chmod 0775`.

### Email not sending
Configure SMTP at **Admin → Settings → Mail** (host, port, user, pass, secure).
PHPMailer falls back to PHP `mail()` if `mail_host` is empty — useful for local dev.

### Cron sends duplicate reminders
That can't happen — the `notifications` table has `UNIQUE (subject_type, subject_id, kind)`.
If you need to re-send, delete the row from **Admin → Notifications** or click "Resend".

---

## 📝 License & credits

Custom build for Nour's Touch Clinic. Includes open-source dependencies:
DomPDF (LGPL-2.1), PHPMailer (LGPL-2.1), Bootstrap (MIT), Chart.js (MIT),
FullCalendar (MIT), TinyMCE (MIT), AOS (MIT), Font Awesome Free (CC BY 4.0).
