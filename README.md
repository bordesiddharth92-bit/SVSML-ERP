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

The ERP runs on a **dedicated subdomain**, e.g. `https://erp.seavoyageship.com/`.
The subdomain's document root is the ERP project folder
(typically `public_html/erp.seavoyageship.com/`), so the ERP lives at
the root of that subdomain — there is no `/erp/` URL prefix anywhere.

1. **Create the subdomain in cPanel**
   - cPanel → Domains → Create A New Domain
   - Domain: `erp.seavoyageship.com`
   - Document Root: `public_html/erp.seavoyageship.com`
   - Cloudflare users: add an `A` (or proxied `CNAME`) record for `erp` pointing at the same origin and turn proxy ON if you want SSL through Cloudflare. The app makes no assumptions that block proxying.

2. **Create the MySQL database**
   In cPanel → MySQL Databases:
   - a database (e.g. `cpaneluser_svsml`)
   - a user with a strong password
   - grant `ALL PRIVILEGES` on the database to that user

3. **Upload the project**
   Upload the contents of this repository into the subdomain document root
   (`public_html/erp.seavoyageship.com/`).

4. **Configure DB credentials**
   Edit `config/db.php` and set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

5. **(Only if you redeploy somewhere else)** edit two values:
   - `config/app.php` → `define('BASE_URL', '/your-folder/');`
   - `.htaccess` (root) → `RewriteBase /your-folder/`

   `BASE_URL` must always start AND end with a forward slash. For
   subdomain-root or domain-root installs, set it to `'/'` (current default).

6. **Import the schema**
   In cPanel → phpMyAdmin, select the database and import in this order:
   1. `database/schema.sql`
   2. `database/seed.sql`

7. **Run the installer**
   Visit `https://erp.seavoyageship.com/install.php` in your browser.
   It will:
   - verify the schema
   - create the first **admin** user
   - write `install.lock` to disable itself

8. **Delete `install.php`** from the server (recommended).

9. **Log in**
   Go to `https://erp.seavoyageship.com/login.php` with the admin credentials you set during install.

## Local Development

```bash
# from project root
php -S localhost:8080
```

Then visit `http://localhost:8080`. Make sure `config/db.php` points to a local MySQL instance with `schema.sql` + `seed.sql` already imported.

## Build Order

Modules are delivered one at a time. Currently delivered:

- [x] **Module 1** — Schema + project skeleton
- [x] **Module 2** — Dropdowns / Settings
- [x] **Module 3** — Companies & Vessels
- [x] **Module 4** — Crew Personal Details
- [x] **Module 5** — Documents + Courses + Medical
- [x] **Module 6** — Sailing History
- [x] **Module 7** — Sign On / Sign Off (this PR)
- [x] **Module 8** — Contracts (dompdf) (this PR)
- [x] **Module 9** — Travel Details (this PR)
- [ ] Module 10 — Client Approval
- [ ] Module 11 — SVSML Approval
- [ ] Module 12 — Quick Approval
- [ ] Module 13 — Staff Activity Log
- [ ] Module 14 — Dashboard
- [ ] Module 15 — Expiry Alerts Page
- [ ] Module 16 — Crew Login

## Composer setup (one-time, required for Module 8 PDF generation)

Module 8 uses [dompdf](https://github.com/dompdf/dompdf) to render contract
PDFs. The dependency is already declared in `composer.json` but the
`vendor/` directory is intentionally not committed.

**Option A — install via cPanel Terminal (recommended):**

```bash
cd ~/public_html/erp.seavoyageship.com
composer install --no-dev --optimize-autoloader
```

That writes `vendor/autoload.php` and the dompdf classes. After it
finishes, the **Generate PDF** button on `crew-contracts.php` works.

**Option B — pre-build locally and upload `vendor/`:**

```bash
# on your local machine
git clone https://github.com/bordesiddharth92-bit/SVSML-ERP.git
cd SVSML-ERP
composer install --no-dev --optimize-autoloader

# upload the vendor/ directory to public_html/erp.seavoyageship.com/
```

If neither option is run, the contracts page still works for adding /
editing rows. Clicking **Generate PDF** simply shows a friendly error
explaining the setup step instead of crashing.

## Conventions

- **Date status** (used for all expiry fields):
  - `> 30 days`   → green / Valid
  - `0 to 30`     → yellow / Expiring Soon
  - `< 0`         → red / Expired
  - no date       → gray / Missing
- **Activity logging:** every DB write must call `logActivity()` from `includes/helpers.php`.
- **File uploads:** PDF, JPG, PNG, DOCX only; max 10 MB; named `{type}_{crew_id}_{timestamp}.ext`.
