<?php

declare(strict_types=1);

/**
 * Content Extractor
 *
 * Uses patterns specified in site config files and auto detection (hNews/PHP Readability)
 * to extract content from HTML files.
 *
 * @version 1.6
 *
 * @date 2021-03-01
 *
 * @author Keyvan Minoukadeh
 * @copyright 2021 Keyvan Minoukadeh
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPL v3
 */

namespace Kalimeromk\Rssfeed\Extractors\ContentExtractor;

use DOMAttr;
use DOMElement;
use DOMException;
use DOMNodeList;
use DOMXPath;
use Kalimeromk\Rssfeed\Extractors\Readability\Readability;

class ContentExtractor
{
    /** @var array<int, string> */
    public array $allowedParsers = ['libxml', 'html5php', 'gumbo'];

    public string $defaultParser = 'html5php';

    public bool $allowParserOverride = false;

    public ?string $parserOverride = null;

    public ?string $selectedParser = null;

    /** @var array<string, string|array<string, string>> */
    public array $fingerprints = [];

    public bool $stripImages = false;

    public ?Readability $readability = null;

    public bool $debug = false;

    public bool $debugVerbose = false;

    /** @var array<string, mixed> */
    protected static array $tidy_config = [
        'clean' => false,
        'output-xhtml' => true,
        'logical-emphasis' => true,
        'show-body-only' => false,
        'new-blocklevel-tags' => 'article aside footer header hgroup menu nav section details datagrid',
        'new-inline-tags' => 'mark time meter progress data wbr',
        'wrap' => 0,
        'drop-empty-paras' => true,
        'drop-proprietary-attributes' => false,
        'enclose-text' => true,
        'enclose-block-text' => true,
        'merge-divs' => true,
        'merge-spans' => true,
        'char-encoding' => 'utf8',
        'hide-comments' => true,
    ];

    protected ?string $html = null;

    protected ?SiteConfig $config = null;

    protected ?SiteConfig $userSubmittedConfig = null;

    protected ?string $title = null;

    protected bool $nativeAd = false;

    /** @var array<int, string> */
    protected array $author = [];

    protected ?string $language = null;

    protected ?int $date = null;

    protected ?DOMElement $body = null;

    protected bool $success = false;

    protected ?string $nextPageUrl = null;

    /** @var array<string, string> */
    protected array $opengraph = [];

    /** @var array<string, mixed> */
    protected array $jsonld = [];

    /** @var array<string, string> */
    protected array $twitterCard = [];

    public function __construct(string $path, ?string $fallback = null)
    {
        SiteConfig::set_config_path($path, $fallback);
    }

    public function reset(): void
    {
        $this->html = null;
        $this->readability = null;
        $this->config = null;
        $this->title = null;
        $this->nativeAd = false;
        $this->body = null;
        $this->author = [];
        $this->language = null;
        $this->date = null;
        $this->nextPageUrl = null;
        $this->success = false;
        $this->opengraph = [];
        $this->jsonld = [];
        $this->twitterCard = [];
    }

    public function findHostUsingFingerprints(string $html): string|false
    {
        $this->debug('Checking fingerprints...');
        $head = mb_substr($html, 0, 10000);
        foreach ($this->fingerprints as $_fp => $_fphost) {
            $lookin = 'html';
            if (is_array($_fphost)) {
                if (isset($_fphost['head']) && $_fphost['head']) {
                    $lookin = 'head';
                }
                $_fphost = $_fphost['hostname'];
            }
            if (mb_strpos($$lookin, $_fp) !== false) {
                $this->debug("Found match: $_fphost");

                return $_fphost;
            }
        }
        $this->debug('No fingerprint matches');

        return false;
    }

    public function buildSiteConfig(string $url, string $html = ''): SiteConfig
    {
        $host = @parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            $host = '';
        }
        $host = mb_strtolower($host);
        if (mb_substr($host, 0, 4) === 'www.') {
            $host = mb_substr($host, 4);
        }
        $config = SiteConfig::build($host);
        if (! $config) {
            $config = new SiteConfig;
        }
        if ($config->autodetect_on_failure()) {
            if (! empty($this->fingerprints) && ($_fphost = $this->findHostUsingFingerprints($html))) {
                if ($config_fingerprint = SiteConfig::build($_fphost)) {
                    $this->debug("Appending site config settings from $_fphost (fingerprint match)");
                    $config->append($config_fingerprint);
                }
            }
        }
        if ($config->autodetect_on_failure()) {
            if ($config_global = SiteConfig::build('global', true)) {
                $this->debug('Appending site config settings from global.txt');
                $config->append($config_global);
            }
        }

