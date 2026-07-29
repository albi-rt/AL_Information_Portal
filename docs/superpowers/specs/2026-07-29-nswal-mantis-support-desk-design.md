# nsw.al Support Desk on a Dedicated MantisBT — Design

**Date:** 2026-07-29
**Status:** Approved (design); pending implementation plan
**Repos / trees involved:**
- `AL_Information_Portal` (this repo) — the nsw.al WordPress site (`wp-content/themes/nsw-theme`)
- `/Users/albi/dev/mantis-nsd` — existing **MantisBT 2.28.0** install for a *different* project (NSD / NCTS). **Read-only template. Never modified.**
- `/Users/albi/dev/mantis-nswal` — **new**, to be created: the dedicated Mantis install for nsw.al

---

## 1. Problem & goal

The nsw.al Information Portal has a Contact page whose form currently posts to a WordPress
REST endpoint that creates **Jira** issues (the file was copied "1:1" from the Macedonian
sister project). We want the Contact form to create tickets in **MantisBT** instead, in a
**new, dedicated Mantis** that is fully isolated from the existing NSD Mantis.

End state: a citizen submits the Contact form → a ticket is created in the nsw.al Mantis →
the citizen receives a confirmation email with a ticket reference → nsw.al staff work the
ticket in Mantis.

## 2. Decisions (locked with the user)

1. **Separate, dedicated MantisBT install** for nsw.al (own DB, config, URL, branding) —
   *not* a new project inside the NSD install. Guarantees NSD is untouched and gives nsw.al
   its own identity. Rationale: Mantis statuses, workflow, priorities, and branding are
   **installation-wide**, so a shared install could not isolate nsw.al from NSD.
2. **Reuse the NSD service-desk workflow** — statuses `new → in_progress → escalated /
   pending_user → resolved → closed`, the NSD priorities, and reporter-only RBAC — but
   re-branded for nsw.al with its own categories.
3. **One-way confirmation email for v1.** WordPress creates the ticket and emails the
   customer a confirmation with the ticket reference. Customer replies go to `info@nsw.al`
   (Reply-To) and are handled manually. Two-way email threading is deferred (phase 2).

## 3. Architecture / data flow

```
Contact page ──POST JSON──▶ WP REST: nsw-theme/v1/contact
   security gates (unchanged): wp_rest nonce · honeypot(website) · per-IP 30s rate limit
                               · sanitize · length caps · required-field validation
        │
        │  if Mantis configured (URL + token + project id all present):
        ├─▶ POST {MANTIS_URL}api/rest/issues            header: Authorization: <api-token>
        │       project   = { id: <NSW project id> }
        │       category  = mapped from form category (fallback: "General inquiry")
        │       summary   = subject
        │       description = "From: Name <email>" + org/agency meta + message body
        │       custom_fields = Customer Name, Customer Email, Organization,
        │                       Relevant Agency, Source Channel="Portal (Web Contact Form)"
        │       ◀── 201 { issue: { id } }
        │
        ├─▶ confirmation email to customer  (subject: "[NSWAL-<id>] We've received your message")
        │       non-fatal: a failed email does not fail the request (ticket already exists)
        │
        │  else (Mantis not configured — demo/staging):
        └─▶ admin-notification email to the fallback recipient  (unchanged behavior)
```

The reporter of every ticket is the **service account** (`nswal_web`), exactly as the Jira
version used the API user as reporter. The customer's identity lives in custom fields and in
the description's first line, so no per-citizen Mantis accounts are created.

---

## 4. Deliverable A — the nsw.al Mantis install (`/Users/albi/dev/mantis-nswal`)

Built the same reproducible way NSD was (`mantis-nsd/CLAUDE.md`): **no core file edits**;
everything via `config/` overrides + a setup SQL script.

### 4.1 Base tree
A pristine **MantisBT 2.28.0** tree (same version as NSD). Source: the existing
`mantis-nsd-source-2026-04-23.zip` if it is verified pristine upstream; otherwise a fresh
2.28.0 download, or a copy of the NSD tree with all NSD-specific overrides removed
(`config/*`, `plugins/NSD`, `sql/*`, `custom_*`). The chosen source must be confirmed during
the plan step. NSD's tree is only read, never changed.

