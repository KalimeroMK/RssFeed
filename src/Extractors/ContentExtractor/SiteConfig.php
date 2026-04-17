<?php

declare(strict_types=1);

/**
 * Site Config
 *
 * @version 1.1
 *
 * @date 2017-09-25
 *
 * @author Keyvan Minoukadeh
 * @copyright 2017 Keyvan Minoukadeh
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPL v3
 */

namespace Kalimeromk\Rssfeed\Extractors\ContentExtractor;

use Illuminate\Support\Facades\Cache;

class SiteConfig
{
    public const HOSTNAME_REGEX = '/^(([a-zA-Z0-9-]*[a-zA-Z0-9])\.)*([A-Za-z0-9-]*[A-Za-z0-9])$/';

    public array $title = [];

    public array $body = [];

    public array $author = [];

    public array $date = [];

    public array $strip = [];

    public array $strip_id_or_class = [];

    public array $strip_image_src = [];

    public array $native_ad_clue = [];

    public array $http_header = [];

    public ?bool $tidy = null;

    public ?bool $skip_json_ld = null;

    public ?bool $autodetect_on_failure = null;

    public ?bool $prune = null;

    public array $test_url = [];

    public array $test_contains = [];

    public array $if_page_contains = [];

    public array $single_page_link = [];

    public array $next_page_link = [];

    public array $single_page_link_in_feed = [];

    public ?string $parser = null;

    public ?bool $insert_detected_image = null;

    public array $find_string = [];

    public array $replace_string = [];

    public static bool $debug = false;

    protected bool $default_tidy = true;

    protected bool $default_skip_json_ld = false;

    protected bool $default_autodetect_on_failure = true;

    protected bool $default_prune = true;

    protected string $default_parser = 'html5php';

    protected bool $default_insert_detected_image = true;

    protected static ?string $config_path_custom = null;

    protected static ?string $config_path_fallback = null;

    /** @var array<string, SiteConfig> */
    protected static array $config_cache = [];

    public static function use_apc(bool $apc = true): bool
    {
        // APC caching is deprecated; Laravel Cache is used automatically.
        return false;
    }

    public static function set_config_path(string $path, ?string $fallback = null): void
    {
        self::$config_path_custom = $path;
        self::$config_path_fallback = $fallback;
    }

    public static function add_to_cache(string $key, self $config, bool $use_apc = true): void
    {
        $key = mb_strtolower($key);
        if (mb_substr($key, 0, 4) === 'www.') {
            $key = mb_substr($key, 4);
        }
        self::$config_cache[$key] = $config;
        $cacheKey = 'rssfeed_siteconfig_'.$key;
        Cache::put($cacheKey, $config, 86400);
        self::debug("Cached site config with key $key");
    }

    public static function load_cached(string $key): self|false
    {
        $key = mb_strtolower($key);
        if (mb_substr($key, 0, 4) === 'www.') {
            $key = mb_substr($key, 4);
        }
        if (array_key_exists($key, self::$config_cache)) {
            self::debug("... site config for $key already loaded in this request");

            return self::$config_cache[$key];
        }
        $cacheKey = 'rssfeed_siteconfig_'.$key;
        $sconfig = Cache::get($cacheKey);
        if ($sconfig instanceof self) {
            self::debug("... site config for $key found in Laravel cache");
            self::$config_cache[$key] = $sconfig;

            return $sconfig;
        }

        return false;
    }

    public static function is_cached(string $key): bool
    {
        $key = mb_strtolower($key);
        if (mb_substr($key, 0, 4) === 'www.') {
            $key = mb_substr($key, 4);
        }
        if (array_key_exists($key, self::$config_cache)) {
            return true;
        }
        $cacheKey = 'rssfeed_siteconfig_'.$key;

        return Cache::has($cacheKey);
    }

