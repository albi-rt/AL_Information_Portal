# Running the NSW Albania theme locally

Use **Local by Flywheel** — free, GUI, real LAMP stack. Do **not** use wp-now; it's a WASM sandbox with SQLite and doesn't match production behaviour.

## One-time setup

1. **Create a site in Local.** Pick PHP 8.0+, Apache, MySQL 8.
2. **Junction the theme into the site.** From an elevated PowerShell or `cmd`:
   ```cmd
   cd "%USERPROFILE%\Local Sites\<site-name>\app\public\wp-content\themes"
   mklink /J nsw-al "C:\Users\LENOVO\Documents\nsw\wp-theme\nsw-al"
   ```
   On macOS/Linux, use a symlink: `ln -s /path/to/repo/wp-theme/nsw-al .`
3. **Point the importer at the repo.** Open the site's `wp-config.php` and add:
   ```php
   define( 'NSW_THEME_LEGACY_DIR', 'C:/Users/LENOVO/Documents/nsw' );
   ```
   (The repo root — `inc/data.php` reads `content/news/{sq,en}/*.json` from there.)
4. **Activate the theme + run setup.** In wp-admin:
   - **Appearance → Themes** → activate **NSW Albania**.
   - **Settings → Permalinks** → click **Save Changes** (refreshes rewrite rules so the CPT archive URL works).
   - **Tools → NSW Setup** → click **Run import**. This creates the static pages, wires them to the right templates, sets the front page, and imports the news articles.

## Daily workflow

Click **Start site** in Local. That's it — Apache serves the theme live. No `npm run dev`, no build step. CSS/JS edits hit refresh; PHP edits hit refresh.

## Toggling languages without Polylang

The header has an SQ / EN toggle. Clicking writes a `nsw_theme_lang` cookie and redirects back to the current page; subsequent requests are served in that locale. Translations come from `languages/sq-translations.php` (UI literals) and `assets/data/content-{sq,en}.json` (page copy).

## Adding Polylang

1. **Plugins → Add New** → install + activate **Polylang**.
2. **Languages** → add Albanian (sq) and English (en); set Albanian as default.
3. **Tools → NSW Setup** → click **Link Polylang sq ↔ en pairs** to wire up news translations.
4. Polylang's own language switcher takes over; the theme's i18n filters automatically step aside.

## Production deployment

- **Theme**: same files, minus the `tools/` directory and minus the `require NSW_THEME_DIR . 'inc/import-admin.php';` line in `functions.php`.
- **Content**: either run the importer once with a temporary `NSW_THEME_LEGACY_DIR` pointing at an uploaded copy of the source, then remove the constant + import-admin require; or migrate pages via Tools → Export from Local and Tools → Import on production.
- **Contact form**: set the recipient under Settings → General → "NSW contact form recipient".