### 4.2 `config/config_inc.php` (nsw.al)
- **Database:** own DB `nswal_servicedesk` with its own DB user/password (local dev values;
  production values filled at deploy).
- **Path:** `$g_path` = local dev URL (e.g. `http://localhost:8090/`); production URL filled
  at deploy (placeholder: the support host for nsw.al, e.g. `https://support.nsw.al/` —
  **to be confirmed by the user at deploy time**).
- **REST:** `$g_webservice_rest_enabled = ON`.
- **Workflow (reused from NSD):** `$g_status_enum_string`, `$g_status_enum_workflow`,
  status colors/icons, `$g_bug_submit_status = NSWAL_NEW`, priorities
  (`10:low,20:medium,30:high,40:critical`, default medium), reporter-only RBAC
  (`$g_limit_reporters = ON`, thresholds mirroring NSD), notify flags.
- **Branding:** `$g_window_title = 'NSW Albania — Support'`, `$g_from_name`, `$g_from_email
  = info@nsw.al`, favicon/logo; SMTP block (placeholders, filled at deploy).
- **Signup/anon:** `$g_allow_signup = OFF`, `$g_allow_anonymous_login = OFF`.

### 4.3 `config/custom_constants_inc.php` + `custom_strings_inc.php`
`NSWAL_*` status constants (`NSWAL_NEW=10 … NSWAL_CLOSED=90`) and their display labels —
mirrors NSD's `custom_constants_inc.php` / `custom_strings_inc.php`.

### 4.4 `sql/nswal_setup.sql` (idempotent — mirrors `nsd_setup.sql`)
Run after `admin/install.php` builds the schema.

- **Project:** `INSERT IGNORE mantis_project_table` → `"NSW Albania Support"` with a **known
  `id = 1`** (so it matches `NSW_THEME_MANTIS_PROJECT_ID`), `inherit_global = 0` (so it does
  not inherit the global "General" category); delete the default global General category as
  NSD does.
- **Categories (7), enabled (status=1):** `General inquiry`, `LPCO process`, `Registration`,
  `Payment`, `Technical issue`, `Feedback`, `Other` — matching the Contact form's
  `contactPage.form.categoryOptions` / the label map in `inc/contact-form.php`.
- **Custom fields (5):** `Customer Name` (string), `Customer Email` (string),
  `Organization` (string), `Relevant Agency` (string), `Source Channel`
  (enum: `Portal|Email|Phone`, default Portal). Linked to the project via
  `mantis_custom_field_project_table` with a display sequence.
- **Users:** a **service API account** `nswal_web` (REPORTER-level, used only by the
  WordPress integration) plus a small set of starter staff accounts (officer/supervisor/
  admin), all assigned to the project via `mantis_project_user_list_table`. (Starter staff
  are convenience seed data; production can replace them.)

### 4.5 API token
Mantis stores API tokens **hashed**; the plaintext is shown once at creation and cannot be
seeded cleanly via SQL. So the token is created **after** setup, via the Mantis UI
(`api_token_create.php`) while signed in as `nswal_web` (or via admin impersonation), then
pasted into `wp-config.php` as `NSW_THEME_MANTIS_TOKEN`. This is a documented runbook step.

### 4.6 `mantis-nswal/DEPLOY.md`
NSD/Plesk-style runbook: create DB + DB user → run `admin/install.php` → run
`sql/nswal_setup.sql` → sign in and create the `nswal_web` API token → set the three
`NSW_THEME_MANTIS_*` constants in the site's `wp-config.php` → verify a live ticket via curl,
then via the Contact form. Includes both local-dev and Plesk-production variants.

---

## 5. Deliverable B — WordPress `wp-content/themes/nsw-theme/inc/contact-form.php`

Rewrite the integration layer from Jira → Mantis. **Keep** the structure that already works:
config resolver (constants override options so secrets stay out of the DB), the security
gates, the `phpmailer_init` SMTP hook, the customer confirmation email, and the email-only
fallback. **Remove** the Jira-specific code (ADF builder, Jira create-issue, Jira settings) —
the Albania portal does not use Jira.

### 5.1 New/changed functions
- `nsw_theme_contact_mantis_configured()` — true when URL + token + project id are all set.
- `nsw_theme_contact_create_mantis_issue( array $data )` —
  `wp_remote_post( rtrim($url,'/') . '/api/rest/issues' )` with headers
  `Authorization: <token>`, `Content-Type: application/json`; body per §5.3; returns the
  numeric `issue.id` or a `WP_Error`.
