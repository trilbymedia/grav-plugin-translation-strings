<?php

namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Common\Yaml;
use RocketTheme\Toolbox\Event\Event;
use Throwable;
use function array_filter;
use function array_map;
use function array_keys;
use function array_unique;
use function count;
use function is_array;
use function is_string;
use function range;
use function sort;
use function sprintf;
use function strpos;
use function strtolower;
use function trim;

class TranslationStringsPlugin extends Plugin
{
    /** @var array|null Pending save data for post-save YAML rewrite */
    private $_pendingSave = null;
    /** @var string|null File path for the pending save */
    private $_pendingSavePath = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            // Load custom translations after theme languages are loaded
            'onThemeInitialized' => ['onThemeInitialized', -1000],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            $this->enable([
                'onAdminSave' => ['onAdminSave', 0],
                'onAdminAfterSave' => ['onAdminAfterSave', 0],
                'onAssetsInitialized' => ['onAssetsInitialized', 0],
            ]);
        }
    }

    /**
     * Inject JS on the plugin config page that syncs each language list entry's
     * code select with a data-ai-translate-lang attribute, enabling per-section
     * AI translation with the correct target language.
     */
    public function onAssetsInitialized(): void
    {
        $uri = $this->grav['uri'];
        $path = $uri->path();

        // Only load on this plugin's config page
        if (!$path || strpos($path, '/admin/plugins/translation-strings') === false) {
            return;
        }

        $this->grav['assets']->addInlineJs("
            (function() {
                function syncLangAttributes() {
                    document.querySelectorAll('[data-collection-holder] > [data-collection-item]').forEach(function(item) {
                        var select = item.querySelector('select[name$=\"[code]\"]');
                        if (select && select.value) {
                            item.setAttribute('data-ai-translate-lang', select.value);
                        }
                        if (select) {
                            select.removeEventListener('change', onCodeChange);
                            select.addEventListener('change', onCodeChange);
                        }
                    });
                }
                function onCodeChange(e) {
                    var item = e.target.closest('[data-collection-item]');
                    if (item) {
                        item.setAttribute('data-ai-translate-lang', e.target.value);
                    }
                }
                // Run on load and observe for dynamically added list items
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', syncLangAttributes);
                } else {
                    syncLangAttributes();
                }
                var observer = new MutationObserver(function() { syncLangAttributes(); });
                var target = document.getElementById('admin-main') || document.body;
                observer.observe(target, { childList: true, subtree: true });
            })();
        ", ['group' => 'bottom']);
    }

    public static function languageOptions(): array
    {
        $grav = Grav::instance();
        $config = $grav['config'] ?? null;
        $language = $grav['language'];

        $codes = [];
        if ($config) {
            foreach ($language->getLanguages() as $code) {
                $codes[] = (string)$code;
            }

            $codes = array_merge($codes, static::extractLanguageCodes($config->get('plugins.translation-strings.languages')));
        }

        $codes = array_map(static fn($code) => (string)$code, $codes);
        $codes = array_filter(array_unique($codes));

        if (!$codes) {
            $codes = array_keys(LanguageCodes::getList(false));
        }

        sort($codes, SORT_STRING);

        return array_combine($codes, $codes);
    }

    private static function extractLanguageCodes($value): array
    {
        $codes = [];

        if (!is_array($value)) {
            return $codes;
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            foreach ($value as $code => $content) {
                $code = strtolower(trim((string)$code));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }

            return $codes;
        }

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $code = strtolower(trim((string)($entry['code'] ?? '')));
            if ($code === '') {
                continue;
            }

            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Load custom translations after theme languages are loaded.
     * Only merges the active language and its base fallback for performance.
     */
    public function onThemeInitialized(): void
    {
        $this->loadCustomTranslations();
    }

    private function loadCustomTranslations(): void
    {
        $languages = $this->grav['languages'];
        $configured = $this->config->get('plugins.translation-strings.languages', []);

        if (!is_array($configured) || empty($configured)) {
            return;
        }

        // Build an associative map of all configured translations
        $translationMap = $this->buildTranslationMap($configured);

        if (empty($translationMap)) {
            return;
        }

        // Determine which language codes to merge (active + fallback chain)
        $langService = $this->grav['language'];
        $active = $langService->getActive() ?: $this->config->get('system.languages.default_lang', 'en');
        $active = strtolower((string)$active);

        $codesToMerge = [$active];

        // Add base language as fallback (e.g., 'fr' for 'fr-fr')
        if (strpos($active, '-') !== false) {
            $codesToMerge[] = substr($active, 0, strpos($active, '-'));
        }

        // Always include the default language for fallback
        $defaultLang = strtolower((string)$this->config->get('system.languages.default_lang', 'en'));
        if (!in_array($defaultLang, $codesToMerge, true)) {
            $codesToMerge[] = $defaultLang;
        }

        // Merge only the needed languages
        foreach ($codesToMerge as $code) {
            if (isset($translationMap[$code]) && !empty($translationMap[$code])) {
                $languages->mergeRecursive([$code => $translationMap[$code]]);
            }
        }
    }

    /**
     * Build an associative map from the configured languages.
     * Handles both list format [{code, content}] and associative format {code: content}.
     */
    private function buildTranslationMap(array $input): array
    {
        $map = [];

        if ($this->isAssociative($input)) {
            foreach ($input as $code => $content) {
                $code = strtolower(trim((string)$code));
                if ($code !== '' && is_array($content)) {
                    $map[$code] = $content;
                }
            }
        } else {
            foreach ($input as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $code = strtolower(trim((string)($entry['code'] ?? '')));
                $content = $entry['content'] ?? [];

                if ($code === '') {
                    continue;
                }

                // Content may be a YAML string (from CodeMirror) or already parsed array
                if (is_string($content)) {
                    $content = trim($content);
                    if ($content === '') {
                        continue;
                    }
                    try {
                        $content = Yaml::parse($content) ?? [];
                    } catch (Throwable $e) {
                        continue;
                    }
                }

                if (is_array($content) && !empty($content)) {
                    $map[$code] = $content;
                }
            }
        }

        return $map;
    }

    /**
     * On admin save, validate entries but KEEP the list format [{code, content}]
     * to prevent corruption of the data structure.
     *
     * Previously this converted to associative format which broke the list blueprint
     * field on subsequent admin loads, causing translations to leak between languages.
     */
    public function onAdminSave(Event $event): void
    {
        $object = $event['object'];

        if (!$object instanceof Data) {
            return;
        }

        $blueprints = $object->blueprints();
        $bpFile = $blueprints ? $blueprints->getFilename() : '';
        if (!$blueprints || strpos($bpFile, 'translation-strings') === false) {
            return;
        }

        $languages = $object->get('languages') ?? [];

        if (!is_array($languages)) {
            return;
        }

        $admin = $this->grav['admin'] ?? null;
        $cleaned = [];
        $seenCodes = [];

        // If data is already in associative format (from a previous buggy save),
        // convert it back to list format
        if ($this->isAssociative($languages)) {
            $list = [];
            foreach ($languages as $code => $content) {
                $list[] = ['code' => (string)$code, 'content' => $content];
            }
            $languages = $list;
        }

        foreach ($languages as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $code = strtolower(trim((string)($entry['code'] ?? '')));
            if ($code === '') {
                continue;
            }

            // Skip duplicate language codes
            if (isset($seenCodes[$code])) {
                if ($admin) {
                    $admin->setMessage(sprintf('Translation strings: duplicate language code "%s" removed.', $code), 'warning');
                }
                continue;
            }
            $seenCodes[$code] = true;

            $content = $entry['content'] ?? [];

            // Parse YAML string if needed (from CodeMirror editor)
            if (is_string($content)) {
                $content = trim($content);
                if ($content === '') {
                    $cleaned[] = ['code' => $code, 'content' => []];
                    continue;
                }

                try {
                    $content = Yaml::parse($content) ?? [];
                } catch (Throwable $exception) {
                    if ($admin) {
                        $admin->setMessage(sprintf('Translation strings: failed to parse YAML for %s (%s)', $code, $exception->getMessage()), 'error');
                    }
                    continue;
                }
            }

            if ($content === null) {
                $content = [];
            }

            if (!is_array($content)) {
                if ($admin) {
                    $admin->setMessage(sprintf('Translation strings: the content for %s must be a YAML map.', $code), 'error');
                }
                continue;
            }

            $cleaned[] = ['code' => $code, 'content' => $content];
        }

        // Save in list format — this preserves compatibility with the type: list blueprint
        $object->set('languages', $cleaned);
        $this->config->set('plugins.translation-strings.languages', $cleaned);

        // Store for onAdminAfterSave to rewrite with expanded YAML
        $this->_pendingSave = $cleaned;

        // Get the actual file path Grav will save to (respects environment overrides)
        $file = $object->file();
        $this->_pendingSavePath = $file ? $file->filename() : null;

        // Reload translations into Grav's language system
        $this->loadCustomTranslations();
    }

    /**
     * After Grav saves the config file (with its default inline depth),
     * rewrite it with fully expanded YAML to prevent inline { } flow maps.
     */
    public function onAdminAfterSave(Event $event): void
    {
        if ($this->_pendingSave === null) {
            return;
        }

        $cleaned = $this->_pendingSave;
        $filePath = $this->_pendingSavePath;
        $this->_pendingSave = null;
        $this->_pendingSavePath = null;

        if ($filePath) {
            $this->saveConfigExpanded($cleaned, $filePath);
        }
    }

    /**
     * Write the plugin config file with a high inline depth so all nested
     * structures are fully expanded (no inline { } flow maps).
     */
    private function saveConfigExpanded(array $languages, string $filePath): void
    {
        try {
            $data = [
                'enabled' => (bool)$this->config->get('plugins.translation-strings.enabled', true),
                'languages' => $languages,
            ];

            // Inline depth 20 = fully expand all nested structures
            $yaml = Yaml::dump($data, 20, 2);
            file_put_contents($filePath, $yaml);

            // Clear Grav's compiled config cache so it picks up the new file
            $locator = $this->grav['locator'];
            $cacheDir = $locator->findResource('cache://compiled/config', true, true);
            if ($cacheDir && is_dir($cacheDir)) {
                $files = glob($cacheDir . '/*.php');
                if ($files) {
                    foreach ($files as $f) {
                        @unlink($f);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->grav['log']->error('Translation Strings: Failed to save expanded config', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function isAssociative(array $array): bool
    {
        if (empty($array)) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
