# Self-hosted fonts

The main stylesheet references two variable font files that **need to be downloaded** into this directory before going live. Until they land here, the browser will fall back to system fonts (Segoe UI / Helvetica / etc.) — the site will still render, just without the brand typography.

- `InterVariable.woff2` — Inter Variable (regular through bold).
  Source: https://github.com/rsms/inter/releases (download `Inter.zip`, take `web/InterVariable.woff2`).
- `SourceSerif4Variable.woff2` — Source Serif 4 Variable.
  Source: https://fonts.google.com/specimen/Source+Serif+4 (or https://github.com/adobe-fonts/source-serif).

Both are SIL Open Font License — fine for redistribution. Don't link Google Fonts CDN: it's a GDPR liability for an EU government site (it sends IP addresses to Google).