- `nsw_theme_contact_build_description( array $data )` — plain-text (Mantis default text
  format) description whose **first line** is `From: <Name> <email>` (preserves the agent-
  facing "who to reply to" convention from the Jira version), followed by Organization /
  Category / Agency meta lines, then the message body.
- `nsw_theme_contact_send_confirmation_email()` — unchanged logic; the reference becomes
  `NSWAL-<id>` (Mantis has numeric ids, not Jira keys); subject
  `"[NSWAL-<id>] We've received your message"`. (Purely a human reference — no threading in v1.)
- Category → Mantis category name map reused from the existing `..._category_labels()`.

### 5.2 Configuration
**Secrets — `wp-config.php` constants (never the DB):**
```php
define( 'NSW_THEME_MANTIS_URL',        'https://support.nsw.al/' ); // base; REST at {URL}api/rest/
define( 'NSW_THEME_MANTIS_TOKEN',      '<mantis api token for nswal_web>' );
define( 'NSW_THEME_MANTIS_PROJECT_ID', '1' );
// SMTP constants stay as-is (NSW_THEME_SMTP_*).
```
**Non-secret — Settings → General** (replace the Jira fields):
Mantis base URL (display/override), project id, custom-field IDs for Customer Name / Customer
Email / Organization / Relevant Agency / Source Channel, confirmation From, confirmation
Reply-To, admin fallback recipient, and a live **"Mantis configured / not configured"**
status line.

### 5.3 Field mapping (form → Mantis)

| Form field      | Mantis destination                                             |
|-----------------|----------------------------------------------------------------|
| `subject`       | `summary`                                                      |
| `message`       | `description` body (after the `From:`/meta header lines)       |
| `category`      | `category.name` (mapped via label map; fallback "General inquiry") |
| `fullName`      | custom field **Customer Name** + `From:` line                  |
| `email`         | custom field **Customer Email** + `From:` line                 |
| `organization`  | custom field **Organization** (+ meta line if present)         |
| `agency`        | custom field **Relevant Agency** — mapped label if the slug is known, else the raw submitted value (the dropdown is built dynamically from `nsw_agency` CPTs, so slugs may fall outside the static agency map) |
| (constant)      | custom field **Source Channel** = `Portal`                     |
| `website`       | honeypot — accepted-and-discarded (unchanged)                  |

The front-end template (`template-parts/sections/contact-form.php`) and `main.js` are
**unchanged** — the field set and submit path stay the same.

---

## 6. Testing

- **Mantis, standalone:** create a scratch DB, run the installer + `nswal_setup.sql`, serve
  the tree with `php -S localhost:8090 -t /Users/albi/dev/mantis-nswal` (port 8080 is held by
  an unrelated Java process), create the `nswal_web` token, then `curl -X POST
  .../api/rest/issues` with the token and assert a ticket is created in the NSW project with
  category + custom fields populated.
- **WordPress handler:** verify `nsw_theme_contact_create_mantis_issue()` builds the correct
  payload and that the handler returns success on 201, a `WP_Error` on non-2xx/transport
  error, and falls back to the admin email when the Mantis constants are absent. Exercise via
  a live form submission if the Local WP site is running, otherwise via a direct REST call to
  `nsw-theme/v1/contact` with a valid nonce.
- **Isolation check:** confirm NSD's DB (`nsd_servicedesk`) and tree are untouched throughout.

## 7. Out of scope for v1 (YAGNI)

- Two-way email threading (inbound customer replies → Mantis notes; agent notes → customer
  email). NSD ships an `EmailReporting` plugin, so this is feasible later.
- File attachments (the form's file input is already a no-op — `JSON.stringify` drops the
  `File` — so v1 preserves current behavior).
- Per-agency routing/assignment rules.

## 8. Assumptions / open items for deploy

- **Production Mantis URL/host** for nsw.al is a placeholder (`https://support.nsw.al/`) until
  the user confirms where Mantis will live in production.
- Starter category list is the seven above; easy to adjust before running the setup SQL.
- Local DB credentials for `nswal_servicedesk` are dev-only; production creds are set on the
  server and never committed.
