# NSW Albania — WordPress theme

Custom WordPress theme for the Albanian National Single Window (Dritarja e Vetme Kombëtare). This is a WordPress-only project: all editorial content lives in the database and is managed in native wp-admin.

## Architecture

```
wp-content/themes/nsw-al/
├── style.css                 Theme header
├── functions.php             Bootstrap: requires every inc/* file
├── header.php, footer.php    Site chrome
├── front-page.php            Homepage composition
├── page.php, page-wide.php   Default + wide page templates
├── archive-nsw_news.php      News archive
├── single-nsw_news.php       News article view
├── 404.php, index.php
├── page-templates/
│   ├── page-about.php        Template: About
│   ├── page-how-it-works.php Template: How It Works
│   ├── page-agencies.php     Template: Agencies (nsw_agency CPT)
│   ├── page-partners.php     Template: Partners (nsw_partner CPT)
│   ├── page-faq.php          Template: FAQ (nsw_faq CPT)
│   ├── page-documents.php    Template: Documents (nsw_document CPT)
│   ├── page-events.php       Template: Events (nsw_event CPT)
│   ├── page-contact.php      Template: Contact (form → REST)
│   └── page-support.php      Template: Support
├── template-parts/
│   ├── sections/             Homepage strips: hero, stats, agencies, news, partners, cta
│   └── cards/                Reusable card markup
├── inc/
│   ├── setup.php             theme_supports, menus, image sizes
│   ├── enqueue.php           CSS/JS + wp_localize_script
│   ├── i18n.php              Cookie + Polylang i18n
│   ├── data.php              DB content accessors + asset URL helper
│   ├── template-tags.php     Hero/card render helpers + path-URL mapping
│   ├── post-types.php        CPTs (news/event/document/agency/partner/faq) + taxonomies
│   ├── taxonomies.php        nsw_news_category + seed terms
│   ├── meta-boxes.php        Per-CPT fields (locale, event/doc/agency/partner details)
│   ├── roles.php             Agency + Content Editor roles (approval workflow)
│   ├── admin.php             Settings → contact-form recipient
│   └── contact-form.php      REST POST /nsw-theme/v1/contact (honeypot + rate limit)
├── assets/
│   ├── css/main.css          Single stylesheet with design tokens at :root
│   ├── js/main.js            Vanilla JS (no React)
│   ├── fonts/                Self-hosted (Inter Variable + Source Serif 4)
│   ├── images/               Logos, hero bg, agencies, partners
│   └── video/hero.mp4
├── languages/
│   ├── sq-translations.php   Flat 'English' => 'Albanian' map (cookie mode)
│   └── content-{sq,en}.php   Nested UI microcopy map (used by nsw_theme_t)
└── theme.json                WP design tokens (palette + fonts)
```

## i18n strategy

Two operating modes, picked automatically:

- **Cookie mode (default).** `?setlang=sq|en` sets a `nsw_theme_lang` cookie; subsequent requests are served in that locale. The `gettext`, `the_title`, `single_post_title`, `post_type_archive_title`, and `wp_nav_menu_objects` filters look up English strings in `languages/sq-translations.php`. `convert_chars()`-encoded ampersands are decoded before lookup (gotcha #1 from the playbook).
- **Polylang mode.** If Polylang is active (`pll_current_language` exists), all of the above is skipped — Polylang owns its own switcher and per-language post scoping.

UI microcopy comes from `languages/content-{sq,en}.php` (nested PHP maps). The `nsw_theme_t( 'hero.title', 'Transforming Albanian Trade' )` helper resolves dotted keys through that map first, then falls back to the cookie-mode flat map, then to the hard-coded English literal.

## Database content

The Agencies, Partners, FAQ, Documents, Events and News pages read from the database via custom post types, edited in wp-admin:

| Page | CPT | Bilingual model |
|---|---|---|
| Agencies | `nsw_agency` | both locales on one post (meta) |
| Partners | `nsw_partner` | both locales on one post (meta) |
| Events | `nsw_event` | one post per locale (`_nsw_theme_locale`) |
| Documents | `nsw_document` | one post per locale |
| FAQ | `nsw_faq` | one post per locale; category taxonomy |
| News | `nsw_news` | one post per locale; public archive |

Bilingual meta (agencies/partners) is assembled by `nsw_theme_*_post_to_array()` into a `{ sq, en }` shape; `nsw_theme_localized()` returns the active locale's value with `sq → en → first` fallback. Per-locale CPTs carry `_nsw_theme_locale` meta and are filtered to the active language on the read path (skipped under Polylang).

## News (CPT)

`nsw_news` is a public CPT with archive at `/lajme/` (sq) and `/news/` (en) — the rewrite slug is locale-aware. Editors manage articles via wp-admin → News. Each post gets `_nsw_theme_locale = 'sq'|'en'` meta; in cookie-mode, `pre_get_posts` filters the archive query by locale. With Polylang installed the filter is skipped.

All content (news, events, documents, agencies, partners, FAQ) lives in the database and is managed in wp-admin. There is no JSON content layer; UI microcopy lives in `languages/content-{locale}.php`.

## Editorial approval (NSW-83)

Changes to published **pages** and **news** by non-administrators are held for review using the **[PublishPress Revisions](https://wordpress.org/plugins/revisionary/)** plugin (`revisionary`) — a required dependency (install + activate; it auto-enables the `nsw_*` CPTs).

Three roles (`inc/roles.php`, applied on `after_switch_theme`):

| Role | News / Events / Documents | Pages | Approves |
|---|---|---|---|
| **Agency Officer** (`nsw_agency_officer`) | authors; edits become **pending revisions** | — | no |
| **Content Editor** (`nsw_editor`) | full editor — publishes + approves others' revisions | edits become **pending revisions** | news: yes · pages: no |
| **Administrator** | full | full | everything |

The mechanism hinges on one capability: PublishPress treats a user holding `edit_published_{type}_posts` as a *full editor* whose edits go live directly. Moderated tiers therefore **lack** `edit_published_*` (and `publish_*`); they create pending revisions through the plugin's "New Revision" flow. Approvers hold the full published-edit set plus `edit_others_revisions` + `manage_unsubmitted_revisions`. Pending revisions are reviewed (with a diff) and approved under wp-admin → Revisions.

## Contact form

`POST /wp-json/nsw-theme/v1/contact` — vanilla `<form>` + `fetch()`, `X-WP-Nonce` header, honeypot (`<input name="website">`), 30s per-IP rate limit via transient, recipient configurable under Settings → General. File inputs are stripped from the JSON payload before submission (gotcha #8).

## Before going to production

- Install + activate the **PublishPress Revisions** plugin (required for the approval workflow — see above).
- Configure the contact-form recipient under Settings → General → "NSW contact form recipient".
- Install + activate Polylang for full multilingual (URL prefixes, hreflang, sitemap).