        return $config;
    }

    /**
     * $smart_tidy indicates that if tidy is used and no results are produced, we will
     * try again without it. Tidy helps us deal with PHP's patchy HTML parsing most of the time
     * but it has problems of its own which we try to avoid with this option.
     */
    public function process(string $html, string $url, bool $smart_tidy = true, bool $is_next_page = false): bool
    {
        $this->reset();
        if (isset($this->userSubmittedConfig)) {
            $this->debug('Using user-submitted site config');
            $config = $this->userSubmittedConfig;
        } else {
            $config = $this->buildSiteConfig($url, $html);
        }

        if ($config === null) {
            return false;
        }

        $this->config = $config;

        if ($this->userSubmittedConfig !== null && $config->autodetect_on_failure()) {
            $this->debug('Merging user-submitted site config with site config files associated with this URL and/or content');
            $config->append($this->buildSiteConfig($url, $html));
        }

        if (! empty($config->find_string)) {
            if (count($config->find_string) === count($config->replace_string)) {
                $html = str_replace($config->find_string, $config->replace_string, $html, $_count);
                $this->debug("Strings replaced: $_count (find_string and/or replace_string)");
            } else {
                $this->debug('Skipped string replacement - incorrect number of find-replace strings in site config');
            }
            unset($_count);
        }

        $_parser = $this->defaultParser;
        if ($this->allowParserOverride && $this->parserOverride) {
            $_parser = $this->parserOverride;
        } elseif ($this->allowParserOverride && ($config->parser($use_default = false) !== null)) {
            $_parser = $config->parser($use_default = false);
        }
        if ($_parser === 'html5lib') {
            $_parser = 'html5php';
        }
        if (($_parser !== $this->defaultParser) && ! in_array($_parser, $this->allowedParsers)) {
            $this->debug("HTML parser $_parser not allowed, using ".$this->defaultParser.' instead');
            $_parser = $this->defaultParser;
        }
        if ($_parser === 'gumbo' && ! class_exists('Layershifter\Gumbo\Parser')) {
            $this->debug('Gumbo PHP extension not available on server, using HTML5-PHP instead');
            $_parser = 'html5php';
        }
        $this->selectedParser = $_parser;

        $tidied = false;
        $original_html = $html;
        if ($config->tidy() && function_exists('tidy_parse_string') && $smart_tidy) {
            if (($_parser === 'gumbo' || $_parser === 'html5php') && ($config->tidy === null)) {
                // No Tidy
            } else {
                $this->debug('Using Tidy');
                $tidy = tidy_parse_string($html, self::$tidy_config, 'UTF8');
                if ($tidy instanceof \tidy && tidy_clean_repair($tidy)) {
                    $tidied = true;
                    $html = $tidy->value;
                }
                unset($tidy);
            }
        }

        $this->debug("Attempting to parse HTML with $_parser");
        if ($html === null) {
            $html = '';
        }
        $this->readability = new Readability($html, $url, $_parser);

        $readability = $this->readability;
        if ($readability->dom === null) {
            return false;
        }

        $xpath = new DOMXPath($readability->dom);

        foreach ($config->next_page_link as $pattern) {
            $elems = @$xpath->evaluate($pattern, $readability->dom);
            if (is_string($elems)) {
                $this->nextPageUrl = mb_trim($elems);
                break;
            }
            if ($elems instanceof DOMNodeList && $elems->length > 0) {
                foreach ($elems as $item) {
                    if ($item instanceof DOMElement && $item->hasAttribute('href')) {
                        $this->nextPageUrl = $item->getAttribute('href');
                        break 2;
                    }
                    if ($item instanceof DOMAttr && $item->value) {
                        $this->nextPageUrl = $item->value;
                        break 2;
                    }
                }
            }
        }

        foreach ($config->native_ad_clue as $pattern) {
            $elems = @$xpath->evaluate($pattern, $readability->dom);
            if ($elems instanceof DOMNodeList && $elems->length > 0) {
                $this->nativeAd = true;
                break;
            }
        }

        foreach ($config->title as $pattern) {
            $elems = @$xpath->evaluate($pattern, $readability->dom);
            if (is_string($elems)) {
                $this->title = mb_trim($elems);
                $this->debug('Title expression evaluated as string: '.$this->title);
                $this->debug("...XPath match: $pattern");
                break;
            }
            if ($elems instanceof DOMNodeList && $elems->length > 0) {
                $item = $elems->item(0);
                if ($item instanceof \DOMElement) {
                    $this->title = $item->textContent;
                    $this->debug('Title matched: '.$this->title);
                    $this->debug("...XPath match: $pattern");
                    try {
                        if ($item->parentNode !== null) {
                            @$item->parentNode->removeChild($item);
                        }
                    } catch (DOMException $e) {
                        // do nothing
                    }
                }
                break;
            }
        }

        if (empty($this->author)) {
            foreach ($config->author as $pattern) {
                $elems = @$xpath->evaluate($pattern, $readability->dom);
                if (is_string($elems)) {
                    $_author = mb_trim($elems);
                    $_author = $this->cleanAuthor($_author);
                    if ($_author === '') {
                        continue;
                    }
                    $this->author[] = $_author;
                    $this->debug('Author expression evaluated as string: '.$_author);
                    $this->debug("...XPath match: $pattern");
                    break;
                }
                if ($elems instanceof DOMNodeList && $elems->length > 0) {
                    foreach ($elems as $elem) {
                        if (! $elem instanceof \DOMElement) {
                            continue;
                        }
                        if (! isset($elem->parentNode)) {
                            continue;
                        }
                        $_author = mb_trim($elem->textContent);
                        $_author = $this->cleanAuthor($_author);
                        if ($_author === '') {
                            continue;
                        }
                        $this->author[] = $_author;
                        $this->debug('Author matched: '.$_author);
                    }
                    if (! empty($this->author)) {
                        $this->debug("...XPath match: $pattern");
                        break;
                    }
                }
            }
        }

        $_lang_xpath = ['//html[@lang]/@lang', '//meta[@name="DC.language"]/@content'];
        foreach ($_lang_xpath as $pattern) {
            $elems = @$xpath->evaluate($pattern, $readability->dom);
            if (is_string($elems)) {
                if (mb_trim($elems) !== '') {
                    $this->language = mb_trim($elems);
                    $this->debug('Language matched: '.$this->language);
                    break;
                }
            } elseif ($elems instanceof DOMNodeList && $elems->length > 0) {
                foreach ($elems as $elem) {
                    if (! $elem instanceof \DOMElement) {
                        continue;
                    }
                    if (! isset($elem->parentNode)) {
                        continue;
                    }
                    $this->language = mb_trim($elem->textContent);
                    $this->debug('Language matched: '.$this->language);
                }
                if ($this->language) {
                    break;
                }
            }
        }

        $elems = @$xpath->query("//head//meta[@property='og:title' or @property='og:type' or @property='og:url' or @property='og:image' or @property='og:description']",
            $readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Extracting Open Graph elements');
            foreach ($elems as $elem) {
                if ($elem instanceof \DOMElement && $elem->hasAttribute('content')) {
                    $_prop = mb_strtolower($elem->getAttribute('property'));
                    $_val = $elem->getAttribute('content');
                    if (! isset($this->opengraph[$_prop])) {
                        $this->opengraph[$_prop] = $_val;
                    }
                }
            }
            unset($_prop, $_val);
        }

        $elems = @$xpath->query("//head//meta[@name='twitter:card' or @name='twitter:site' or @name='twitter:creator' or @name='twitter:description' or @name='twitter:title' or @name='twitter:image']",
            $readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Extracting Twiter Card elements');
            foreach ($elems as $elem) {
                if ($elem instanceof \DOMElement && $elem->hasAttribute('content')) {
                    $_prop = mb_strtolower($elem->getAttribute('name'));
                    $_val = $elem->getAttribute('content');
                    if (! isset($this->twitterCard[$_prop])) {
                        $this->twitterCard[$_prop] = $_val;
                    }
                }
            }
            unset($_prop, $_val);
        }

        foreach ($config->date as $pattern) {
            $elems = @$xpath->evaluate($pattern, $readability->dom);
            $dateValue = null;
            if (is_string($elems)) {
                $dateValue = strtotime(mb_trim($elems, "; \t\n\r\0\x0B"));
            } elseif ($elems instanceof DOMNodeList && $elems->length > 0) {
                $item = $elems->item(0);
                if ($item instanceof \DOMElement) {
                    $dateStr = $item->textContent;
                    $dateValue = strtotime(mb_trim($dateStr, "; \t\n\r\0\x0B"));
                }
            }
            if (! $dateValue) {
                $this->date = null;
            } else {
                $this->date = $dateValue;
                $this->debug('Date matched: '.date('Y-m-d H:i:s', $this->date));
                $this->debug("...XPath match: $pattern");
                break;
            }
        }

        foreach ($config->strip as $pattern) {
            $elems = @$xpath->query($pattern, $readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('Stripping '.$elems->length.' elements (strip: '.$pattern.')');
                for ($i = $elems->length - 1; $i >= 0; $i--) {
                    $item = $elems->item($i);
                    if ($item === null) {
                        continue;
                    }
                    if ($item instanceof DOMAttr && $item->parentNode instanceof \DOMElement) {
                        $item->parentNode->removeAttributeNode($item);
                    } elseif ($item instanceof \DOMElement && $item->parentNode !== null) {
                        $item->parentNode->removeChild($item);
                    }
                }
            }
        }

        foreach ($config->strip_id_or_class as $string) {
            $string = strtr($string, ["'" => '', '"' => '']);
            $elems = @$xpath->query("//*[contains(@class, '$string') or contains(@id, '$string')]",
                $readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('Stripping '.$elems->length.' elements (strip_id_or_class: '.$string.')');
                for ($i = $elems->length - 1; $i >= 0; $i--) {
                    $item = $elems->item($i);
                    if ($item instanceof \DOMElement && $item->parentNode !== null) {
                        $item->parentNode->removeChild($item);
                    }
                }
            }
        }

        foreach ($config->strip_image_src as $string) {
            $string = strtr($string, ["'" => '', '"' => '']);
            $elems = @$xpath->query("//img[contains(@src, '$string')]", $readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('Stripping '.$elems->length.' elements (strip_image_src: '.$string.')');
                for ($i = $elems->length - 1; $i >= 0; $i--) {
                    $item = $elems->item($i);
                    if ($item instanceof \DOMElement && $item->parentNode !== null) {
                        $item->parentNode->removeChild($item);
                    }
                }
            }
        }

        $elems = @$xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' entry-unrelated ') or contains(concat(' ',normalize-space(@class),' '),' instapaper_ignore ')]",
            $readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Stripping '.$elems->length.' .entry-unrelated,.instapaper_ignore elements');
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $item = $elems->item($i);
                if ($item instanceof \DOMElement && $item->parentNode !== null) {
                    $item->parentNode->removeChild($item);
                }
            }
        }

        $elems = @$xpath->query("//*[contains(@style,'display:none')]", $readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Stripping '.$elems->length.' elements with inline display:none style');
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $item = $elems->item($i);
                if ($item instanceof \DOMElement && $item->parentNode !== null) {
                    $item->parentNode->removeChild($item);
                }
            }
        }

        $elems = $xpath->query("//a[not(./*) and normalize-space(.)='']", $readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Stripping '.$elems->length.' empty a elements');
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $item = $elems->item($i);
                if ($item instanceof \DOMElement && $item->parentNode !== null) {
                    $item->parentNode->removeChild($item);
                }
            }
        }

        foreach ($config->body as $pattern) {
            $elems = @$xpath->query($pattern, $readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('Body matched');
                $this->debug("...XPath match: $pattern");
                if ($elems->length === 1) {
                    $item = $elems->item(0);
                    if ($item instanceof \DOMElement) {
                        $this->body = $item;
                        if ($config->prune()) {
                            $this->debug('...pruning content');
                            $readability->prepArticle($item);
                        }
                    }
                    break;
                }
                if ($readability->dom === null) {
                    break;
                }
                $newBody = $readability->dom->createElement('div');
                $this->debug($elems->length.' body elems found');
                foreach ($elems as $elem) {
                    if (! $elem instanceof \DOMElement) {
                        continue;
                    }
                    if (! isset($elem->parentNode)) {
                        continue;
                    }
                    $isDescendant = false;
                    foreach ($newBody->childNodes as $parent) {
                        if ($parent instanceof \DOMElement && $this->isDescendant($parent, $elem)) {
                            $isDescendant = true;
                            break;
                        }
                    }
                    if ($isDescendant) {
                        $this->debug('...element is child of another body element, skipping.');
                    } else {
                        if ($config->prune()) {
                            $this->debug('Pruning content');
                            $readability->prepArticle($elem);
                        }
                        $this->debug('...element added to body');
                        $newBody->appendChild($elem);
                    }
                }
                if ($newBody->hasChildNodes()) {
                    $this->body = $newBody;
                    break;
                }

            }
        }

        $detect_title = $detect_body = $detect_author = $detect_date = false;
        if (! isset($this->title)) {
            if (empty($config->title) || $config->autodetect_on_failure()) {
                $detect_title = true;
            }
        }
        if (! isset($this->body)) {
            if (empty($config->body) || $config->autodetect_on_failure()) {
                $detect_body = true;
            }
        }
        if (empty($this->author)) {
            if (empty($config->author) || $config->autodetect_on_failure()) {
                $detect_author = true;
            }
        }
        if (! isset($this->date)) {
            if (empty($config->date) || $config->autodetect_on_failure()) {
                $detect_date = true;
            }
        }

        $readability = $this->readability;
        if ($readability === null || $readability->dom === null) {
            return false;
        }

        if (! $config->skip_json_ld()) {
            $elems = @$xpath->query("//script[@type='application/ld+json']", $readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('JSON+LD: found script tag');
                $jsonld = [];
                foreach ($elems as $elem) {
                    if (! $elem instanceof \DOMElement) {
                        continue;
                    }
                    $_jsonld = @json_decode($elem->textContent);
                    if (! $_jsonld) {
                        continue;
                    }
                    if (is_array($_jsonld)) {
                        foreach ($_jsonld as $_jsonld_object) {
                            if (is_object($_jsonld_object)) {
                                $jsonld[] = $_jsonld_object;
                            }
                        }
                    } elseif (is_object($_jsonld)) {
                        if (is_array($this->getJsonLdAttribute('@graph', $_jsonld))) {
                            $_jsonld = $this->getJsonLdAttribute('@graph', $_jsonld);
                            foreach ($_jsonld as $_jsonld_object) {
                                if (is_object($_jsonld_object)) {
                                    $jsonld[] = $_jsonld_object;
                                }
                            }
                        } else {
                            $jsonld[] = $_jsonld;
                        }
                    }
                }
                foreach ($jsonld as $jsonld_object) {
                    if (! isset($this->jsonld['date'])) {
                        $this->processDateJsonLD($jsonld_object);
                    }
                    if (! isset($this->jsonld['title'])) {
                        $this->processTitleJsonLD($jsonld_object);
                    }
                    if (! isset($this->jsonld['author'])) {
                        $this->processAuthorJsonLD($jsonld_object);
                    }
                    if (! isset($this->jsonld['image'])) {
                        $this->processImageJsonLD($jsonld_object);
                    }
                }
            }
        }
        if ($detect_date && isset($this->jsonld['date'])) {
            $this->date = $this->jsonld['date'];
            $detect_date = false;
            $this->debug('JSON+LD: found date: '.date('Y-m-d', $this->date));
        }
        if ($detect_title && isset($this->jsonld['title'])) {
            $this->title = $this->jsonld['title'];
            $detect_title = false;
            $this->debug('JSON+LD: found title: '.$this->title);
        }
        if ($detect_author && isset($this->jsonld['author'])) {
            $this->author[] = $this->jsonld['author'];
            $detect_author = false;
            $this->debug('JSON+LD: found author(s): '.$this->jsonld['author']);
        }
        if ($detect_title || $detect_body) {
            $elems = @$xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' hentry ')]",
                $readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('hNews: found hentry');
                $hentry = $elems->item(0);
                if (! $hentry instanceof \DOMElement) {
                    $hentry = null;
                }

                if ($detect_title && $hentry !== null) {
                    $elems = @$xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' entry-title ')]",
                        $hentry);
                    if ($elems && $elems->length > 0) {
                        $item = $elems->item(0);
                        if ($item instanceof \DOMElement) {
                            $this->title = $item->textContent;
                            $this->debug('hNews: found entry-title: '.$this->title);
                            if ($item->parentNode !== null) {
                                $item->parentNode->removeChild($item);
                            }
                            $detect_title = false;
                        }
                    }
                }

                if ($detect_date && $hentry !== null) {
                    $elems = @$xpath->query(".//time[@pubdate or @pubDate] | .//abbr[contains(concat(' ',normalize-space(@class),' '),' published ')]",
                        $hentry);
                    if ($elems && $elems->length > 0) {
                        $item = $elems->item(0);
                        if ($item instanceof \DOMElement) {
                            $dateValue = strtotime(mb_trim($item->textContent));
                            if ($dateValue !== false) {
                                $this->date = $dateValue;
                                $this->debug('hNews: found publication date: '.date('Y-m-d H:i:s', $this->date));
                                $detect_date = false;
                            } else {
                                $this->date = null;
                            }
                        }
                    }
                }

                if ($detect_author && $hentry !== null) {
                    $elems = @$xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' vcard ') and (contains(concat(' ',normalize-space(@class),' '),' author ') or contains(concat(' ',normalize-space(@class),' '),' byline '))]",
                        $hentry);
                    if ($elems && $elems->length > 0) {
                        $author = $elems->item(0);
                        if ($author instanceof \DOMElement) {
                            $fn = @$xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' fn ')]", $author);
                            if ($fn && $fn->length > 0) {
                                foreach ($fn as $_fn) {
                                    if ($_fn instanceof \DOMElement && mb_trim($_fn->textContent) !== '') {
                                        $this->author[] = mb_trim($_fn->textContent);
                                        $this->debug('hNews: found author: '.mb_trim($_fn->textContent));
                                    }
                                }
                            } else {
                                if (mb_trim($author->textContent) !== '') {
                                    $this->author[] = mb_trim($author->textContent);
                                    $this->debug('hNews: found author: '.mb_trim($author->textContent));
                                }
                            }
                            $detect_author = empty($this->author);
                        }
                    }
                }

                if ($detect_body && $hentry !== null) {
                    $elems = @$xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' entry-content ')]",
                        $hentry);
                    if ($elems && $elems->length > 0) {
                        $this->debug('hNews: found entry-content');
                        if ($elems->length === 1) {
                            $e = $elems->item(0);
                            if ($e instanceof \DOMElement && (($e->tagName === 'img') || (mb_trim($e->textContent) !== ''))) {
                                $this->body = $e;
                                if ($config->prune()) {
                                    $this->debug('Pruning content');
                                    $readability->prepArticle($e);
                                }
                                $detect_body = false;
                            } else {
                                $this->debug('hNews: skipping entry-content - appears not to contain content');
                            }
                            unset($e);
                        } else {
                            $newBody = $readability->dom->createElement('div');
                            $this->debug($elems->length.' entry-content elems found');
                            foreach ($elems as $elem) {
                                if (! $elem instanceof \DOMElement) {
                                    continue;
                                }
                                if (! isset($elem->parentNode)) {
                                    continue;
                                }
                                $isDescendant = false;
                                foreach ($newBody->childNodes as $parent) {
                                    if ($parent instanceof \DOMElement && $this->isDescendant($parent, $elem)) {
                                        $isDescendant = true;
                                        break;
                                    }
                                }
                                if ($isDescendant) {
                                    $this->debug('Element is child of another body element, skipping.');
                                } else {
                                    if ($config->prune()) {
                                        $this->debug('Pruning content');
                                        $readability->prepArticle($elem);
                                    }
                                    $this->debug('Element added to body');
                                    $newBody->appendChild($elem);
                                }
                            }
                            $this->body = $newBody;
                            $detect_body = false;
                        }
                    }
                }
            }
        }

        if ($detect_title) {
            $elems = @$xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_title ')]",
                $readability->dom);
            if ($elems && $elems->length > 0) {
                $item = $elems->item(0);
                if ($item instanceof \DOMElement) {
                    $this->title = $item->textContent;
                    $this->debug('Title found (.instapaper_title): '.$this->title);
                    if ($item->parentNode !== null) {
                        $item->parentNode->removeChild($item);
                    }
                    $detect_title = false;
                }
            }
        }
        if ($detect_body) {
            $elems = @$xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_body ')]",
                $readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('body found (.instapaper_body)');
                $item = $elems->item(0);
                if ($item instanceof \DOMElement) {
                    $this->body = $item;
                    if ($config->prune()) {
                        $this->debug('Pruning content');
                        $readability->prepArticle($item);
                    }
                }
                $detect_body = false;
            }
        }

        if ($detect_body) {
            $elems = @$xpath->query("//*[@itemprop='articleBody']", $readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('body found (Schema.org itemprop="articleBody")');
                if ($elems->length === 1) {
                    $e = $elems->item(0);
                    if ($e instanceof \DOMElement && (($e->tagName === 'img') || (mb_trim($e->textContent) !== ''))) {
                        $this->body = $e;
                        if ($config->prune()) {
                            $this->debug('Pruning content');
                            $readability->prepArticle($e);
                        }
                        $detect_body = false;
                    } else {
                        $this->debug('Schema.org: skipping itemprop="articleBody" - appears not to contain content');
                    }
                    unset($e);
                } else {
                    if ($readability->dom === null) {
                        return $this->success;
                    }
                    $newBody = $readability->dom->createElement('div');
                    $this->debug($elems->length.' itemprop="articleBody" elems found');
                    foreach ($elems as $elem) {
                        if (! $elem instanceof \DOMElement) {
                            continue;
                        }
                        if (! isset($elem->parentNode)) {
                            continue;
                        }
                        $isDescendant = false;
                        foreach ($newBody->childNodes as $parent) {
                            if ($parent instanceof \DOMElement && $this->isDescendant($parent, $elem)) {
                                $isDescendant = true;
                                break;
                            }
                        }
                        if ($isDescendant) {
                            $this->debug('Element is child of another body element, skipping.');
                        } else {
                            if ($config->prune()) {
                                $this->debug('Pruning content');
                                $readability->prepArticle($elem);
                            }
                            $this->debug('Element added to body');
                            $newBody->appendChild($elem);
                        }
                    }
                    $this->body = $newBody;
                    $detect_body = false;
                }
            }
        }

        if ($detect_author) {
            $elems = @$xpath->query("//a[contains(concat(' ',normalize-space(@rel),' '),' author ')]",
                $readability->dom);
            if ($elems && $elems->length === 1) {
                $item = $elems->item(0);
                if ($item instanceof \DOMElement) {
                    $author = mb_trim($item->textContent);
                    if ($author !== '') {
                        $this->debug("Author found (rel=\"author\"): $author");
                        $this->author[] = $author;
                        $detect_author = false;
                    }
                }
            }
        }

        if ($detect_date) {
            $elems = @$xpath->query("//meta[@property='article:published_time' and @content]", $readability->dom);
            if ($elems && $elems->length === 1) {
                $item = $elems->item(0);
                if ($item instanceof \DOMElement) {
                    $dateValue = strtotime(mb_trim($item->getAttribute('content')));
                    if ($dateValue !== false) {
                        $this->date = $dateValue;
                        $this->debug('Date found (article:published_time): '.date('Y-m-d H:i:s', $this->date));
                        $detect_date = false;
                    } else {
                        $this->date = null;
                    }
                }
            }
        }

        if ($detect_date) {
            $elems = @$xpath->query('//time[@pubdate or @pubDate]', $readability->dom);
            if ($elems && $elems->length === 1) {
                $item = $elems->item(0);
                if ($item instanceof \DOMElement) {
                    $dateValue = strtotime(mb_trim($item->textContent));
                    if ($dateValue !== false) {
                        $this->date = $dateValue;
                        $this->debug('Date found (pubdate marked time element): '.date('Y-m-d H:i:s', $this->date));
                        $detect_date = false;
                    } else {
                        $this->date = null;
                    }
                }
            }
        }

        $success = false;
        if ($detect_title || $detect_body) {
            $this->debug('Using Readability');
            if (isset($this->body)) {
                $clonedBody = $this->body->cloneNode(true);
                if ($clonedBody instanceof \DOMElement) {
                    $this->body = $clonedBody;
                }
            }
            $success = $readability->init();
        }
        if ($detect_title) {
            $this->debug('Detecting title');
            $titleElem = $readability->getTitle();
            if ($titleElem !== null) {
                $this->title = $titleElem->textContent;
            }
        }
        if ($detect_body && $success) {
            $this->debug('Detecting body');
            $content = $readability->getContent();
            if ($content !== null) {
                $this->body = $content;
                $firstChildNode = $this->body->firstChild;
                if ($this->body->childNodes->length === 1 && $firstChildNode !== null && $firstChildNode->nodeType === XML_ELEMENT_NODE && $firstChildNode instanceof \DOMElement) {
                    $this->body = $firstChildNode;
                }
                $body = $this->body;
                if ($config->prune()) {
                    $this->debug('Pruning content');
                    $readability->prepArticle($body);
                }
            }
        }
        if (isset($this->body)) {
            $readability->removeScripts($this->body);
            if (! $is_next_page) {
                if (isset($this->title) && ($this->title !== '') && $this->body->hasChildNodes()) {
                    $firstChild = $this->body->firstChild;
                    while ($firstChild !== null && $firstChild->nodeType && ($firstChild->nodeType !== XML_ELEMENT_NODE)) {
                        $firstChild = $firstChild->nextSibling;
                    }
                    if ($firstChild instanceof \DOMElement
                        && in_array(mb_strtolower($firstChild->tagName), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])
                        && (mb_strtolower(mb_trim($firstChild->textContent)) === mb_strtolower(mb_trim($this->title)))) {
                        $this->body->removeChild($firstChild);
                    }
                }
            }
            $bodyOwnerDocument = $this->body->ownerDocument;
            if ($bodyOwnerDocument !== null) {
                $_dont_self_close = ['iframe', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
                foreach ($_dont_self_close as $_tagname) {
                    if ($this->body->tagName === $_tagname) {
                        if (! $this->body->hasChildNodes()) {
                            if ($_tagname === 'iframe') {
                                $this->body->appendChild($bodyOwnerDocument->createTextNode('[embedded content]'));
                            } else {
                                $this->body->appendChild($bodyOwnerDocument->createTextNode(''));
                            }
                        }
                    } else {
                        $elems = $this->body->getElementsByTagName($_tagname);
                        for ($i = $elems->length - 1; $i >= 0; $i--) {
                            $e = $elems->item($i);
                            if ($e instanceof \DOMElement && ! $e->hasChildNodes()) {
                                if ($_tagname === 'iframe') {
                                    $e->appendChild($bodyOwnerDocument->createTextNode('[embedded content]'));
                                } else {
                                    $e->appendChild($bodyOwnerDocument->createTextNode(''));
                                }
                            }
                        }
                    }
                }
            }

            $elems = @$xpath->query('.//img[@data-lazy-src]', $this->body);
            if ($elems !== false) {
                for ($i = $elems->length - 1; $i >= 0; $i--) {
                    $e = $elems->item($i);
                    if ($e instanceof \DOMElement && $e->nextSibling !== null && $e->nextSibling->nodeName === 'noscript') {
                        $eOwnerDocument = $e->ownerDocument;
                        $nextSibling = $e->nextSibling;
                        if ($eOwnerDocument !== null && $nextSibling instanceof \DOMElement && property_exists($nextSibling, 'innerHTML')) {
                            $_new_elem = $eOwnerDocument->createDocumentFragment();
                            @$_new_elem->appendXML($nextSibling->innerHTML);
                            if ($nextSibling->parentNode !== null) {
                                $nextSibling->parentNode->replaceChild($_new_elem, $nextSibling);
                            }
                            if ($e->parentNode !== null) {
                                $e->parentNode->removeChild($e);
                            }
                        }
                    } elseif ($e instanceof \DOMElement) {
                        $e->setAttribute('src', $e->getAttribute('data-lazy-src'));
                        $e->removeAttribute('data-lazy-src');
                    }
                }
            }
            $elems = @$xpath->query(".//img[(@data-src or @data-srcset) and (contains(@src, 'data:image') or contains(@src, '.gif'))]",
                $this->body);
            if ($elems !== false) {
                for ($i = $elems->length - 1; $i >= 0; $i--) {
                    $e = $elems->item($i);
                    if ($e instanceof \DOMElement) {
                        if ($e->hasAttribute('data-src')) {
                            $e->setAttribute('src', $e->getAttribute('data-src'));
                            $e->removeAttribute('data-src');
                        }
                        if ($e->hasAttribute('data-srcset')) {
                            $e->setAttribute('srcset', $e->getAttribute('data-srcset'));
                            $e->removeAttribute('data-srcset');
                        }
                    }
                }
            }
            $elems = @$xpath->query(".//source[@data-srcset and (not(@srcset) or contains(@srcset, 'data:image'))]",
                $this->body);
            if ($elems !== false) {
                for ($i = $elems->length - 1; $i >= 0; $i--) {
                    $e = $elems->item($i);
                    if ($e instanceof \DOMElement) {
                        $e->setAttribute('srcset', $e->getAttribute('data-srcset'));
                        $e->removeAttribute('data-srcset');
                    }
                }
            }
            if ($this->stripImages && $this->body->hasChildNodes()) {
                $elems = @$xpath->query('.//picture | .//figure | .//img | .//figcaption', $this->body);
                if ($elems && $elems->length > 0) {
                    $this->debug('Stripping images: '.$elems->length.' img/picture/figure/figcaption elements');
                    for ($i = $elems->length - 1; $i >= 0; $i--) {
                        $item = $elems->item($i);
                        if ($item instanceof \DOMNode && $item->parentNode !== null) {
                            @$item->parentNode->removeChild($item);
                        }
                    }
                }
            } else {
                if (! $is_next_page) {
                    if ($config->insert_detected_image() && $this->body->hasChildNodes() && isset($this->opengraph['og:image']) && mb_substr($this->opengraph['og:image'],
                        0, 4) === 'http') {
                        $elems = @$xpath->query('.//img', $this->body);
                        if ($elems !== false && $elems->length === 0) {
                            $bodyOwnerDoc = $this->body->ownerDocument;
                            if ($bodyOwnerDoc !== null) {
                                $_new_elem = $bodyOwnerDoc->createDocumentFragment();
                                @$_new_elem->appendXML('<div><img src="'.htmlspecialchars($this->opengraph['og:image']).'" class="ff-og-image-inserted" /></div>');
                                $this->body->insertBefore($_new_elem, $this->body->firstChild);
                            }
                        }
                    }
                }
            }

            $this->success = true;
        }

        if (! $this->success && $tidied && $smart_tidy) {
            $this->debug('Trying again without tidy');
            $this->process($original_html, $url, false, $is_next_page);
        }

        return $this->success;
    }

    public function setUserSubmittedConfig(string $config_string): void
    {
        $this->userSubmittedConfig = SiteConfig::build_from_string($config_string);
    }

    public function getContent(): ?DOMElement
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function getOpenGraph(): array
    {
        return $this->opengraph;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJsonLd(): array
    {
        return $this->jsonld;
    }

    /**
     * @return array<string, string>
     */
    public function getTwitterCard(): array
    {
        return $this->twitterCard;
    }

    public function isNativeAd(): bool
    {
        return $this->nativeAd;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * @return array<int, string>
     */
    public function getAuthors(): array
    {
        return $this->author;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getDate(): ?int
    {
        return $this->date;
    }

    public function getSiteConfig(): ?SiteConfig
    {
        return $this->config;
    }

    public function getParser(): ?string
    {
        return $this->selectedParser;
    }

    public function getNextPageUrl(): ?string
    {
        return $this->nextPageUrl;
    }

    protected function debug(string $msg): void
    {
        if ($this->debug) {
            $mem = round(memory_get_usage() / 1024, 2);
            $memPeak = round(memory_get_peak_usage() / 1024, 2);
            echo '* ', $msg;
            if ($this->debugVerbose) {
                echo ' - mem used: ', $mem, " (peak: $memPeak)";
            }
            echo "\n";
            ob_flush();
            flush();
        }
    }

    private function isDescendant(DOMElement $parent, DOMElement $child): bool
    {
        $node = $child->parentNode;
        while ($node !== null) {
            if ($node->isSameNode($parent)) {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }

    private function cleanAuthor(string $author): string
    {
        if ((mb_strlen($author) >= 3) && (mb_strtolower(mb_substr($author, 0, 3)) === 'by ')) {
            $author = mb_trim(mb_substr($author, 3));
        }

        return $author;
    }

    private function processTitleJsonLD(object $jsonld): void
    {
        if (! $this->isAcceptedJsonLdType($jsonld)) {
            return;
        }
        $headline = $this->getJsonLdAttribute('headline', $jsonld);
        if ($headline && is_string($headline)) {
            $this->jsonld['title'] = $headline;
        }
    }

    private function processDateJsonLD(object $jsonld): void
    {
        if (! $this->isAcceptedJsonLdType($jsonld)) {
            return;
        }
        $date = $this->getJsonLdAttribute('datePublished', $jsonld);
        if (! $date) {
            $date = $this->getJsonLdAttribute('dateCreated', $jsonld);
        }
        if ($date && is_string($date) && strtotime($date) > 0) {
            $this->jsonld['date'] = strtotime($date);
        }
    }

    private function processAuthorJsonLD(object $jsonld): void
    {
        if (! $this->isAcceptedJsonLdType($jsonld)) {
            return;
        }
        $author = $this->getJsonLdAttribute('author', $jsonld);
        if (! $author) {
            return;
        }
        if (is_string($author) && (mb_strpos($author, '://') === false) && mb_strlen($author) < 80) {
            $this->jsonld['author'] = $author;
        } else {
            if (is_object($author)) {
                $authors = [$author];
            } elseif (is_array($author)) {
                $authors = $author;
            } else {
                return;
            }
            $author_names = [];
            foreach ($authors as $author) {
                if (is_object($author)) {
                    $author_type = $this->getJsonLdAttribute('@type', $author, 'string');
                    $author_name = $this->getJsonLdAttribute('name', $author, 'string');
                    if (! $author_type || ! $author_name) {
                        continue;
                    }
                    if ((mb_strtolower($author_type) === 'person') && (mb_strpos($author_name,
                        '://') === false) && (mb_strlen($author_name) < 40)) {
                        $author_names[] = $author_name;
                    }
                    if (mb_strlen(implode(', ', $author_names)) > 80) {
                        break;
                    }
                }
            }
            if (! empty($author_names)) {
                $this->jsonld['author'] = implode(', ', $author_names);
            }
        }
    }

    private function processImageJsonLD(object $jsonld): void
    {
        if (! $this->isAcceptedJsonLdType($jsonld)) {
            return;
        }
        $image = $this->getJsonLdAttribute('image', $jsonld);
        if (! $image) {
            return;
        }
        if (is_string($image)) {
            $images = [$image];
        } elseif (is_array($image)) {
            $images = $image;
        } else {
            return;
        }
        foreach ($images as $image) {
            if (! is_string($image)) {
                continue;
            }
            if ((mb_substr($image, 0, 7) === 'http://') || (mb_substr($image, 0, 8) === 'https://')) {
                $this->jsonld['image'] = $image;
                break;
            }
        }
    }

    /**
     * @return mixed|false
     */
    private function getJsonLdAttribute(string $attr, object $jsonld, ?string $type = null): mixed
    {
        if (! property_exists($jsonld, $attr)) {
            return false;
        }
        $val = $jsonld->$attr;
        if ($type === 'object' && ! is_object($val)) {
            return false;
        }
        if ($type === 'string' && ! is_string($val)) {
            return false;
        }

        return $val;

    }

    private function isAcceptedJsonLdType(object $jsonld): bool
    {
        $type = $this->getJsonLdAttribute('@type', $jsonld);
        if ($type === false) {
            return false;
        }
        if (is_string($type)) {
            $types = [$type];
        } elseif (is_array($type)) {
            $types = $type;
        } else {
            return false;
        }
        foreach ($types as $type) {
            if (! is_string($type)) {
                continue;
            }
            if (in_array(mb_strtolower($type),
                ['article', 'newsarticle', 'techarticle', 'reportagenewsarticle', 'blogposting'])) {
                return true;
            }
        }

        return false;
    }
}
