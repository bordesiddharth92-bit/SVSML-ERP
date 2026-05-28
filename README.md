# SVSML ERP — Crew Management System

Crew management system for **Sea Voyage Ship Management LLP (SVSML)**, an RPSL manning agency.

## Tech Stack

- **Backend:** PHP 7.4+ (PDO MySQL)
- **Database:** MySQL 5.7+ / MariaDB 10.3+
- **Frontend:** HTML + CSS + Vanilla JavaScript
- **PDF:** dompdf (installed via Composer — used from Module 8 onward)
- **Hosting:** GoDaddy shared hosting (cPanel)
- **File storage:** server filesystem under `/uploads/`

## User Roles

| Role        | Login key       | Notes                                |
|-------------|-----------------|--------------------------------------|
| `admin`     | email + password| Full power, single account          |
| `sub_admin` | email + password| Full power, multiple accounts        |
| `staff`     | email + password| Entry / edit / upload / dropdowns    |
| `crew`      | passport number | Read mostly; admin must enable login |

## Folder Structure

```
SVSML-ERP/
├── index.php              entry — redirects to login or dashboard
├── login.php              staff / admin / sub_admin login
├── crew-login.php         crew login (full flow lands in Module 16)
├── logout.php
├── dashboard.php          role-based dashboard (skeleton)
├── install.php            ONE-TIME installer — delete after use
├── config/
│   ├── db.php             PDO connection
│   └── config.php         app constants
├── includes/
│   ├── auth.php           session, role guards
│   ├── helpers.php        flash, logActivity, dateStatus, h()
│   ├── header.php
│   ├── footer.php
│   └── sidebar.php
├── database/
│   ├── schema.sql         CREATE TABLE — 22 tables
│   └── seed.sql           default ranks, dropdowns, settings
├── assets/
│   ├── css/style.css
│   └── js/app.js
└── uploads/               file storage (PHP execution blocked)
    ├── crew/{crew_id}/
    ├── vessels/{vessel_id}/
    └── contracts/{crew_id}/
```

`/config`, `/includes`, `/database` are all blocked from direct web access via `.htaccess`. `/uploads` blocks PHP execution.

## First-Time Setup (cPanel / GoDaddy)

1. **Create the MySQL database**
   In cPanel → MySQL Databases, create:
   - a database (e.g. `cpaneluser_svsml`)
   - a user with a strong password
   - grant `ALL PRIVILEGES` on the database to that user

2. **Upload the project**
   Upload the contents of this repository to `public_html/` (or a subdirectory).

3. **Configure DB credentials**
   Edit `config/db.php` and set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

4. **Import the schema**
   In cPanel → phpMyAdmin, select the database and import:
   1. `database/schema.sql`
   2. `database/seed.sql`

5. **Run the installer**
   Visit `https://your-domain.com/install.php` in your browser.
   It will:
   - verify the schema
   - create the first **admin** user
   - write `install.lock` to disable itself

6. **Delete `install.php`** from the server (recommended).

7. **Log in**
   Go to `https://your-domain.com/login.php` with the admin credentials you set during install.

## Local Development

```bash
# from project root
php -S localhost:8080
```

Then visit `http://localhost:8080`. Make sure `config/db.php` points to a local MySQL instance with `schema.sql` + `seed.sql` already imported.

## Build Order

Modules are delivered one at a time. Currently delivered:

- [x] **Module 1** — Schema + project skeleton (this PR)
- [ ] Module 2 — Dropdowns / Settings
- [ ] Module 3 — Companies & Vessels
- [ ] Module 4 — Crew Personal Details
- [ ] Module 5 — Documents + Courses + Medical
- [ ] Module 6 — Sailing History
- [ ] Module 7 — Sign On / Sign Off
- [ ] Module 8 — Contracts (dompdf)
- [ ] Module 9 — Travel Details
- [ ] Module 10 — Client Approval
- [ ] Module 11 — SVSML Approval
- [ ] Module 12 — Quick Approval
- [ ] Module 13 — Staff Activity Log
- [ ] Module 14 — Dashboard
- [ ] Module 15 — Expiry Alerts Page
- [ ] Module 16 — Crew Login

## Conventions

- **Date status** (used for all expiry fields):
  - `> 30 days`   → green / Valid
  - `0 to 30`     → yellow / Expiring Soon
  - `< 0`         → red / Expired
  - no date       → gray / Missing
- **Activity logging:** every DB write must call `logActivity()` from `includes/helpers.php`.
- **File uploads:** PDF, JPG, PNG, DOCX only; max 10 MB; named `{type}_{crew_id}_{timestamp}.ext`.
