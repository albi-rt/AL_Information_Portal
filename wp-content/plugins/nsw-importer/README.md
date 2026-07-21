# NSW Importer

One-time seeder for the Albanian National Single Window site. It loads the
content that used to live in the Next.js `content/*.json` files into WordPress,
so from then on **everything is managed in wp-admin** — no JSON, no hardcoded
data.

## What it creates

| Data | Where it lands | Translatable? |
|------|----------------|---------------|
| 14 agencies, 4 partners | `nsw_agency` / `nsw_partner` CPTs (bilingual meta on one post) | **No** — language-neutral, shown in both languages |
| 12 documents, 4 events, 29 FAQs, 6 news | `nsw_document` / `nsw_event` / `nsw_faq` / `nsw_news` CPTs, one post per locale | Yes — sq ↔ en linked in Polylang |
| Pages (Home, About, How It Works, Agencies, Partners, FAQ, Documents, Events, Contact, Support) | real WP Pages wired to the theme's page templates | Yes — sq ↔ en linked |
| Navigation | Primary + two footer menus, **per language**, assigned to theme locations (edit in Appearance → Menus) | — |
| Agency / partner logos | Media Library (featured images, sideloaded from the theme's `assets/images/`) | — |
| UI microcopy | Polylang → Languages → **Strings** (255 strings, seeded Albanian) | Yes |

It also configures Polylang: languages **sq (default) + en**, makes only the
per-locale CPTs translatable, and sets `redirect_lang` so the English home is
served at `/en/`.

## How to run

1. Activate the **NSW Theme** (Appearance → Themes).
2. Install & activate **Polylang** (Plugins → Add New).
3. Activate this plugin, then go to **Tools → NSW Setup** and click **Run import**.
4. Visit **Settings → Permalinks** and Save once (flush rewrite rules).

The import is **idempotent** — every record carries a `_nsw_theme_import_source`
meta key, so re-running updates in place instead of duplicating. Tick **Clean
first** to wipe previously imported posts before re-importing.

## After the initial seed

This plugin is only needed for the one-time seed. Once the content is in and
verified, **deactivate it** — the theme reads everything from the database on
its own. The bundled `data/` JSON is kept so the import can be re-run on another
environment (e.g. staging → production).

## Notes

- Content source: `data/` (copied from the Next.js project's `content/` tree).
- Requires the **NSW Theme** active (it reads the theme's page templates and the
  `content-en.php` / `content-sq.php` string catalogs when seeding Polylang
  strings) and **Polylang** active for the bilingual wiring.
