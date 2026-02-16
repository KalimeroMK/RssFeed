<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * HTML Sanitizer Service
 * Modern wrapper for ezyang/htmlpurifier
 * Removes XSS vulnerabilities and inline styles from HTML content
 */
class HtmlSanitizerService
{
    private ?HTMLPurifier $purifier = null;

    private ?HTMLPurifier $purifierNoStyles = null;

    /** @var array<string, mixed> */
    private array $defaultConfig = [
        'HTML.Allowed' => 'p,b,a[href],i,em,strong,ul,ol,li,br,img[src|alt],h1,h2,h3,h4,h5,h6,blockquote,cite,code,pre,table,thead,tbody,tr,td,th',
        'CSS.AllowedProperties' => 'font,font-size,font-weight,font-style,font-family,text-decoration,color,background-color,text-align',
        'AutoFormat.AutoParagraph' => false,
        'AutoFormat.RemoveEmpty' => true,
        'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
        'URI.DisableExternalResources' => false,
        'HTML.TargetBlank' => true,
    ];

    /** @var array<string, mixed> */
    private array $noStylesConfig = [
        'HTML.Allowed' => 'p,b,a[href],i,em,strong,ul,ol,li,br,img[src|alt],h1,h2,h3,h4,h5,h6,blockquote,cite,code,pre,table,thead,tbody,tr,td,th',
        'CSS.AllowedProperties' => '', // No CSS allowed - removes inline styles
        'AutoFormat.AutoParagraph' => false,
        'AutoFormat.RemoveEmpty' => true,
        'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
        'URI.DisableExternalResources' => false,
        'HTML.TargetBlank' => true,
    ];

    public function __construct()
    {
        $this->purifier = $this->createPurifier($this->defaultConfig);
        $this->purifierNoStyles = $this->createPurifier($this->noStylesConfig);
    }

    /**
     * Create HTMLPurifier instance with configuration
     *
     * @param  array<string, mixed>  $config
     */
    private function createPurifier(array $config): HTMLPurifier
    {
        $purifierConfig = HTMLPurifier_Config::createDefault();

        foreach ($config as $key => $value) {
            $purifierConfig->set($key, $value);
        }

        // Cache directory for HTMLPurifier
        $cacheDir = storage_path('app/htmlpurifier-cache');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $purifierConfig->set('Cache.SerializerPath', $cacheDir);

        return new HTMLPurifier($purifierConfig);
    }

    /**
     * Sanitize HTML content
     * Removes XSS vulnerabilities and malformed HTML
     */
    public function sanitize(string $html): string
    {
        return $this->purifier->purify($html);
    }

    /**
     * Sanitize HTML and remove all inline styles
     * Use this when you want clean HTML without any style attributes
     */
    public function sanitizeWithoutStyles(string $html): string
    {
        return $this->purifierNoStyles->purify($html);
    }

    /**
     * Sanitize HTML with custom allowed elements
     */
    public function sanitizeWithAllowed(string $html, string $allowedElements): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', $allowedElements);

        $cacheDir = storage_path('app/htmlpurifier-cache');
        $config->set('Cache.SerializerPath', $cacheDir);

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($html);
    }

    /**
     * Strip all HTML tags (keep only text)
     */
    public function stripAllTags(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', '');

        $cacheDir = storage_path('app/htmlpurifier-cache');
        $config->set('Cache.SerializerPath', $cacheDir);

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($html);
    }

    /**
     * Check if HTML contains malicious content
     */
    public function isMalicious(string $html): bool
    {
        $cleaned = $this->sanitize($html);

        // If the cleaned version is significantly different, it likely had malicious content
        return mb_strlen($cleaned) !== mb_strlen($html) &&
               preg_match('/<script|javascript:|on\w+=/i', $html);
    }

    /**
     * Clean inline styles from HTML (using Readability's approach)
     * This removes style attributes but keeps other HTML intact
     */
    public function cleanInlineStyles(string $html): string
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $elements = $xpath->query('//*[@style]');

        foreach ($elements as $element) {
            if ($element instanceof \DOMElement) {
                $element->removeAttribute('style');
            }
        }

        // Get the body content
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            $result = '';
            foreach ($body->childNodes as $child) {
                $result .= $dom->saveHTML($child);
            }
            return $result;
        }

        return $html;
    }
}
