# RedaQuest Connector

WordPress plugin that connects a site to [RedaQuest](https://app.redaquest.com) and adds an AI Blog Writer plus one-click social scheduling to the block editor.

- **Connect** a site to a RedaQuest workspace (OAuth-style, the token stays server-side, no key to paste).
- **Blog Writer** in the editor: topic, audience (personas from your brand manual), outline, then a full GEO article with FAQ, SEO meta and optional cover and per-section images.
- **Social**: turn a published article into scheduled social posts via RedaQuest.

Requires a RedaQuest account. The plugin talks to the RedaQuest API and (for social publishing) Zernio. See `readme.txt` for the user-facing description and the external-services disclosure.

## Develop / build

Requires Node 20+.

```bash
npm install        # install build tooling (@wordpress/scripts)
npm run start      # watch/dev build
npm run build      # production build -> build/index.js
npm run plugin-zip # build a distributable redaquest-connector.zip
```

The Gutenberg app source is in `src/`; the build output (`build/`) is generated and git-ignored. PHP lives in `redaquest-connector.php` + `includes/`. Styles in `assets/`. Translations in `languages/`.

## Translations (i18n)

Base language is English; the plugin ships with `sk`, `cs`, `de`, `hu`, `pl`. Both PHP
(`__()`/`_e()`) and JS (`@wordpress/i18n` `__()`) strings use the `redaquest-connector`
text domain. Strings are localized in two layers: `.mo` files for PHP and per-script
`.json` files for the Gutenberg editor. The compiled `.mo`/`.json` are committed so
translations work in any install, including CI builds that have no WP-CLI.

```bash
npm run i18n   # make-pot -> update-po -> make-mo -> make-json (+ rename to handle name)
```

Workflow after touching any user-facing string:

1. `npm run i18n` regenerates `languages/redaquest-connector.pot` and merges new strings
   into every `languages/*.po` (existing translations are kept).
2. Translate the new empty `msgstr` entries in the `.po` files (sk/cs/de/hu/pl).
3. Run `npm run i18n` again to recompile `.mo` and `.json`, then commit `languages/`.

The JS `.json` files are renamed to `redaquest-connector-<locale>-redaquest-editor.json`
(the `wp_set_script_translations` handle) on purpose: WordPress resolves that name first,
independent of the install path, which the default md5-of-source-path name is not.

Requires WP-CLI (`wp`) and gettext (`msgfmt`) on PATH.

## Release / publish to WordPress.org

Push a tag (e.g. `v3.0.0`); the GitHub Actions workflow builds the plugin and attaches a ready-to-upload `redaquest-connector.zip` to the release. Upload that build to the WordPress.org SVN.

## License

GPL-2.0-or-later. See `LICENSE`.
