# Translation Strings Plugin

> **Grav 2.0 has this built in.** Admin 2.1 ships a **Translations** section that does everything this plugin does and adds search across every string on the site, per-language coverage, machine translation, and a report of keys nothing provides any more. It stores overrides as ordinary language files under `user/languages/`, which Grav reads natively.
>
> This plugin remains supported on **Grav 1.7**. If you are on 2.0, open **Translations** in the admin: it detects the snippets stored here, offers to import them, and then offers to disable this plugin. Nothing is deleted, so the move is reversible. There is also a CLI equivalent: `bin/plugin api i18n:migrate --dry-run`.
>
> Keeping both active on 2.0 is not recommended. This plugin merges its strings *after* the built-in editor does, so it silently wins any key set in both places.

The **Translation Strings** plugin lets you manage per-language YAML snippets inside Grav Admin. Each snippet is saved to `user/config/plugins/translation-strings.yaml` and merged into Grav’s translation service on save, so Twig’s `|t` filter and `$language->translate()` can use the keys immediately.

## Installation

Install like any other Grav plugin.

### GPM (preferred)

```bash
bin/gpm install translation-strings
```

### Manual

1. Download the plugin and unzip it in `user/plugins`.
2. Ensure the folder is named `translation-strings`.

### Admin

If you use the Admin plugin, open **Plugins → Add** and search for “Translation Strings”.

## Configuration

The default configuration lives in `user/plugins/translation-strings/translation-strings.yaml`:

```yaml
enabled: true
languages: []
```

To customize, copy it to `user/config/plugins/translation-strings.yaml` and edit. Each language entry accepts a code and YAML content:

```yaml
languages:
  - code: en
    content: |
      MY_PLUGIN:
        GREETING: "Hello"
        CTA: "Read More"
```

YAML indentation matters—use spaces, not tabs.

## Usage

Once saved, translations can be retrieved anywhere Grav expects a language key.

**Twig**
```twig
{{ 'MY_PLUGIN.GREETING'|t }}
```

**PHP**
```php
$this->grav['language']->translate('MY_PLUGIN.CTA');
```

Fallback behaviour follows your `system.languages` configuration.

## Admin Tips

- Add one item per language you want to manage.
- Leave the content blank to remove a language from the plugin config.
- The editor accepts any YAML hierarchy—use standard Grav naming conventions for keys.

## Troubleshooting

- **Translation not found?** Verify the key and check for YAML syntax errors.
- **Language missing?** Ensure the code is listed under `system.languages.supported` or save it once via the plugin form.

## License

MIT License © Trilby Media
