<?php
/**
 * Enterprise JSON-based Internationalization
 *
 * Load one JSON file per locale. No .po/.mo compilation needed.
 * Add a language = drop a JSON file in languages/.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_I18n {

    /** @var array Loaded strings for current locale */
    private static $strings = [];

    /** @var array English fallback strings */
    private static $fallback = [];

    /** @var string Resolved locale */
    private static $locale = 'en_US';

    /** @var array Cache of secondary locale loads (for emails in customer locale) */
    private static $locale_cache = [];

    /**
     * Initialize: determine locale and load JSON files.
     * Must be called before any other Bookflow class.
     */
    public static function init() {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        self::$locale = apply_filters('bookflow_locale', $locale);

        // Always load English as fallback
        self::$fallback = self::load_file('en_US');

        if (self::$locale !== 'en_US') {
            self::$strings = self::load_file(self::$locale);
        } else {
            self::$strings = self::$fallback;
        }

        // Cache current locale
        self::$locale_cache['en_US'] = self::$fallback;
        self::$locale_cache[self::$locale] = self::$strings;
    }

    /**
     * Translate a key, with optional sprintf args.
     *
     * @param string $key   Dot-notation key (e.g. 'calendar.select_date')
     * @param mixed  ...$args Optional sprintf arguments
     * @return string
     */
    public static function t($key, ...$args) {
        $str = self::$strings[$key] ?? self::$fallback[$key] ?? $key;
        if (!empty($args)) {
            $str = sprintf($str, ...$args);
        }
        return $str;
    }

    /**
     * The resolved locale for the current request (e.g. 'ro_RO') — used by
     * the Widget Builder's per-locale text overrides to know which
     * override set to merge in.
     */
    public static function current_locale() {
        return self::$locale;
    }

    /**
     * Echo an escaped translation.
     */
    public static function te($key, ...$args) {
        echo esc_html(self::t($key, ...$args));
    }

    /**
     * Translate in a specific locale (for emails sent in customer's language).
     *
     * @param string $key    Dot-notation key
     * @param string $locale Target locale (e.g. 'ro_RO')
     * @param mixed  ...$args Optional sprintf arguments
     * @return string
     */
    public static function t_locale($key, $locale, ...$args) {
        if (!isset(self::$locale_cache[$locale])) {
            self::$locale_cache[$locale] = self::load_file($locale);
        }
        $strings = self::$locale_cache[$locale];
        $str = $strings[$key] ?? self::$fallback[$key] ?? $key;
        if (!empty($args)) {
            $str = sprintf($str, ...$args);
        }
        return $str;
    }

    /**
     * Get translated booking status label.
     *
     * @param string $slug Status slug (e.g. 'in-progress')
     * @return string
     */
    public static function status($slug) {
        $key = 'status.' . str_replace('-', '_', $slug);
        return self::t($key);
    }

    /**
     * Get status label in a specific locale.
     */
    public static function status_locale($slug, $locale) {
        $key = 'status.' . str_replace('-', '_', $slug);
        return self::t_locale($key, $locale);
    }

    /**
     * Get all translated statuses as slug => label array.
     *
     * @return array
     */
    public static function statuses() {
        $slugs = ['pending', 'confirmed', 'partially-paid', 'paid', 'in-progress', 'completed', 'cancelled', 'refunded', 'no-show'];
        $result = [];
        foreach ($slugs as $slug) {
            $result[$slug] = self::status($slug);
        }
        return $result;
    }

    /**
     * Get all strings (current locale merged over fallback).
     * Useful for passing to JS.
     *
     * @return array
     */
    public static function get_all() {
        return array_merge(self::$fallback, self::$strings);
    }

    /**
     * Get all keys starting with a prefix.
     *
     * @param string $prefix e.g. 'calendar.'
     * @return array
     */
    public static function get_section($prefix) {
        $all = self::get_all();
        $result = [];
        $len = strlen($prefix);
        foreach ($all as $key => $value) {
            if (strncmp($key, $prefix, $len) === 0) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Get current locale.
     *
     * @return string
     */
    public static function get_locale() {
        return self::$locale;
    }

    /**
     * Get list of available locales (based on JSON files present).
     *
     * @return array ['en_US' => 'English', 'ro_RO' => 'Română', ...]
     */
    public static function get_available_locales() {
        $dir = BOOKFLOW_PLUGIN_DIR . 'languages/';
        $locales = [];
        foreach (glob($dir . '*.json') as $file) {
            $locale = basename($file, '.json');
            $data = self::load_file($locale);
            $locales[$locale] = $data['_locale_name'] ?? $locale;
        }
        return $locales;
    }

    /**
     * Load a JSON translation file.
     *
     * @param string $locale Locale code (e.g. 'ro_RO')
     * @return array
     */
    private static function load_file($locale) {
        $file = BOOKFLOW_PLUGIN_DIR . 'languages/' . sanitize_file_name($locale) . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $json = file_get_contents($file);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
