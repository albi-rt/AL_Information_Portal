# NSW Albania — Production Deploy Runbook (Plesk)

Deploy = **WordPress install + theme/plugin code + database + uploads**. The code
is in this repo; the content lives in the database. Local users are NOT exported —
production keeps its own Plesk-created admin account.

- Repo: this repository (`wp-content/themes/nsw-theme` + `wp-content/plugins/nsw-importer`; WP core is gitignored)
- **Database export & uploads travel separately** (not in git): the users-excluded DB dump
  `nswal-PROD-<hash>.sql.gz` and the `wp-content/uploads/` folder (~19 MB) come from the
  local machine's `backups/` folder / Local site.

> Menu labels vary slightly by Plesk version; paths below are for a recent Plesk Obsidian with **WordPress Toolkit**.

---

## 1. Install WordPress (WordPress Toolkit)
Plesk → **Websites & Domains** → your domain → **WordPress** (or the *WordPress Toolkit* card) → **Install**.
- Set the site title, and **create the production admin** (its own login + email — this is the account you keep).
- Toolkit auto-creates the database and `wp-config.php`. **Leave that wp-config alone** (its DB creds + salts are correct for this server).

## 2. Set PHP 8.0+
Plesk → domain → **PHP Settings** → choose **PHP 8.0** or newer (the theme requires 8.0). Apply.

## 3. Install the plugins
WordPress Toolkit → your site → **Plugins** → **Install** → from wordpress.org:
- **Polylang** (required — the whole bilingual system depends on it), then **Members**, **Post Views Counter**, **Revisionary**.
- Activate all four.

## 4. Deploy the theme + importer code
**Option A — Git (Plesk Git feature):**
Plesk → domain → **Git** → **Add Repository** →
- Remote URL: `https://github.com/albi-rt/AL_Information_Portal.git`
- Deployment path: the site's document root (e.g. `httpdocs`)
- Deploy mode: Manual (or Automatic).
Then **Pull / Deploy**. Git only writes tracked files, so it adds `wp-content/themes/nsw-theme` and `wp-content/plugins/nsw-importer` **on top of** the WP install without touching core.
(This also drops `DEPLOY.md` into the webroot — harmless, no secrets — delete it from prod or block `*.md` if you prefer it not be public.)

**Option B — Upload (simpler for a one-off; nothing extra lands in the webroot):**
Plesk → domain → **Files** (File Manager) or SFTP → upload these two folders into `httpdocs/wp-content/`:
- `themes/nsw-theme/`  ·  `plugins/nsw-importer/`

Then WordPress Toolkit → **Themes** → activate **NSW Theme**.
> **Do NOT run "Tools → NSW Setup"** — that importer is a stale one-time bootstrap. The DB import (step 5) is the source of truth.

## 5. Import the database
The export already **excludes `wp_users`/`wp_usermeta`**, so your Plesk admin survives the import.

**Via phpMyAdmin (easiest):** WordPress Toolkit → your site → **Database** → **phpMyAdmin** → select the site's DB → **Import** tab → upload `nswal-PROD-<hash>.sql.gz` (phpMyAdmin reads .gz directly) → Go.

**Or via SSH/WP-CLI:**
```bash
gunzip nswal-PROD-<hash>.sql.gz
wp db import nswal-PROD-<hash>.sql          # run from the docroot
```

## 6. Search-replace the domain (serialization-safe — REQUIRED)
Polylang/block data is serialized, so this must NOT be a raw SQL find/replace.

**WP-CLI (SSH into the site, `cd` to the docroot):**
```bash
wp search-replace 'nswal.local' 'YOUR-PROD-DOMAIN' --all-tables --report-changes-only
```
> Plesk's WordPress Toolkit provides WP-CLI. If SSH is disabled, install the **"Better Search Replace"** plugin and run the same replace from wp-admin (it's serialization-safe).

Also confirm **Settings → General** shows the prod domain for WordPress Address / Site Address (search-replace normally fixes both).

## 7. Upload the media
Copy `wp-content/uploads/` (the ~19 MB folder) into `httpdocs/wp-content/uploads/` via **File Manager** or SFTP. (Media files are not in git or the DB.)

## 8. SSL
Plesk → domain → **SSL/TLS Certificates** → **Install** a free **Let's Encrypt** certificate (tick www + the domain). Enable "Redirect from HTTP to HTTPS".

## 9. Flush permalinks
WordPress admin → **Settings → Permalinks → Save Changes** (regenerates `.htaccess`, makes the CPT/page routes resolve). Or `wp rewrite flush`.

## 10. Post-deploy checks
- Home in both languages: `/` (sq) and `/en/`.
- Data pages: `/agjencite/` + `/en/agencies/`, `/partneret/`, `/dokumenta/`, `/ngjarje/`, `/pyetjet-e-shpeshta/`, `/lajme/`, `/kontakt/`.
- **Language switcher** (SQ | EN, active = purple) works and switches to the translated page.
- Agencies/Partners show **logos** (uploads copied) in the correct language.
- Contact form submits.
- wp-admin: Agencies/Partners show as **sq/en translation pairs** (Polylang column); Languages → Strings has the Albanian.

---

## Notes & gotchas
- **wp-config.php**: Plesk's install owns it — never overwrite with the local file.
- **Authors**: posts are authored by user ID 1 → the Plesk admin (fresh installs make admin = ID 1, so it lines up). If not, authorship shows blank — cosmetic; the theme mostly hides author.
- **Admin CPT labels** (Agencies/Partners/FAQ/Documents) appear in English in the SQ dashboard by design (ui-en.php was removed) — front end unaffected.
- **Nginx + Apache**: Plesk usually proxies nginx→Apache; permalinks work via the Apache `.htaccess` that "Save Permalinks" writes. No manual config needed.
- **Future content changes on prod** are edited directly in prod wp-admin. To refresh a **staging/local** copy from prod later, use WordPress Toolkit's **Clone**, which does its own serialization-safe search-replace.
- **Re-exporting from local** (if you make more local content changes before launch): rerun the users-excluded dump — `mysqldump ... --ignore-table=DB.wp_users --ignore-table=DB.wp_usermeta` — or `backups/dump-db.sh` for a full local backup.
