# nsw.al Support Desk on a Dedicated MantisBT — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the nsw.al Contact form create tickets in a new, isolated MantisBT install by rewiring the WordPress REST endpoint from Jira to the MantisBT REST API.

**Architecture:** Two deliverables. **Phase A** stands up a dedicated MantisBT 2.28.0 install for nsw.al (own DB, config, branding; reuses NSD's service-desk workflow) using config overrides + an idempotent setup SQL script — the existing NSD install is only read as a template, never modified. **Phase B** replaces the Jira integration in the theme with a small, unit-tested MantisBT REST client (`inc/contact-mantis.php`) called by the existing contact handler. **Phase C** verifies end-to-end and confirms NSD is untouched.

**Tech Stack:** WordPress block theme (PHP 8.0+), MantisBT 2.28.0 (PHP + MySQL, REST API), Homebrew PHP/MySQL for local dev. Tests are plain-PHP (no PHPUnit) run with the `php` CLI, plus `curl` integration checks against the Mantis REST API.

## Global Constraints

- **Never modify the NSD install** at `/Users/albi/dev/mantis-nsd` or its database `nsd_servicedesk`. Read-only template. Verify at the end that both are untouched.
- **Never edit MantisBT core files.** All nsw.al Mantis customization goes in `config/` overrides + `sql/nswal_setup.sql` (mirrors the rule in `mantis-nsd/CLAUDE.md`).
- **Secrets never in the DB or git.** WordPress Mantis secrets live in `wp-config.php` constants. The Mantis DB password and `config_inc.php` are gitignored; the production API token is minted via the Mantis UI at deploy, not committed.
- **MantisBT REST contract (verified against the 2.28.0 tree):**
  - Endpoint: `POST {base}/api/rest/issues` where `{base}` ends in `/`.
  - Auth header: `Authorization: <api_token>` — the raw token, **no** `Bearer` prefix.
  - Request body is the issue object directly (not wrapped): `summary` (required), `description` (required), `project` = `{ "id": <int> }`, `category` = `{ "name": "<string>" }`, `custom_fields` = `[ { "field": { "name": "<string>" }, "value": "<string>" } ]`.
  - Success: HTTP **201** with JSON `{ "issue": { "id": <int>, ... } }`.
  - API token hash stored in `mantis_api_token_table.hash` is `hash('sha256', $plain_token)` (unsalted).
- **New Mantis project id is `1`** and must match `NSW_THEME_MANTIS_PROJECT_ID`.
- **Local dev ports:** serve the new Mantis on **8090** (`php -S`), because 8080 is held by an unrelated Java process. MySQL is Homebrew on 3306.
- **Custom fields are referenced by name** in the REST payload — no numeric field IDs are needed in `wp-config.php`.
- **Front-end is unchanged:** `template-parts/sections/contact-form.php` and `assets/js/main.js` keep the same field set (`fullName, email, organization, category, agency, subject, message, website` honeypot). Attachments remain a no-op (already dropped by `JSON.stringify`).

**Reference (read if you need the source-of-truth template):**
- Spec: `docs/superpowers/specs/2026-07-29-nswal-mantis-support-desk-design.md`
- NSD config template: `/Users/albi/dev/mantis-nsd/config/config_inc.php`, `config/custom_constants_inc.php`, `config/custom_strings_inc.php`
- NSD setup SQL template: `/Users/albi/dev/mantis-nsd/sql/nsd_setup.sql`
- Current WP integration being replaced: `wp-content/themes/nsw-theme/inc/contact-form.php`

---

## File Structure

**New Mantis install — `/Users/albi/dev/mantis-nswal/` (its own git repo, NOT inside AL_Information_Portal):**
- `config/config_inc.php` — DB, path, REST on, reused NSD workflow, nsw.al branding (gitignored; a `.sample` is committed)
- `config/custom_constants_inc.php` — `NSWAL_*` status constants
- `config/custom_strings_inc.php` — status/priority labels + "ticket" terminology
- `sql/nswal_setup.sql` — idempotent: project, 7 categories, 5 custom fields, service + staff users, assignments
- `sql/nswal_dev_token.sql` — **dev-only** seed of a known API token for `nswal_web` (gitignored)
- `DEPLOY.md` — provisioning runbook (local + Plesk)
- `CLAUDE.md`, `.gitignore` — mirror NSD conventions

**WordPress theme — `wp-content/themes/nsw-theme/` (in this repo):**
- `inc/contact-mantis.php` — **new**: MantisBT REST client (config resolver, label maps, pure payload builders, transport). No hooks registered at load, so it is unit-testable under plain PHP.
- `inc/contact-form.php` — **modified**: REST route + security handler + confirmation email + admin settings + SMTP hook. Jira code removed; calls the new client.
- `functions.php` — **modified**: add one `require` line for `inc/contact-mantis.php` before `inc/contact-form.php`.
- `tests/wp-shims.php` — **new**: minimal WordPress function/class shims so theme functions run under the `php` CLI.
- `tests/contact-mantis-test.php` — **new**: unit tests for the client (builders + transport).

---

## Phase A — Stand up the nsw.al Mantis

### Task A1: Create the mantis-nswal tree + config + constants + strings

**Files:**
- Create: `/Users/albi/dev/mantis-nswal/` (pristine MantisBT 2.28.0 tree)
- Create: `/Users/albi/dev/mantis-nswal/config/config_inc.php`
- Create: `/Users/albi/dev/mantis-nswal/config/custom_constants_inc.php`
- Create: `/Users/albi/dev/mantis-nswal/config/custom_strings_inc.php`
- Create: `/Users/albi/dev/mantis-nswal/.gitignore`, `CLAUDE.md`

**Interfaces:**
- Produces: a Mantis tree served at `http://localhost:8090/` whose `config_inc.php` defines DB `nswal_servicedesk`, REST enabled, project workflow with `NSWAL_*` constants, and nsw.al branding. Consumed by A3 (installer + REST).

- [ ] **Step 1: Obtain a pristine MantisBT 2.28.0 tree.** Verify the existing source zip is pristine upstream (no NSD edits), then extract it:

```bash
cd /Users/albi/dev
unzip -l mantis-nsd-source-2026-04-23.zip | grep -E "config_inc.php|core/constant_inc.php" | head
# Confirm it does NOT contain a populated config/config_inc.php (only the .sample) and that
# core/constant_inc.php is 2.28.0. Then extract to mantis-nswal:
mkdir -p mantis-nswal
unzip -q mantis-nsd-source-2026-04-23.zip -d mantis-nswal-extract
# The zip may contain a top-level folder; move the tree so core.php sits at mantis-nswal/core.php:
ls mantis-nswal-extract
# If it extracted into mantis-nswal-extract/<something>/, move that inner dir's contents:
#   rsync -a mantis-nswal-extract/<inner>/ mantis-nswal/   (adjust <inner>)
# Verify:
grep MANTIS_VERSION mantis-nswal/core/constant_inc.php   # expect 2.28.0
```

If the zip is NOT pristine (contains NSD config/plugins), instead download a clean 2.28.0:

```bash
cd /Users/albi/dev
curl -L -o mantisbt-2.28.0.zip https://downloads.sourceforge.net/project/mantisbt/mantis-stable/2.28.0/mantisbt-2.28.0.zip
unzip -q mantisbt-2.28.0.zip && mv mantisbt-2.28.0 mantis-nswal
grep MANTIS_VERSION mantis-nswal/core/constant_inc.php   # expect 2.28.0
```

- [ ] **Step 2: Generate a unique crypto salt** (used in Step 3):

```bash
php -r 'echo bin2hex(random_bytes(32)), "\n";'
```

- [ ] **Step 3: Write `config/custom_constants_inc.php`** (loaded before `config_inc.php` per `core.php`):

```php
<?php
# NSW Albania Support — status constants (reusing MantisBT value slots)
define( 'NSWAL_NEW',          10 );
define( 'NSWAL_IN_PROGRESS',  20 );
define( 'NSWAL_ESCALATED',    30 );
define( 'NSWAL_PENDING_USER', 40 );
define( 'NSWAL_RESOLVED',     80 );
define( 'NSWAL_CLOSED',       90 );
```

- [ ] **Step 4: Write `config/config_inc.php`** (paste the salt from Step 2 into `$g_crypto_master_salt`; pick a local dev DB password):

```php
<?php
# MantisBT Configuration for NSW Albania — Support Desk
# NOTE: gitignored (contains credentials). Keep a redacted config_inc.php.sample in git.

# --- Database ---
$g_hostname      = 'localhost';
$g_db_username   = 'nswal_admin';
$g_db_password   = 'NswalAdmin2026x';   # local dev only; production set on the server
$g_database_name = 'nswal_servicedesk';
$g_db_type       = 'mysqli';

# --- Path (dev; production URL filled at deploy — see DEPLOY.md) ---
$g_path = 'http://localhost:8090/';

# --- Security ---
$g_crypto_master_salt = 'PASTE_64_HEX_FROM_STEP_2';
$g_admin_checks = OFF;

# --- Branding ---
$g_window_title = 'NSW Albania — Support';
$g_logo_image   = 'images/mantis_logo.png';

# --- REST API ---
$g_webservice_rest_enabled = ON;

# --- Signup / Authentication ---
$g_allow_signup            = OFF;
$g_allow_anonymous_login   = OFF;
$g_reauthentication_expiry = 3600;

# --- File Uploads ---
$g_max_file_size      = 10485760; # 10MB
$g_file_upload_method = DATABASE;
$g_allowed_files      = 'pdf,doc,docx,xls,xlsx,txt,png,jpg,jpeg,gif,zip,eml,msg';

# --- Statuses (reused NSD service-desk flow) ---
$g_status_enum_string = '10:new,20:in_progress,30:escalated,40:pending_user,80:resolved,90:closed';
$g_status_colors = array(
    'new'          => '#fcbdbd',
    'in_progress'  => '#e3b7eb',
    'escalated'    => '#ffcd85',
    'pending_user' => '#c9ccc4',
    'resolved'     => '#d2f5b0',
    'closed'       => '#c9ccc4',
);
$g_status_enum_workflow[NSWAL_NEW]          = '20:in_progress,30:escalated';
$g_status_enum_workflow[NSWAL_IN_PROGRESS]  = '30:escalated,40:pending_user,80:resolved';
$g_status_enum_workflow[NSWAL_ESCALATED]    = '20:in_progress,80:resolved';
$g_status_enum_workflow[NSWAL_PENDING_USER] = '20:in_progress,80:resolved,90:closed';
$g_status_enum_workflow[NSWAL_RESOLVED]     = '90:closed,20:in_progress';
$g_status_enum_workflow[NSWAL_CLOSED]       = '20:in_progress';

$g_bug_submit_status             = NSWAL_NEW;
$g_bug_assigned_status           = NSWAL_IN_PROGRESS;
$g_bug_reopen_status             = NSWAL_IN_PROGRESS;
$g_bug_resolved_status_threshold = NSWAL_RESOLVED;

# --- Priorities ---
$g_priority_enum_string = '10:low,20:medium,30:high,40:critical';
$g_default_bug_priority = 20; # Medium

# --- Access control (reporters see only their own tickets) ---
$g_limit_reporters       = ON;
$g_report_bug_threshold  = REPORTER;
$g_update_bug_threshold  = DEVELOPER;
$g_handle_bug_threshold  = DEVELOPER;
$g_assign_bug_threshold  = DEVELOPER;
$g_add_bugnote_threshold = REPORTER;
$g_delete_bug_threshold  = MANAGER;
$g_move_bug_threshold    = MANAGER;

# --- Email / SMTP (placeholders; set real values at deploy) ---
$g_phpMailer_method     = PHPMAILER_METHOD_SMTP;
$g_smtp_host            = 'smtp.example.com';
$g_smtp_port            = 587;
$g_smtp_username        = 'noreply@nsw.al';
$g_smtp_password        = '';
$g_smtp_connection_mode = 'tls';

$g_webmaster_email   = 'info@nsw.al';
$g_from_email        = 'noreply@nsw.al';
$g_from_name         = 'NSW Albania Support';
$g_return_path_email = 'noreply@nsw.al';

$g_enable_email_notification = ON;
$g_email_send_using_cronjob  = OFF;
```

- [ ] **Step 5: Write `config/custom_strings_inc.php`** (status/priority labels + light "ticket" terminology):

```php
<?php
# NSW Albania Support — string overrides

# Status labels
$s_status_enum_string = '10:new,20:in_progress,30:escalated,40:pending_user,80:resolved,90:closed';
$s_new_bug_title          = 'New';
$s_in_progress_bug_title  = 'In Progress';
$s_escalated_bug_title    = 'Escalated';
$s_pending_user_bug_title = 'Pending User';
$s_resolved_bug_title     = 'Resolved';
$s_closed_bug_title       = 'Closed';
$s_new_bug_button          = 'New';
$s_in_progress_bug_button  = 'In Progress';
$s_escalated_bug_button    = 'Escalated';
$s_pending_user_bug_button = 'Pending User';
$s_resolved_bug_button     = 'Resolved';
$s_closed_bug_button       = 'Closed';

# Priority labels
$s_priority_enum_string = '10:low,20:medium,30:high,40:critical';

# Terminology
$s_bug             = 'ticket';
$s_bugs            = 'tickets';
$s_email_bug       = 'Ticket';
$s_login_page_info = 'Welcome to NSW Albania Support.';
```

- [ ] **Step 6: Write `.gitignore` and `CLAUDE.md`:**

`.gitignore`:
```
config/config_inc.php
sql/nswal_dev_token.sql
sql/full_dump.sql
```

`CLAUDE.md`:
```markdown
# NSW Albania Support MantisBT — Project Rules

## Core Rule
Do NOT modify MantisBT core files. All changes via config overrides (`config/`),
custom strings, or SQL setup scripts.

## Setup Script (`sql/nswal_setup.sql`)
All DB-level configuration (project, categories, custom fields, users) lives here so a
fresh MantisBT install can be brought to nsw.al state by running it. Whenever you make a
DB-level change, update this script to keep it reproducible.

## Isolation
This install is completely separate from the NSD Mantis (`/Users/albi/dev/mantis-nsd`).
Never share a database or touch NSD.
```

- [ ] **Step 7: Init git and commit the artifacts** (config_inc.php is gitignored):

```bash
cd /Users/albi/dev/mantis-nswal
cp config/config_inc.php config/config_inc.php.sample   # then redact the passwords/salt in the .sample
git init -q && git add config/config_inc.php.sample config/custom_constants_inc.php config/custom_strings_inc.php .gitignore CLAUDE.md
git commit -q -m "nsw.al Mantis: config, constants, strings (reused NSD service-desk workflow)"
```

Expected: commit succeeds; `git status` shows `config/config_inc.php` untracked/ignored.

---

### Task A2: Write the reproducible setup SQL

**Files:**
- Create: `/Users/albi/dev/mantis-nswal/sql/nswal_setup.sql`

**Interfaces:**
- Produces: after the installer builds the schema, this script creates project id `1` ("NSW Albania Support"), 7 categories, 5 custom fields (referenced by name from WordPress), the `nswal_web` service account (REPORTER), and starter staff. Consumed by A3.

- [ ] **Step 1: Write `sql/nswal_setup.sql`** (mirrors `mantis-nsd/sql/nsd_setup.sql`; column shapes verified against the live schema):

```sql
-- =============================================================================
-- NSW Albania Support — MantisBT Setup Script
-- Run AFTER a fresh MantisBT install (admin/install.php) to configure the
-- NSW Albania project, categories, custom fields, and user accounts.
--   Usage:  mysql -u <user> -p nswal_servicedesk < sql/nswal_setup.sql
-- Idempotent where possible (INSERT IGNORE).
-- =============================================================================

-- ---- Project (id=1, matches NSW_THEME_MANTIS_PROJECT_ID) ---------------------
DELETE FROM mantis_category_table WHERE project_id = 0 AND name = 'General';

INSERT IGNORE INTO mantis_project_table
  (id, name, status, enabled, view_state, description, inherit_global)
VALUES
  (1, 'NSW Albania Support', 10, 1, 10,
   'Public support desk for the NSW Albania Information Portal (nsw.al). Tickets are created from the website contact form.',
   0);

-- ---- Categories (7) — match the contact form's category options --------------
INSERT IGNORE INTO mantis_category_table (project_id, user_id, name, status) VALUES
  (1, 0, 'General inquiry', 1),
  (1, 0, 'LPCO process',    1),
  (1, 0, 'Registration',    1),
  (1, 0, 'Payment',         1),
  (1, 0, 'Technical issue', 1),
  (1, 0, 'Feedback',        1),
  (1, 0, 'Other',           1);

-- ---- Custom fields (5) — referenced BY NAME from WordPress -------------------
-- type: 0=STRING, 3=ENUM. access 25=REPORTER. All optional at report time.
INSERT IGNORE INTO mantis_custom_field_table
  (name, type, possible_values, default_value, valid_regexp,
   access_level_r, access_level_rw, length_min, length_max,
   require_report, require_update, require_resolved, require_closed,
   display_report, display_update, display_resolved, display_closed, filter_by)
VALUES
  ('Customer Name',   0, '',                    '',       '', 25, 25, 0, 120, 0,0,0,0, 1,1,1,1, 1),
  ('Customer Email',  0, '',                    '',       '', 25, 25, 0, 200, 0,0,0,0, 1,1,1,1, 1),
  ('Organization',    0, '',                    '',       '', 25, 25, 0, 150, 0,0,0,0, 1,1,1,1, 1),
  ('Relevant Agency', 0, '',                    '',       '', 25, 25, 0, 150, 0,0,0,0, 1,1,1,1, 1),
  ('Source Channel',  3, 'Portal|Email|Phone',  'Portal', '', 25, 25, 0, 0,   0,0,0,0, 1,1,1,1, 1);

-- Link custom fields to project 1 (sequence = display order)
INSERT IGNORE INTO mantis_custom_field_project_table (field_id, project_id, sequence)
SELECT id, 1,
  CASE name
    WHEN 'Customer Name'   THEN 1
    WHEN 'Customer Email'  THEN 2
    WHEN 'Organization'    THEN 3
    WHEN 'Relevant Agency' THEN 4
    WHEN 'Source Channel'  THEN 5
  END
FROM mantis_custom_field_table
WHERE name IN ('Customer Name','Customer Email','Organization','Relevant Agency','Source Channel');

-- ---- Users ------------------------------------------------------------------
-- nswal_web = service account used ONLY by the WordPress integration (REPORTER=25).
-- Staff starters use password 'nswal1234' (replace in production).
INSERT IGNORE INTO mantis_user_table
  (username, realname, email, password, enabled, access_level,
   date_created, last_visit, cookie_string, protected)
VALUES
  ('nswal_web', 'Website Contact Form', 'noreply@nsw.al',
   MD5('nswal1234'), 1, 25,
   UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), CONCAT('nswal_web_', MD5(RAND())), 0),
  ('nswal_officer', 'Support Officer', 'officer@nsw.al',
   MD5('nswal1234'), 1, 55,
   UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), CONCAT('nswal_officer_', MD5(RAND())), 0),
  ('nswal_supervisor', 'Support Supervisor', 'supervisor@nsw.al',
   MD5('nswal1234'), 1, 70,
   UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), CONCAT('nswal_supervisor_', MD5(RAND())), 0);

-- Assign users to project 1
INSERT IGNORE INTO mantis_project_user_list_table (project_id, user_id, access_level)
SELECT 1, id,
  CASE username
    WHEN 'nswal_web'        THEN 25
    WHEN 'nswal_officer'    THEN 55
    WHEN 'nswal_supervisor' THEN 70
  END
FROM mantis_user_table
WHERE username IN ('nswal_web','nswal_officer','nswal_supervisor');
```

- [ ] **Step 2: Commit:**

```bash
cd /Users/albi/dev/mantis-nswal
git add sql/nswal_setup.sql && git commit -q -m "nsw.al Mantis: reproducible setup SQL (project, categories, custom fields, users)"
```

---

### Task A3: Provision the DB, install schema, apply setup, verify REST creates a ticket

This task's "test" is a live REST assertion. Write the assertion first, watch it fail (no server/ticket), then provision until it passes.

**Files:**
- Create: `/Users/albi/dev/mantis-nswal/sql/nswal_dev_token.sql` (dev-only seed token)

**Interfaces:**
- Consumes: A1 (tree + config), A2 (setup SQL).
- Produces: a running local Mantis at `http://localhost:8090/` with a ticket-creating REST endpoint and a known dev token `nswaldevtoken0000000000000000000`. Consumed by Phase C.

- [ ] **Step 1: Write the acceptance assertion (the "failing test").** Save as `/Users/albi/dev/mantis-nswal/sql/../verify_rest.sh` → actually create `/Users/albi/dev/mantis-nswal/scripts/verify_rest.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
BASE="http://localhost:8090"
TOKEN="nswaldevtoken0000000000000000000"
RESP="$(curl -s -w '\n%{http_code}' -X POST "$BASE/api/rest/issues" \
  -H "Authorization: $TOKEN" -H "Content-Type: application/json" \
  -d '{
    "summary": "Smoke test from verify_rest.sh",
    "description": "From: Test User <test@example.com>\nCategory: General inquiry\n\nHello from the acceptance test.",
    "project":  { "id": 1 },
    "category": { "name": "General inquiry" },
    "custom_fields": [
      { "field": { "name": "Customer Name" },  "value": "Test User" },
      { "field": { "name": "Customer Email" }, "value": "test@example.com" },
      { "field": { "name": "Source Channel" }, "value": "Portal" }
    ]
  }')"
CODE="$(echo "$RESP" | tail -n1)"
BODY="$(echo "$RESP" | sed '$d')"
echo "$BODY"; echo "HTTP $CODE"
test "$CODE" = "201" || { echo "FAIL: expected 201"; exit 1; }
echo "$BODY" | grep -q '"id"' || { echo "FAIL: no issue id in response"; exit 1; }
echo "PASS"
```

Run it now to confirm it FAILS (nothing is serving 8090 yet):

```bash
chmod +x /Users/albi/dev/mantis-nswal/scripts/verify_rest.sh
/Users/albi/dev/mantis-nswal/scripts/verify_rest.sh || echo "expected failure at this point"
```

Expected: connection refused / non-201 → the assertion fails. Good.

- [ ] **Step 2: Create the database and grant the config DB user.** Use a MySQL admin login (root or your Homebrew admin). If root has no password locally:

```bash
mysql -u root <<'SQL'
CREATE DATABASE IF NOT EXISTS nswal_servicedesk CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'nswal_admin'@'localhost' IDENTIFIED BY 'NswalAdmin2026x';
GRANT ALL PRIVILEGES ON nswal_servicedesk.* TO 'nswal_admin'@'localhost';
FLUSH PRIVILEGES;
SQL
mysql -u nswal_admin -pNswalAdmin2026x -e "SELECT 'db ok';" nswal_servicedesk
```

Expected: `db ok`. (If root needs a password, use `mysql -u root -p`.)

- [ ] **Step 3: Serve the Mantis tree** (leave running in a background terminal):

```bash
php -S localhost:8090 -t /Users/albi/dev/mantis-nswal
```

- [ ] **Step 4: Build the schema with the MantisBT installer.**

**Canonical (browser):** open `http://localhost:8090/admin/install.php`. It reads DB settings from `config_inc.php`. Set the **Administrator** username to `administrator` and a password you choose, leave DB fields as prefilled, click **Install/Upgrade Database**. Wait for all rows to report *Good*. (This creates all tables, the schema-version row, and the admin account.)

**Scripted alternative (curl):**
```bash
curl -s "http://localhost:8090/admin/install.php?install=2&admin_username=administrator&admin_password=AdminNswal2026x" \
  | grep -iE "good|already|error|installed" | head
```
Then verify tables exist:
```bash
mysql -u nswal_admin -pNswalAdmin2026x -e "SHOW TABLES;" nswal_servicedesk | grep -c mantis_
```
Expected: a count well above 30 (all Mantis tables present).

- [ ] **Step 5: Apply the nsw.al setup SQL:**

```bash
mysql -u nswal_admin -pNswalAdmin2026x nswal_servicedesk < /Users/albi/dev/mantis-nswal/sql/nswal_setup.sql
mysql -u nswal_admin -pNswalAdmin2026x -e \
  "SELECT id,name FROM mantis_project_table; SELECT name FROM mantis_category_table WHERE project_id=1; SELECT username FROM mantis_user_table;" \
  nswal_servicedesk
```
Expected: project id `1` = "NSW Albania Support"; 7 categories; users incl. `nswal_web`.

- [ ] **Step 6: Seed the dev API token** (dev-only; hash = sha256 of the plain token):

Write `/Users/albi/dev/mantis-nswal/sql/nswal_dev_token.sql`:
```sql
-- DEV ONLY. Seeds a known API token for nswal_web so local/CI tests can call REST.
-- Plain token: nswaldevtoken0000000000000000000   Hash: sha256(plain), unsalted.
-- Production: mint a real token via the Mantis UI instead (see DEPLOY.md).
INSERT IGNORE INTO mantis_api_token_table (user_id, name, hash, date_created, date_used)
SELECT u.id, 'wordpress-dev',
       SHA2('nswaldevtoken0000000000000000000', 256),
       UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM mantis_user_table u
WHERE u.username = 'nswal_web';
```
Apply it:
```bash
mysql -u nswal_admin -pNswalAdmin2026x nswal_servicedesk < /Users/albi/dev/mantis-nswal/sql/nswal_dev_token.sql
```

- [ ] **Step 7: Run the acceptance assertion — it should now PASS:**

```bash
/Users/albi/dev/mantis-nswal/scripts/verify_rest.sh
```
Expected: prints the created issue JSON, `HTTP 201`, then `PASS`.

- [ ] **Step 8: Confirm the ticket landed in the right project with fields populated:**

```bash
curl -s -H "Authorization: nswaldevtoken0000000000000000000" "http://localhost:8090/api/rest/issues/1" \
  | python3 -m json.tool | grep -iE '"name"|"id"|Customer|Portal|General inquiry' | head
```
Expected: project "NSW Albania Support", category "General inquiry", custom fields present.

- [ ] **Step 9: Commit the verify script** (dev token SQL is gitignored):

```bash
cd /Users/albi/dev/mantis-nswal
git add scripts/verify_rest.sh && git commit -q -m "nsw.al Mantis: REST acceptance smoke test"
```

---

### Task A4: Write the deploy runbook

**Files:**
- Create: `/Users/albi/dev/mantis-nswal/DEPLOY.md`

- [ ] **Step 1: Write `DEPLOY.md`** capturing the exact steps proven in A1–A3, for local and Plesk:

```markdown
# NSW Albania Support (MantisBT) — Deploy Runbook

Isolated from the NSD Mantis. Its own DB (`nswal_servicedesk`), config, URL.

## 1. Files
Deploy the MantisBT 2.28.0 tree plus the nsw.al overrides:
`config/config_inc.php` (from `.sample`, filled with real creds), `config/custom_constants_inc.php`,
`config/custom_strings_inc.php`, and `sql/nswal_setup.sql`. Never copy anything from the NSD install.

## 2. Database
Create `nswal_servicedesk` (utf8mb4) and a DB user with full rights on it. Put those creds
and a fresh `$g_crypto_master_salt` (`php -r 'echo bin2hex(random_bytes(32));'`) into `config_inc.php`.
Set `$g_path` to the production URL (e.g. https://support.nsw.al/) — **the final URL is TBD until
the host is decided; this is the only value blocking production.**

## 3. Schema + setup
Run `admin/install.php` (build schema + create the admin account), then
`mysql -u <user> -p nswal_servicedesk < sql/nswal_setup.sql`.

## 4. API token for WordPress
Log into Mantis as `nswal_web` (or impersonate via admin) → **My Account → API Tokens** →
create token "wordpress" → copy the value ONCE. Put it in the WordPress `wp-config.php` as
`NSW_THEME_MANTIS_TOKEN`. (Do NOT use the dev seed token in production.)

## 5. Wire WordPress
In the site's `wp-config.php`:
    define( 'NSW_THEME_MANTIS_URL',        'https://support.nsw.al/' );
    define( 'NSW_THEME_MANTIS_TOKEN',      '<token from step 4>' );
    define( 'NSW_THEME_MANTIS_PROJECT_ID', '1' );

## 6. SMTP + SSL
Fill the `$g_smtp_*` block, enable Let's Encrypt on the host, set `$g_email_send_using_cronjob = ON`
with a cron if volume warrants.

## 7. Verify
`scripts/verify_rest.sh` (point BASE/TOKEN at production) → expect HTTP 201, then submit the
live nsw.al contact form and confirm a ticket appears in the NSW Albania Support project.
```

- [ ] **Step 2: Commit:**

```bash
cd /Users/albi/dev/mantis-nswal && git add DEPLOY.md && git commit -q -m "nsw.al Mantis: deploy runbook"
```

---

## Phase B — WordPress: replace Jira with the MantisBT client

### Task B1: Add the plain-PHP test harness (WP shims)

**Files:**
- Create: `wp-content/themes/nsw-theme/tests/wp-shims.php`

**Interfaces:**
- Produces: `ABSPATH` defined + shims for `apply_filters`, `get_option`, `__`, `wp_json_encode`, `is_wp_error`, `WP_Error`, `wp_remote_post`, `wp_remote_retrieve_response_code`, `wp_remote_retrieve_body`, and globals `$GLOBALS['__wp_remote_post_return']` / `$GLOBALS['__wp_remote_post_args']` for controlling/capturing the HTTP stub. Consumed by B2/B3 tests.

- [ ] **Step 1: Write `tests/wp-shims.php`:**

```php
<?php
/**
 * Minimal WordPress shims so theme client functions run under the plain `php` CLI.
 * Test-only. Not loaded by WordPress (WordPress defines these for real).
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) { return $default; }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code = $code; $this->message = $message; $this->data = $data;
        }
        public function get_error_message() { return $this->message; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
/* Controllable HTTP stub. Tests set $GLOBALS['__wp_remote_post_return']; args captured for assertion. */
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        $GLOBALS['__wp_remote_post_args'] = array( 'url' => $url, 'args' => $args );
        return $GLOBALS['__wp_remote_post_return'] ?? array( 'response' => array( 'code' => 0 ), 'body' => '' );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
}
```

- [ ] **Step 2: Commit:**

```bash
cd /Users/albi/dev/AL_Information_Portal
git add wp-content/themes/nsw-theme/tests/wp-shims.php
git commit -m "test(theme): add plain-PHP WordPress shims for contact client tests"
```

---

### Task B2: MantisBT client — pure payload builders (TDD)

**Files:**
- Create: `wp-content/themes/nsw-theme/inc/contact-mantis.php`
- Test: `wp-content/themes/nsw-theme/tests/contact-mantis-test.php`

**Interfaces:**
- Produces (consumed by B3, and by `inc/contact-form.php`):
  - `nsw_theme_contact_config( string $key, string $default = '' ): string`
  - `nsw_theme_contact_mantis_configured(): bool`
  - `nsw_theme_contact_category_labels(): array` / `nsw_theme_contact_agency_labels(): array`
  - `nsw_theme_contact_map_category( string $key ): string` — Mantis category name; fallback `'General inquiry'`
  - `nsw_theme_contact_build_description( array $data ): string`
  - `nsw_theme_contact_build_custom_fields( array $data ): array`
  - `nsw_theme_contact_build_issue_payload( array $data ): array`
- `$data` shape (from the handler): `['fullName','email','organization','category','agency','subject','message']` (all strings).

- [ ] **Step 1: Write the failing tests** in `tests/contact-mantis-test.php`:

```php
<?php
/** Plain-PHP unit tests for the MantisBT contact client. Run: php tests/contact-mantis-test.php */
require __DIR__ . '/wp-shims.php';
define( 'NSW_THEME_MANTIS_URL',        'http://localhost:8090/' );
define( 'NSW_THEME_MANTIS_TOKEN',      'tok-123' );
define( 'NSW_THEME_MANTIS_PROJECT_ID', '1' );
require __DIR__ . '/../inc/contact-mantis.php';

$fails = 0;
function check( $label, $cond ) {
    global $fails;
    if ( $cond ) { echo "  ok  - $label\n"; }
    else { echo "  FAIL- $label\n"; $fails++; }
}

$data = array(
    'fullName'     => 'Jane Doe',
    'email'        => 'jane@example.com',
    'organization' => 'ACME sh.p.k.',
    'category'     => 'technical',
    'agency'       => 'customs',
    'subject'      => 'Cannot upload LPCO',
    'message'      => "Line one.\nLine two.",
);

// map_category
check( 'map known category', nsw_theme_contact_map_category( 'technical' ) === 'Technical issue' );
check( 'map unknown category falls back', nsw_theme_contact_map_category( 'zzz' ) === 'General inquiry' );

// build_description
$desc = nsw_theme_contact_build_description( $data );
check( 'description first line is From', strpos( $desc, "From: Jane Doe <jane@example.com>" ) === 0 );
check( 'description has Category label', strpos( $desc, "Category: Technical issue" ) !== false );
check( 'description has Organization', strpos( $desc, "Organization: ACME sh.p.k." ) !== false );
check( 'description contains message body', strpos( $desc, "Line one.\nLine two." ) !== false );

// build_custom_fields
$cf = nsw_theme_contact_build_custom_fields( $data );
$byName = array();
foreach ( $cf as $row ) { $byName[ $row['field']['name'] ] = $row['value']; }
check( 'cf Customer Name',  ( $byName['Customer Name']  ?? '' ) === 'Jane Doe' );
check( 'cf Customer Email', ( $byName['Customer Email'] ?? '' ) === 'jane@example.com' );
check( 'cf Organization',   ( $byName['Organization']   ?? '' ) === 'ACME sh.p.k.' );
check( 'cf Source Channel', ( $byName['Source Channel'] ?? '' ) === 'Portal' );

// build_issue_payload
$p = nsw_theme_contact_build_issue_payload( $data );
check( 'payload summary',      $p['summary'] === 'Cannot upload LPCO' );
check( 'payload project id',   $p['project']['id'] === 1 );
check( 'payload category name', $p['category']['name'] === 'Technical issue' );
check( 'payload has custom_fields array', is_array( $p['custom_fields'] ) && count( $p['custom_fields'] ) >= 4 );

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
```

- [ ] **Step 2: Run it — expect failure** (functions not defined yet):

```bash
cd /Users/albi/dev/AL_Information_Portal
php wp-content/themes/nsw-theme/tests/contact-mantis-test.php
```
Expected: fatal error `Call to undefined function nsw_theme_contact_map_category()`.

- [ ] **Step 3: Implement `inc/contact-mantis.php`** (builders only for now; transport added in B3):

```php
<?php
/**
 * MantisBT contact client — config resolver, label maps, pure payload builders,
 * and the REST transport (added in B3). No hooks registered at load, so this file
 * is unit-testable under plain PHP.
 *
 * Secrets (wp-config.php constants):
 *   NSW_THEME_MANTIS_URL         base URL, trailing slash (REST at {URL}api/rest/)
 *   NSW_THEME_MANTIS_TOKEN       API token for the nswal_web service account
 *   NSW_THEME_MANTIS_PROJECT_ID  Mantis project id (default "1")
 *
 * @package NSW_Theme
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Constants (wp-config.php) win over wp_options so secrets never land in the DB. */
function nsw_theme_contact_config( string $key, string $default = '' ): string {
    $const = 'NSW_THEME_' . strtoupper( $key );
    if ( defined( $const ) ) { return (string) constant( $const ); }
    return (string) get_option( 'nsw_theme_' . strtolower( $key ), $default );
}

/** True only when URL + token + project id are all set. */
function nsw_theme_contact_mantis_configured(): bool {
    return '' !== nsw_theme_contact_config( 'mantis_url' )
        && '' !== nsw_theme_contact_config( 'mantis_token' )
        && '' !== nsw_theme_contact_config( 'mantis_project_id', '1' );
}

/** Form category key => Mantis category name (must match sql/nswal_setup.sql). */
function nsw_theme_contact_category_labels(): array {
    return apply_filters( 'nsw_theme_contact_category_labels', array(
        'general'      => 'General inquiry',
        'lpco'         => 'LPCO process',
        'registration' => 'Registration',
        'payment'      => 'Payment',
        'technical'    => 'Technical issue',
        'feedback'     => 'Feedback',
        'other'        => 'Other',
    ) );
}

/** Agency slug => human label (best-effort; dynamic CPT slugs fall through to raw value). */
function nsw_theme_contact_agency_labels(): array {
    return apply_filters( 'nsw_theme_contact_agency_labels', array(
        'general' => 'General',
        'customs' => 'Customs',
        'other'   => 'Other',
    ) );
}

/** Map a form category key to a Mantis category name; fallback to 'General inquiry'. */
function nsw_theme_contact_map_category( string $key ): string {
    $labels = nsw_theme_contact_category_labels();
    return $labels[ $key ] ?? 'General inquiry';
}

/** Plain-text description: `From:` first line (agent-facing), meta lines, then the message. */
function nsw_theme_contact_build_description( array $data ): string {
    $lines = array();
    $lines[] = 'From: ' . $data['fullName'] . ' <' . $data['email'] . '>';
    if ( '' !== ( $data['organization'] ?? '' ) ) {
        $lines[] = 'Organization: ' . $data['organization'];
    }
    $lines[] = 'Category: ' . nsw_theme_contact_map_category( (string) ( $data['category'] ?? '' ) );
    if ( '' !== ( $data['agency'] ?? '' ) ) {
        $agencies = nsw_theme_contact_agency_labels();
        $lines[]  = 'Agency: ' . ( $agencies[ $data['agency'] ] ?? $data['agency'] );
    }
    $lines[] = '';
    $lines[] = (string) ( $data['message'] ?? '' );
    return implode( "\n", $lines );
}

/** Build the REST custom_fields array (referenced by name). Blank optional fields are skipped. */
function nsw_theme_contact_build_custom_fields( array $data ): array {
    $fields   = array();
    $agencies = nsw_theme_contact_agency_labels();
    $add = function ( $name, $value ) use ( &$fields ) {
        if ( '' !== (string) $value ) {
            $fields[] = array( 'field' => array( 'name' => $name ), 'value' => (string) $value );
        }
    };
    $add( 'Customer Name',  $data['fullName'] ?? '' );
    $add( 'Customer Email', $data['email'] ?? '' );
    $add( 'Organization',   $data['organization'] ?? '' );
    if ( '' !== ( $data['agency'] ?? '' ) ) {
        $add( 'Relevant Agency', $agencies[ $data['agency'] ] ?? $data['agency'] );
    }
    $add( 'Source Channel', 'Portal' );
    return $fields;
}

/** Assemble the full MantisBT issue payload. */
function nsw_theme_contact_build_issue_payload( array $data ): array {
    return array(
        'summary'       => (string) ( $data['subject'] ?? '' ),
        'description'   => nsw_theme_contact_build_description( $data ),
        'project'       => array( 'id' => (int) nsw_theme_contact_config( 'mantis_project_id', '1' ) ),
        'category'      => array( 'name' => nsw_theme_contact_map_category( (string) ( $data['category'] ?? '' ) ) ),
        'custom_fields' => nsw_theme_contact_build_custom_fields( $data ),
    );
}
```

- [ ] **Step 4: Run the tests — expect PASS:**

```bash
php wp-content/themes/nsw-theme/tests/contact-mantis-test.php
```
Expected: all `ok`, ends with `ALL PASS`.

- [ ] **Step 5: Commit:**

```bash
git add wp-content/themes/nsw-theme/inc/contact-mantis.php wp-content/themes/nsw-theme/tests/contact-mantis-test.php
git commit -m "feat(theme): MantisBT contact client — payload builders (TDD)"
```

---

### Task B3: MantisBT client — REST transport (TDD)

**Files:**
- Modify: `wp-content/themes/nsw-theme/inc/contact-mantis.php` (append transport function)
- Modify: `wp-content/themes/nsw-theme/tests/contact-mantis-test.php` (append transport tests)

**Interfaces:**
- Produces: `nsw_theme_contact_create_mantis_issue( array $data )` → `array{ id:int }` on success, or `WP_Error` on transport/HTTP failure. Consumed by `inc/contact-form.php` (B4).

- [ ] **Step 1: Append failing transport tests** to `tests/contact-mantis-test.php` (before the final summary block — move the `echo $fails ...` lines to the very end):

```php
// ---- transport: success ----
$GLOBALS['__wp_remote_post_return'] = array(
    'response' => array( 'code' => 201 ),
    'body'     => json_encode( array( 'issue' => array( 'id' => 42 ) ) ),
);
$res = nsw_theme_contact_create_mantis_issue( $data );
check( 'create returns id on 201', is_array( $res ) && ( $res['id'] ?? 0 ) === 42 );
check( 'POST hit issues endpoint', strpos( $GLOBALS['__wp_remote_post_args']['url'], '/api/rest/issues' ) !== false );
check( 'Authorization header is raw token',
    ( $GLOBALS['__wp_remote_post_args']['args']['headers']['Authorization'] ?? '' ) === 'tok-123' );

// ---- transport: HTTP error ----
$GLOBALS['__wp_remote_post_return'] = array(
    'response' => array( 'code' => 400 ),
    'body'     => '{"message":"bad"}',
);
$res = nsw_theme_contact_create_mantis_issue( $data );
check( 'create returns WP_Error on 400', is_wp_error( $res ) );

// ---- transport: connection error ----
$GLOBALS['__wp_remote_post_return'] = new WP_Error( 'http_request_failed', 'down' );
$res = nsw_theme_contact_create_mantis_issue( $data );
check( 'create returns WP_Error on transport error', is_wp_error( $res ) );
```

- [ ] **Step 2: Run — expect the new checks to FAIL** (function undefined):

```bash
php wp-content/themes/nsw-theme/tests/contact-mantis-test.php
```
Expected: fatal `undefined function nsw_theme_contact_create_mantis_issue()`.

- [ ] **Step 3: Append the transport function** to `inc/contact-mantis.php`:

```php
/**
 * Create a MantisBT issue via REST. Returns array{id:int} or WP_Error.
 *
 * @param array $data Validated form data.
 * @return array{id:int}|WP_Error
 */
function nsw_theme_contact_create_mantis_issue( array $data ) {
    $base  = rtrim( nsw_theme_contact_config( 'mantis_url' ), '/' );
    $token = nsw_theme_contact_config( 'mantis_token' );

    $response = wp_remote_post(
        $base . '/api/rest/issues',
        array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => $token,           // raw token, no Bearer prefix
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( nsw_theme_contact_build_issue_payload( $data ) ),
        )
    );

    if ( is_wp_error( $response ) ) {
        error_log( 'NSW Theme contact: Mantis transport error: ' . $response->get_error_message() );
        return new WP_Error( 'nsw_theme_mantis_failed', __( 'Failed to create support request.', 'nsw-theme' ), array( 'status' => 502 ) );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = (string) wp_remote_retrieve_body( $response );

    if ( $code < 200 || $code >= 300 ) {
        error_log( 'NSW Theme contact: Mantis create issue error: ' . $code . ' — ' . $body );
        return new WP_Error( 'nsw_theme_mantis_failed', __( 'Failed to create support request.', 'nsw-theme' ), array( 'status' => 502 ) );
    }

    $decoded = json_decode( $body, true );
    $id      = ( is_array( $decoded ) && isset( $decoded['issue']['id'] ) ) ? (int) $decoded['issue']['id'] : 0;
    return array( 'id' => $id );
}
```

- [ ] **Step 4: Run — expect ALL PASS:**

```bash
php wp-content/themes/nsw-theme/tests/contact-mantis-test.php
```
Expected: ends with `ALL PASS`.

- [ ] **Step 5: Commit:**

```bash
git add wp-content/themes/nsw-theme/inc/contact-mantis.php wp-content/themes/nsw-theme/tests/contact-mantis-test.php
git commit -m "feat(theme): MantisBT contact client — REST transport (TDD)"
```

---

### Task B4: Rewire the handler + settings; remove Jira

**Files:**
- Modify: `wp-content/themes/nsw-theme/functions.php:32-33` (add require)
- Modify: `wp-content/themes/nsw-theme/inc/contact-form.php` (full rewrite of the WP glue; Jira removed)

**Interfaces:**
- Consumes: the B2/B3 client functions.
- Produces: the REST route `nsw-theme/v1/contact` now creates Mantis tickets and returns `{ success, ticket: "NSWAL-<id>", message }`.

- [ ] **Step 1: Add the require in `functions.php`.** Change the block at lines 32-33 from:

```php
	require NSW_THEME_DIR . 'inc/meta-boxes.php';
	require NSW_THEME_DIR . 'inc/contact-form.php';
```
to:
```php
	require NSW_THEME_DIR . 'inc/meta-boxes.php';
	require NSW_THEME_DIR . 'inc/contact-mantis.php';
	require NSW_THEME_DIR . 'inc/contact-form.php';
```

- [ ] **Step 2: Replace `inc/contact-form.php` entirely** with the Mantis-backed glue (config resolver + label maps now live in `contact-mantis.php`; Jira ADF/create/settings removed):

```php
<?php
/**
 * Contact form — REST endpoint + MantisBT integration + customer confirmation email.
 *
 *   1. Validate required fields (fullName, email, category, subject, message).
 *   2. Create a MantisBT ticket via REST (see inc/contact-mantis.php).
 *   3. Send a customer-facing confirmation email referencing NSWAL-<id>.
 *
 * Protections: wp_rest nonce, honeypot (`website`), per-IP 30s rate limit, length caps.
 *
 * Secrets (wp-config.php): NSW_THEME_MANTIS_URL, NSW_THEME_MANTIS_TOKEN,
 * NSW_THEME_MANTIS_PROJECT_ID, and (optional) NSW_THEME_SMTP_*.
 *
 * @package NSW_Theme
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ---- REST route ---------------------------------------------------------- */
add_action( 'rest_api_init', function () {
    register_rest_route( 'nsw-theme/v1', '/contact', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'nsw_theme_handle_contact',
        'permission_callback' => '__return_true',
        'args'                => array(
            'fullName'     => array( 'type' => 'string', 'required' => true ),
            'email'        => array( 'type' => 'string', 'required' => true, 'format' => 'email' ),
            'organization' => array( 'type' => 'string' ),
            'category'     => array( 'type' => 'string', 'required' => true ),
            'agency'       => array( 'type' => 'string' ),
            'subject'      => array( 'type' => 'string', 'required' => true ),
            'message'      => array( 'type' => 'string', 'required' => true ),
            'website'      => array( 'type' => 'string' ), // Honeypot.
        ),
    ) );
} );

/* ---- Handler ------------------------------------------------------------- */
function nsw_theme_handle_contact( WP_REST_Request $request ) {

    $nonce = $request->get_header( 'X-WP-Nonce' );
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
        return new WP_Error( 'nsw_theme_bad_nonce', __( 'Security check failed.', 'nsw-theme' ), array( 'status' => 403 ) );
    }

    $params = $request->get_json_params();
    if ( ! is_array( $params ) ) { $params = $request->get_params(); }

    if ( ! empty( $params['website'] ) ) { // honeypot: accept-but-discard
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
    $key = 'nsw_theme_contact_' . md5( $ip );
    if ( get_transient( $key ) ) {
        return new WP_Error( 'nsw_theme_rate_limited', __( 'Please wait a moment before sending again.', 'nsw-theme' ), array( 'status' => 429 ) );
    }
    set_transient( $key, 1, 30 );

    $data = array(
        'fullName'     => mb_substr( sanitize_text_field( (string) ( $params['fullName'] ?? '' ) ), 0, 120 ),
        'email'        => sanitize_email( mb_substr( (string) ( $params['email'] ?? '' ), 0, 200 ) ),
        'organization' => mb_substr( sanitize_text_field( (string) ( $params['organization'] ?? '' ) ), 0, 150 ),
        'category'     => mb_substr( sanitize_text_field( (string) ( $params['category'] ?? '' ) ), 0, 60 ),
        'agency'       => mb_substr( sanitize_text_field( (string) ( $params['agency'] ?? '' ) ), 0, 60 ),
        'subject'      => mb_substr( sanitize_text_field( (string) ( $params['subject'] ?? '' ) ), 0, 200 ),
        'message'      => mb_substr( sanitize_textarea_field( (string) ( $params['message'] ?? '' ) ), 0, 5000 ),
    );

    foreach ( array( 'fullName', 'email', 'category', 'subject', 'message' ) as $required ) {
        if ( '' === trim( (string) $data[ $required ] ) ) {
            return new WP_Error( 'nsw_theme_missing', __( 'Missing required fields.', 'nsw-theme' ), array( 'status' => 400 ) );
        }
    }
    if ( ! is_email( $data['email'] ) ) {
        return new WP_Error( 'nsw_theme_bad_email', __( 'Please enter a valid email.', 'nsw-theme' ), array( 'status' => 422 ) );
    }
    if ( strlen( $data['subject'] ) < 3 ) {
        return new WP_Error( 'nsw_theme_short_subject', __( 'Subject is too short.', 'nsw-theme' ), array( 'status' => 400 ) );
    }
    if ( strlen( $data['message'] ) < 10 ) {
        return new WP_Error( 'nsw_theme_short_message', __( 'Message is too short.', 'nsw-theme' ), array( 'status' => 400 ) );
    }

    if ( nsw_theme_contact_mantis_configured() ) {
        $issue = nsw_theme_contact_create_mantis_issue( $data );
        if ( is_wp_error( $issue ) ) { return $issue; }
        $ref = $issue['id'] ? 'NSWAL-' . $issue['id'] : '';

        if ( $ref ) {
            try { nsw_theme_contact_send_confirmation_email( $data, $ref ); }
            catch ( \Throwable $e ) { error_log( 'NSW Theme contact: confirmation email failed (non-fatal): ' . $e->getMessage() ); }
        }
        return new WP_REST_Response( array( 'success' => true, 'ticket' => $ref, 'message' => __( 'Message sent.', 'nsw-theme' ) ), 200 );
    }

    // Fallback: email the admin when Mantis isn't configured (demo/staging).
    if ( ! nsw_theme_contact_send_admin_notification( $data ) ) {
        return new WP_Error( 'nsw_theme_mail_failed', __( 'Could not send the message. Please try again later.', 'nsw-theme' ), array( 'status' => 500 ) );
    }
    return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Message sent.', 'nsw-theme' ) ), 200 );
}

/* ---- Customer confirmation email ---------------------------------------- */
function nsw_theme_contact_send_confirmation_email( array $data, string $ref ): bool {
    $site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
    $from      = nsw_theme_contact_config( 'contact_from' );
    $reply_to  = nsw_theme_contact_config( 'contact_reply_to' );
    if ( '' === $from )     { $from = nsw_theme_contact_config( 'smtp_user' ); }
    if ( '' === $from )     { $from = (string) get_option( 'admin_email' ); }
    if ( '' === $reply_to ) { $reply_to = $from; }

    $subject = '[' . $ref . '] ' . __( "We've received your message", 'nsw-theme' );
    $name    = esc_html( $data['fullName'] );
    $ref_h   = esc_html( $ref );
    $msg_sub = esc_html( $data['subject'] );
    $msg     = esc_html( $data['message'] );

    $greeting = sprintf( __( 'Hi %s,', 'nsw-theme' ), $name );
    $thanks   = sprintf(
        __( 'Thank you for contacting <strong>%1$s</strong>. Your message has been received and assigned reference <strong>%2$s</strong>.', 'nsw-theme' ),
        esc_html( $site_name ), $ref_h
    );

    $body  = '<div style="font-family:system-ui,-apple-system,sans-serif;color:#222;line-height:1.5;max-width:600px">';
    $body .= '<p>' . $greeting . '</p>';
    $body .= '<p>' . $thanks . '</p>';
    $body .= '<p>' . esc_html__( 'We aim to respond within 36 hours.', 'nsw-theme' ) . '</p>';
    $body .= '<hr style="border:0;border-top:1px solid #ddd;margin:24px 0">';
    $body .= '<p style="color:#666;font-size:13px;margin:0 0 8px"><strong>' . esc_html__( 'Your message:', 'nsw-theme' ) . '</strong></p>';
    $body .= '<p style="color:#666;font-size:13px;margin:0 0 4px"><em>' . $msg_sub . '</em></p>';
    $body .= '<p style="color:#666;font-size:13px;white-space:pre-wrap;margin:0">' . $msg . '</p></div>';

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: "' . str_replace( '"', '', $site_name ) . '" <' . $from . '>',
        'Reply-To: ' . $reply_to,
    );
    return (bool) wp_mail( $data['email'], $subject, $body, $headers );
}

/* ---- Admin fallback notification (Mantis not configured) ----------------- */
function nsw_theme_contact_send_admin_notification( array $data ): bool {
    $to = (string) get_option( 'nsw_theme_contact_email', get_option( 'admin_email' ) );
    if ( '' === $to ) { $to = (string) get_option( 'admin_email' ); }
    $site    = (string) get_bloginfo( 'name' );
    $subject = sprintf( '[%s] %s', $site, $data['subject'] );

    $reply_name = trim( str_replace( array( "\r", "\n" ), '', $data['fullName'] ) );
    $headers    = array(
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: "' . addslashes( $reply_name ) . '" <' . $data['email'] . '>',
    );
    $rows = array(
        __( 'Name', 'nsw-theme' )         => $data['fullName'],
        __( 'Email', 'nsw-theme' )        => $data['email'],
        __( 'Organization', 'nsw-theme' ) => $data['organization'],
        __( 'Category', 'nsw-theme' )     => $data['category'],
        __( 'Agency', 'nsw-theme' )       => $data['agency'],
        __( 'Subject', 'nsw-theme' )      => $data['subject'],
    );
    $body = '<h2>' . esc_html__( 'New contact submission', 'nsw-theme' ) . '</h2><table style="border-collapse:collapse;width:100%;max-width:600px">';
    foreach ( $rows as $label => $value ) {
        if ( '' === $value ) { continue; }
        $body .= sprintf( '<tr><td style="padding:8px 12px;font-weight:bold;border-bottom:1px solid #eee">%s</td><td style="padding:8px 12px;border-bottom:1px solid #eee">%s</td></tr>', esc_html( $label ), esc_html( $value ) );
    }
    $body .= sprintf( '<tr><td style="padding:8px 12px;font-weight:bold;vertical-align:top">%s</td><td style="padding:8px 12px;white-space:pre-wrap">%s</td></tr>', esc_html__( 'Message', 'nsw-theme' ), nl2br( esc_html( $data['message'] ) ) );
    $body .= '</table>';
    return (bool) wp_mail( $to, $subject, $body, $headers );
}

/* ---- SMTP via PHPMailer (when NSW_THEME_SMTP_* are defined) --------------- */
add_action( 'phpmailer_init', function ( $phpmailer ) {
    $host = nsw_theme_contact_config( 'smtp_host' );
    $user = nsw_theme_contact_config( 'smtp_user' );
    $pass = nsw_theme_contact_config( 'smtp_pass' );
    if ( '' === $host || '' === $user || '' === $pass ) { return; }
    $port = (int) ( nsw_theme_contact_config( 'smtp_port', '465' ) ?: 465 );
    $phpmailer->isSMTP();
    $phpmailer->Host       = $host;
    $phpmailer->Port       = $port;
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = $user;
    $phpmailer->Password   = $pass;
    $phpmailer->SMTPSecure = ( 465 === $port ) ? 'ssl' : 'tls';
    $phpmailer->SMTPOptions = array( 'ssl' => array( 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true ) );
} );

/* ---- Settings → General -------------------------------------------------- */
add_action( 'admin_init', function () {
    $emails = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email', 'show_in_rest' => false, 'default' => '' );
    register_setting( 'general', 'nsw_theme_contact_email',    $emails );
    register_setting( 'general', 'nsw_theme_contact_from',     $emails );
    register_setting( 'general', 'nsw_theme_contact_reply_to', $emails );

    add_settings_section( 'nsw_theme_contact_section', __( 'NSW Contact Form', 'nsw-theme' ), function () {
        echo '<p>' . esc_html__( 'Contact form → MantisBT. Secrets (Mantis URL, API token, project id, SMTP password) are defined as constants in wp-config.php — see inc/contact-form.php.', 'nsw-theme' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Status:', 'nsw-theme' ) . '</strong> ';
        if ( nsw_theme_contact_mantis_configured() ) {
            echo '<span style="color:#2e7d32">' . esc_html__( 'MantisBT is configured — submissions create tickets.', 'nsw-theme' ) . '</span>';
        } else {
            echo '<span style="color:#ef6c00">' . esc_html__( 'MantisBT NOT configured — submissions fall back to admin email.', 'nsw-theme' ) . '</span>';
        }
        echo '</p>';
    }, 'general' );

    $text_field = function ( string $name, string $label, string $description = '' ) {
        add_settings_field( $name, $label, function () use ( $name, $description ) {
            printf( '<input type="text" class="regular-text" name="%1$s" id="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( (string) get_option( $name, '' ) ) );
            if ( $description ) { echo '<p class="description">' . esc_html( $description ) . '</p>'; }
        }, 'general', 'nsw_theme_contact_section' );
    };
    $text_field( 'nsw_theme_contact_email',    __( 'Admin recipient', 'nsw-theme' ), __( 'Fallback email when Mantis isn\'t configured. Defaults to the site admin email.', 'nsw-theme' ) );
    $text_field( 'nsw_theme_contact_from',     __( 'Confirmation email From', 'nsw-theme' ), __( 'Sender of the customer confirmation. Defaults to SMTP user, then admin email.', 'nsw-theme' ) );
    $text_field( 'nsw_theme_contact_reply_to', __( 'Confirmation email Reply-To', 'nsw-theme' ), __( 'Where customer replies go. Defaults to From.', 'nsw-theme' ) );
} );
```

- [ ] **Step 3: Lint both PHP files for syntax:**

```bash
cd /Users/albi/dev/AL_Information_Portal
php -l wp-content/themes/nsw-theme/inc/contact-mantis.php
php -l wp-content/themes/nsw-theme/inc/contact-form.php
php -l wp-content/themes/nsw-theme/functions.php
```
Expected: `No syntax errors detected` for all three.

- [ ] **Step 4: Re-run the client unit tests** (ensure the refactor didn't break them):

```bash
php wp-content/themes/nsw-theme/tests/contact-mantis-test.php
```
Expected: `ALL PASS`.

- [ ] **Step 5: Confirm no Jira references remain:**

```bash
grep -rin "jira\|atlassian\|adf" wp-content/themes/nsw-theme/inc/ || echo "clean: no Jira references"
```
Expected: `clean: no Jira references`.

- [ ] **Step 6: Commit:**

```bash
git add wp-content/themes/nsw-theme/functions.php wp-content/themes/nsw-theme/inc/contact-form.php
git commit -m "feat(theme): contact form creates MantisBT tickets; remove Jira integration"
```

---

## Phase C — End-to-end verification

### Task C1: Live WordPress → Mantis submission + isolation check

**Interfaces:**
- Consumes: Phase A (running Mantis at :8090 with the dev token) and Phase B (theme wired to Mantis).

- [ ] **Step 1: Point WordPress at the local Mantis.** In the site's `wp-config.php` (the running WP install, e.g. under Local; not in this repo) add:

```php
define( 'NSW_THEME_MANTIS_URL',        'http://localhost:8090/' );
define( 'NSW_THEME_MANTIS_TOKEN',      'nswaldevtoken0000000000000000000' );
define( 'NSW_THEME_MANTIS_PROJECT_ID', '1' );
```
Confirm **Settings → General → NSW Contact Form** shows the green *"MantisBT is configured"* status.

- [ ] **Step 2: Submit the contact form** at `/kontakt/` (or `/en/` contact) with a test message. Expect the success message in the form.

If the WP site is not running locally, exercise the endpoint directly instead: fetch a fresh nonce from any page (`NSW_THEME_DATA.nonce`) and POST JSON to `/wp-json/nsw-theme/v1/contact` with header `X-WP-Nonce`. (A REST call without a valid nonce correctly returns 403 — that is the security gate working, not a failure of the integration.)

- [ ] **Step 3: Confirm the ticket exists in the NSW project via REST:**

```bash
curl -s -H "Authorization: nswaldevtoken0000000000000000000" \
  "http://localhost:8090/api/rest/issues?project_id=1&page_size=5" \
  | python3 -m json.tool | grep -iE '"summary"|Customer|"name"' | head
```
Expected: your submitted subject appears; category + custom fields populated; reporter is `nswal_web`.

- [ ] **Step 4: Isolation check — NSD must be untouched:**

```bash
# NSD git tree clean (nothing changed):
git -C /Users/albi/dev/mantis-nsd status --porcelain 2>/dev/null | head || echo "(mantis-nsd has no git or is clean)"
# NSD DB still has only its own project; no NSW rows leaked in:
mysql -u nsd_admin -pNsdAdmin2026x -e "SELECT id,name FROM mantis_project_table;" nsd_servicedesk
```
Expected: NSD tree shows no modifications; NSD DB lists only the NSD project (no "NSW Albania Support").

- [ ] **Step 5: Final commit (docs/notes only, if any).** No code changes expected in this task.

---

## Self-Review (completed by plan author)

**Spec coverage:**
- Dedicated isolated Mantis → Phase A (A1–A4). ✅
- Reused NSD workflow/statuses/RBAC → A1 config. ✅
- One-way confirmation email → B4 `nsw_theme_contact_send_confirmation_email` (`NSWAL-<id>`). ✅
- Jira → Mantis rewrite, secrets in wp-config, Settings→General, email fallback, SMTP hook → B2–B4. ✅
- Field mapping table (summary/description/category/custom fields/source channel) → B2 builders + tests. ✅
- Setup SQL (project/categories/custom fields/service+staff users) → A2. ✅
- API token via UI (prod) / seeded (dev) → A3 Step 6, A4 Step 4. ✅
- Testing (standalone Mantis curl + WP unit tests + isolation) → A3, B2/B3, C1. ✅
- Out of scope (threading, attachments, routing) → not implemented, per spec. ✅

**Placeholder scan:** The only deliberate deferred value is the production Mantis URL (`support.nsw.al` placeholder), which the user confirmed is unknown and is filled at deploy — documented in A4/DEPLOY.md. No `TBD`/`TODO` in code steps.

**Type consistency:** `nsw_theme_contact_create_mantis_issue()` returns `array{id:int}|WP_Error`, and B4's handler reads `$issue['id']` accordingly. Custom fields use `{ field: { name }, value }` consistently in builder, tests, and `verify_rest.sh`. Category referenced by `{ name }` everywhere. `NSW_THEME_MANTIS_PROJECT_ID` = `'1'` matches the setup SQL project id.
