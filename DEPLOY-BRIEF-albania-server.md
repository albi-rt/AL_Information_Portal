# Deployment brief: Albania on the new server

Handover for a fresh session. Everything below was learned the hard way on the existing
AL and MK deployments. Read it before touching the server.

## Target

| | |
|---|---|
| Country | **Albania only** |
| Install type | **Fresh**, not a migration. No existing data moves across. |
| Status | Treat as **production** (not finally confirmed) |
| Server | New, specs not yet known. Inspect before planning anything. |
| Domain | Comes later. Deploy against IP or a temporary hostname first. |

Two things to deploy:

1. **Mantis service desk** (support desk, from `mantis-nswal`, Albania config)
2. **Information Portal** (WordPress, from `AL_Information_Portal`)

## First steps on the server

Do this before writing any plan, because the plan depends on it.

```bash
cat /etc/os-release ; nproc ; free -h ; df -h /
php -v ; nginx -v 2>&1 || apache2 -v 2>&1 ; mysql --version
which plesk >/dev/null && echo "Plesk present" || echo "no control panel"
id ; sudo -n true 2>/dev/null && echo "sudo OK" || echo "no passwordless sudo"
```

Record the answers in this file. The RFP sets no hardware specification, so the server is
constrained by outcomes instead: `NSW_PER_0001` average page load under 2 seconds, and
`NSW_PER_0002` at least 1000 concurrent users. `NSW_ACC_0010` requires the application to
work behind an HTTP reverse proxy.

## Access

Use the existing SSH key at `~/.ssh/id_ed25519.pub` rather than a password. Add it to the
server's `authorized_keys`. Any password pasted into a chat should be treated as burned and
rotated afterwards.

## Mantis: what bit us before

1. **Plesk drops a placeholder `index.html`** that shadows Mantis's `index.php`. The site
   shows a parking page while `/login_page.php` works fine. Delete the `index.html`.
2. **Leave the installer's "Admin Username/Password (to create Database)" blank** when the
   database already exists. Filling them makes Mantis try to create it again.
3. **Keep the table prefix `mantis` and suffix `_table`.** `sql/nsw_setup.sql` references
   `mantis_project_table` and friends. Change them and the setup script fails.
4. **API tokens must be exactly 32 characters.** Mantis rejects any other length with 401
   before it even looks the token up. Tokens minted in the UI are always 32.
5. **Projects must be `private`.** A public project is visible to every logged-in user
   regardless of membership, which leaks the whole central queue to agency staff.
6. **Set `$g_send_reset_password = OFF`.** Otherwise Mantis emails a set-password link when
   an account is created, which is useless with notifications off or placeholder addresses.
7. **The Add-User form defaults to `reporter`.** Leaving it there cripples the account
   silently, because with `$g_limit_reporters = ON` a reporter sees only its own tickets.
8. **Private subprojects are invisible through the parent.** Central triage must be a member
   of the parent *and* of every agency subproject or it cannot route tickets.
9. **Email is a performance trap.** With `$g_email_send_using_cronjob = OFF` Mantis sends
   every notification inline, one SMTP session per recipient. Against a remote mail host
   that makes status changes and assignment take several seconds. Either leave notifications
   off, or set `= ON` and run `scripts/send_emails.php` from cron every minute.
10. If running the installer against a local PHP server, use `PHP_CLI_SERVER_WORKERS=8`.
    The single-threaded built-in server deadlocks mid-install.

Albania values: database `nswal_servicedesk`, user `nswal_admin`, project
"NSW Albania Support" (id 1), accounts `nswal_web` / `nswal_officer` /
`nswal_supervisor`. Starter password in `sql/nsw_setup.sql`; change it before go-live.

Read `mantis-nswal/DEPLOY.md` and `mantis-nswal/WORKFLOW.md` for the full procedure and the
agency routing model.

## Information Portal: what bit us before

1. **Reset PHP opcache after every PHP change.** In Plesk that is PHP Settings then Apply.
   Without it the old code keeps running and you will debug a file that is not executing.
2. **Bump `NSW_THEME_VERSION`** whenever `main.css` or `theme.json` changes. The stylesheet
   is cache-busted by that constant, so browsers keep the old CSS otherwise.
3. **Custom post type capabilities are granted to nobody, including Administrator.**
   `nsw_document`, `nsw_event` and `nsw_service` declare custom `capability_type` values and
   nothing in the theme grants the matching caps. `inc/roles.php` is referenced by the README
   but does not exist. Until the caps are granted (Members plugin, or a new `roles.php`), no
   one can create Documents, Events or Services. News works because it is native WordPress
   Posts.
4. **Plugins are not in the repository.** Only the theme is versioned. The install needs at
   least Polylang (Albanian and English) and Post Views Counter (the FR29 visit counter).
   The README also names PublishPress Revisions for editorial approval.
5. **Logo SVGs need explicit `width` and `height`.** A viewBox-only SVG collapses to 0x0
   inside the flex header on Chrome and Safari.
6. **The contact form needs three constants in `wp-config.php`:**
   `NSW_THEME_MANTIS_URL`, `NSW_THEME_MANTIS_TOKEN`, `NSW_THEME_MANTIS_PROJECT_ID`.
   Without them the form fails with no useful message.
7. **Plesk's WP-CLI console strips pipes, semicolons and quotes.** Run single `wp` commands
   there, or use a real shell.

Current live AL theme version is 0.11.0. Platform on the old host: WordPress 7.1.2,
PHP 8.2.33, MariaDB 10.11.14, nginx.

## Order of work

1. Inspect the server and record what it is
2. Install the stack (web server, PHP, database) if it is bare
3. Mantis first: database, files, config, installer, `nsw_setup.sql`, API token
4. Information Portal: database, WordPress, theme, plugins, `wp-config.php` constants
5. Wire the contact form to Mantis and verify a real ticket is created end to end
6. TLS and the domain, once the hostname is decided
7. Verify against `NSW_PER_0001` and `NSW_PER_0002` before calling it production

## Open items

- Server specification, unknown until inspected
- Hostname and DNS, decided later
- AL SMTP: the `info@nsw.al` mailbox password stopped working and needs resetting at the
  mail provider before notifications can be turned on
- Whether this host is the AKSHI production environment or an interim one
- `strings_albanian.txt` is machine translated and still needs a native review