    public static function build(string $host, bool $exact_host_match = false): self|false
    {
        $host = mb_strtolower($host);
        if (mb_substr($host, 0, 4) === 'www.') {
            $host = mb_substr($host, 4);
        }
        if (! $host || (mb_strlen($host) > 200) || ! preg_match(self::HOSTNAME_REGEX, mb_ltrim($host, '.'))) {
            return false;
        }
        $config = self::load_cached_merged($host, $exact_host_match);
        if ($config) {
            return $config;
        }
        $try = [$host];
        if (! $exact_host_match) {
            $split = explode('.', $host);
            if (count($split) > 1) {
                array_shift($split);
                $try[] = '.'.implode('.', $split);
            }
        }

        self::debug(". looking for site config for $host in custom folder");
        $config = null;
        $config_std = null;
        foreach ($try as $h) {
            $h_key = $h.'.custom';
            if ($config = self::load_cached($h_key)) {
                break;
            }
            if (file_exists(self::$config_path_custom.'/'.$h.'.txt')) {
                self::debug("... found site config ($h.txt)");
                $file_custom = self::$config_path_custom.'/'.$h.'.txt';
                $config = self::build_from_file($file_custom);
                break;
            }
        }

        if ($config && ! $config->autodetect_on_failure()) {
            self::debug('... autodetect on failure is disabled (no other site config files will be loaded)');
            self::add_to_cache_merged($host, $exact_host_match, $config);

            return $config;
        }

        if (isset(self::$config_path_fallback)) {
            self::debug(". looking for site config for $host in standard folder");
            foreach ($try as $h) {
                if ($config_std = self::load_cached($h)) {
                    break;
                }
                if (file_exists(self::$config_path_fallback.'/'.$h.'.txt')) {
                    self::debug("... found site config in standard folder ($h.txt)");
                    $file_secondary = self::$config_path_fallback.'/'.$h.'.txt';
                    $config_std = self::build_from_file($file_secondary);
                    break;
                }
            }
        }

        if (! $config && ! $config_std) {
            self::debug("... no site config match for $host");
            self::add_to_cache_merged($host, $exact_host_match);

            return false;
        }

        $config_final = null;
        if (! $config_std) {
            $config_final = $config;
        } elseif ($config) {
            self::debug('. merging config files');
            $config->append($config_std);
            $config_final = $config;
        } else {
            $config_final = $config_std;
        }

        self::add_to_cache_merged($host, $exact_host_match, $config_final);

        return $config_final;
    }

    public static function build_from_file(string $path, bool $cache = true): self|false
    {
        $key = basename($path, '.txt');
        $config_lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $config_lines) {
            return false;
        }
        $config = self::build_from_array($config_lines);
        if ($cache) {
            self::add_to_cache($key, $config);
        }

