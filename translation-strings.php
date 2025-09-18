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
use function ksort;
use function range;
use function sort;
use function sprintf;
use function strpos;
use function strtolower;
use function trim;

class TranslationStringsPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
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

    public function onPluginsInitialized(): void
    {
        $this->loadCustomTranslations();

        if ($this->isAdmin()) {
            $this->enable([
                'onAdminSave' => ['onAdminSave', 0],
            ]);
        }
    }

    private function loadCustomTranslations(): void
    {
        $languages = $this->grav['languages'];
        $configured = $this->config->get('plugins.translation-strings.languages', []);
        $normalized = $this->normalizeLanguages($configured, false);

        foreach ($normalized as $code => $content) {
            if ($content) {
                $languages->mergeRecursive([$code => $content]);
            }
        }
    }


    public function onAdminSave(Event $event): void
    {
        $object = $event['object'];

        if (!$object instanceof Data) {
            return;
        }

        $blueprints = $object->blueprints();
        if (!$blueprints || $blueprints->getFilename() !== 'plugins/translation-strings') {
            return;
        }

        $languages = $object->get('languages') ?? [];
        $normalized = $this->normalizeLanguages($languages, true);

        $object->set('languages', $normalized);
        $this->config->set('plugins.translation-strings.languages', $normalized);

        $this->loadCustomTranslations();
    }

    private function normalizeLanguages($input, bool $notifyAdmin): array
    {
        $admin = $notifyAdmin ? ($this->grav['admin'] ?? null) : null;
        $normalized = [];

        if (!is_array($input)) {
            return $normalized;
        }

        if ($this->isAssociative($input)) {
            foreach ($input as $code => $content) {
                $this->ingestLanguageEntry($normalized, (string)$code, $content, $admin);
            }
        } else {
            foreach ($input as $language) {
                if (!is_array($language)) {
                    continue;
                }

                $code = $language['code'] ?? '';
                $content = $language['content'] ?? [];
                $this->ingestLanguageEntry($normalized, (string)$code, $content, $admin);
            }
        }

        ksort($normalized);

        return $normalized;
    }

    private function ingestLanguageEntry(array &$normalized, string $code, $content, $admin = null): void
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return;
        }

        if (is_string($content)) {
            $content = trim($content);
            if ($content === '') {
                $normalized[$code] = [];
                return;
            }

            try {
                $content = Yaml::parse($content) ?? [];
            } catch (Throwable $exception) {
                if ($admin) {
                    $admin->setMessage(sprintf('Translation strings: failed to parse YAML for %s (%s)', $code, $exception->getMessage()), 'error');
                }

                return;
            }
        }

        if ($content === null) {
            $normalized[$code] = [];

            return;
        }

        if (!is_array($content)) {
            if ($admin) {
                $admin->setMessage(sprintf('Translation strings: the YAML for %s must be a map of keys.', $code), 'error');
            }

            return;
        }

        $normalized[$code] = $content;
    }

    private function formatLanguagesForForm(array $normalized): array
    {
        $list = [];

        foreach ($normalized as $code => $content) {
            $list[] = [
                'code' => $code,
                'content' => $content ? Yaml::dump($content, 10, 2) : '',
            ];
        }

        return $list;
    }

    private function isAssociative(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