        return $config;
    }

    public static function build_from_string(string $string): self
    {
        $config_lines = explode("\n", $string);

        return self::build_from_array($config_lines);
    }

    /**
     * @param  array<int, string>  $lines
     */
    public static function build_from_array(array $lines): self
    {
        $config = new self;
        foreach ($lines as $line) {
            $line = mb_trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $command = explode(':', $line, 2);
            if (count($command) !== 2) {
                continue;
            }
            $val = mb_trim($command[1]);
            $command = mb_trim($command[0]);
            if ($command === '') {
                continue;
            }

            if ($command === 'strip_attr') {
                $command = 'strip';
            }

            if (in_array($command, [
                'title',
                'body',
                'author',
                'date',
                'strip',
                'strip_id_or_class',
                'strip_image_src',
                'single_page_link',
                'single_page_link_in_feed',
                'next_page_link',
                'native_ad_clue',
                'http_header',
                'test_url',
                'find_string',
                'replace_string',
            ])) {
                $config->$command[] = $val;
            } elseif (in_array($command,
                ['tidy', 'prune', 'autodetect_on_failure', 'insert_detected_image', 'skip_json_ld'])) {
                $config->$command = ($val === 'yes');
            } elseif (in_array($command, ['parser'])) {
                $config->$command = $val;
            } elseif (in_array($command, ['test_contains'])) {
                $config->add_test_contains($val);
            } elseif (in_array($command, ['if_page_contains'])) {
                $config->add_if_page_contains_condition($val);
            } elseif ((mb_substr($command, -1) === ')') && preg_match('!^([a-z0-9_]+)\((.*?)\)$!i', $command, $match)) {
                if (in_array($match[1], ['replace_string'])) {
                    $config->find_string[] = $match[2];
                    $config->replace_string[] = $val;
                } elseif (in_array($match[1], ['http_header'])) {
                    $_header = mb_strtolower(mb_trim($match[2]));
                    $config->http_header[$_header] = $val;
                }
            }
        }

        return $config;
    }

    public function insert_detected_image(bool $use_default = true): ?bool
    {
        if ($use_default) {
            return $this->insert_detected_image ?? $this->default_insert_detected_image;
        }

        return $this->insert_detected_image;
    }

    public function tidy(bool $use_default = true): ?bool
    {
        if ($use_default) {
            return $this->tidy ?? $this->default_tidy;
        }

        return $this->tidy;
    }

    public function skip_json_ld(bool $use_default = true): ?bool
    {
        if ($use_default) {
            return $this->skip_json_ld ?? $this->default_skip_json_ld;
        }

        return $this->skip_json_ld;
    }

    public function prune(bool $use_default = true): ?bool
    {
        if ($use_default) {
            return $this->prune ?? $this->default_prune;
        }

        return $this->prune;
    }

    public function parser(bool $use_default = true): ?string
    {
        if ($use_default) {
            return $this->parser ?? $this->default_parser;
        }

        return $this->parser;
    }

    public function autodetect_on_failure(bool $use_default = true): ?bool
    {
        if ($use_default) {
            return $this->autodetect_on_failure ?? $this->default_autodetect_on_failure;
        }

        return $this->autodetect_on_failure;
    }

    public function append(self $newconfig): void
    {
        foreach (
            [
                'title',
                'body',
                'author',
                'date',
                'strip',
                'strip_id_or_class',
                'strip_image_src',
                'single_page_link',
                'single_page_link_in_feed',
                'next_page_link',
                'native_ad_clue',
            ] as $var
        ) {
            $this->$var = array_unique(array_merge($this->$var, $newconfig->$var));
        }
        foreach (['http_header'] as $var) {
            $this->$var = array_merge($newconfig->$var, $this->$var);
        }
        foreach (['single_page_link'] as $var) {
            if (isset($this->if_page_contains[$var]) && isset($newconfig->if_page_contains[$var])) {
                $this->if_page_contains[$var] = array_merge($newconfig->if_page_contains[$var],
                    $this->if_page_contains[$var]);
            } elseif (isset($newconfig->if_page_contains[$var])) {
                $this->if_page_contains[$var] = $newconfig->if_page_contains[$var];
            }
        }
        foreach (
            [
                'tidy',
                'prune',
                'parser',
                'autodetect_on_failure',
                'insert_detected_image',
                'skip_json_ld',
            ] as $var
        ) {
            if ($this->$var === null) {
                $this->$var = $newconfig->$var;
            }
        }
        foreach (['find_string', 'replace_string'] as $var) {
            $this->$var = array_merge($this->$var, $newconfig->$var);
        }
    }

    public function add_test_contains(string $test_contains): void
    {
        if (! empty($this->test_url)) {
            $key = end($this->test_url);
            reset($this->test_url);
            if (isset($this->test_contains[$key])) {
                $this->test_contains[$key][] = $test_contains;
            } else {
                $this->test_contains[$key] = [$test_contains];
            }
        }
    }

    public function add_if_page_contains_condition(string $if_page_contains): void
    {
        if (! empty($this->single_page_link)) {
            $key = end($this->single_page_link);
            reset($this->single_page_link);
            $this->if_page_contains['single_page_link'][$key] = $if_page_contains;
        }
    }

    public function get_if_page_contains_condition(string $directive_name, string $directive_value): ?string
    {
        if (isset($this->if_page_contains[$directive_name])) {
            if (isset($this->if_page_contains[$directive_name][$directive_value])) {
                return $this->if_page_contains[$directive_name][$directive_value];
            }
        }

        return null;
    }

    protected static function debug(string $msg): void
    {
        if (self::$debug) {
            echo '* ', $msg;
            echo "\n";
            ob_flush();
            flush();
        }
    }

    protected static function load_cached_merged(string $host, bool $exact_host_match): self|false
    {
        if ($exact_host_match) {
            $key = $host.'.merged.ex';
        } else {
            $key = $host.'.merged';
        }

        return self::load_cached($key);
    }

    protected static function add_to_cache_merged(string $host, bool $exact_host_match, ?self $config = null): void
    {
        if ($exact_host_match) {
            $key = $host.'.merged.ex';
        } else {
            $key = $host.'.merged';
        }
        if (! isset($config)) {
            $config = new self;
        }
        self::add_to_cache($key, $config);
    }
}
