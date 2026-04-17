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
            $this->config = $this->userSubmittedConfig;
            if ($this->config->autodetect_on_failure()) {
                $this->debug('Merging user-submitted site config with site config files associated with this URL and/or content');
                $this->config->append($this->buildSiteConfig($url, $html));
            }
        } else {
            $this->config = $this->buildSiteConfig($url, $html);
        }

        if (! empty($this->config->find_string)) {
            if (count($this->config->find_string) === count($this->config->replace_string)) {
                $html = str_replace($this->config->find_string, $this->config->replace_string, $html, $_count);
                $this->debug("Strings replaced: $_count (find_string and/or replace_string)");
            } else {
                $this->debug('Skipped string replacement - incorrect number of find-replace strings in site config');
            }
            unset($_count);
        }

        $_parser = $this->defaultParser;
        if ($this->allowParserOverride && $this->parserOverride) {
            $_parser = $this->parserOverride;
        } elseif ($this->allowParserOverride && ($this->config->parser($use_default = false) !== null)) {
            $_parser = $this->config->parser($use_default = false);
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
        if ($this->config->tidy() && function_exists('tidy_parse_string') && $smart_tidy) {
            if (($_parser === 'gumbo' || $_parser === 'html5php') && ($this->config->tidy === null)) {
                // No Tidy
            } else {
                $this->debug('Using Tidy');
                $tidy = tidy_parse_string($html, self::$tidy_config, 'UTF8');
                if (tidy_clean_repair($tidy)) {
                    $original_html = $html;
                    $tidied = true;
                    $html = $tidy->value;
                }
                unset($tidy);
            }
        }

        $this->debug("Attempting to parse HTML with $_parser");
        $this->readability = new Readability($html, $url, $_parser);

        $xpath = new DOMXPath($this->readability->dom);

        foreach ($this->config->next_page_link as $pattern) {
            $elems = @$xpath->evaluate($pattern, $this->readability->dom);
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

        foreach ($this->config->native_ad_clue as $pattern) {
            $elems = @$xpath->evaluate($pattern, $this->readability->dom);
            if ($elems instanceof DOMNodeList && $elems->length > 0) {
                $this->nativeAd = true;
                break;
            }
        }

        foreach ($this->config->title as $pattern) {
            $elems = @$xpath->evaluate($pattern, $this->readability->dom);
            if (is_string($elems)) {
                $this->title = mb_trim($elems);
                $this->debug('Title expression evaluated as string: '.$this->title);
                $this->debug("...XPath match: $pattern");
                break;
            }
            if ($elems instanceof DOMNodeList && $elems->length > 0) {
                $this->title = $elems->item(0)->textContent;
                $this->debug('Title matched: '.$this->title);
                $this->debug("...XPath match: $pattern");
                try {
                    @$elems->item(0)->parentNode->removeChild($elems->item(0));
                } catch (DOMException $e) {
                    // do nothing
                }
                break;
            }
        }

        if (empty($this->author)) {
            foreach ($this->config->author as $pattern) {
                $elems = @$xpath->evaluate($pattern, $this->readability->dom);
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
            $elems = @$xpath->evaluate($pattern, $this->readability->dom);
            if (is_string($elems)) {
                if (mb_trim($elems) !== '') {
                    $this->language = mb_trim($elems);
                    $this->debug('Language matched: '.$this->language);
                    break;
                }
            } elseif ($elems instanceof DOMNodeList && $elems->length > 0) {
                foreach ($elems as $elem) {
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
            $this->readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Extracting Open Graph elements');
            foreach ($elems as $elem) {
                if ($elem->hasAttribute('content')) {
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
            $this->readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Extracting Twiter Card elements');
            foreach ($elems as $elem) {
                if ($elem->hasAttribute('content')) {
                    $_prop = mb_strtolower($elem->getAttribute('name'));
                    $_val = $elem->getAttribute('content');
                    if (! isset($this->twitterCard[$_prop])) {
                        $this->twitterCard[$_prop] = $_val;
                    }
                }
            }
            unset($_prop, $_val);
        }

        foreach ($this->config->date as $pattern) {
            $elems = @$xpath->evaluate($pattern, $this->readability->dom);
            $dateValue = null;
            if (is_string($elems)) {
                $dateValue = strtotime(mb_trim($elems, "; \t\n\r\0\x0B"));
            } elseif ($elems instanceof DOMNodeList && $elems->length > 0) {
                $dateStr = $elems->item(0)->textContent;
                $dateValue = strtotime(mb_trim($dateStr, "; \t\n\r\0\x0B"));
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

        foreach ($this->config->strip as $pattern) {
            $elems = @$xpath->query($pattern, $this->readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('Stripping '.$elems->length.' elements (strip: '.$pattern.')');
                for ($i = $elems->length - 1; $i >= 0; $i--) {
                    if ($elems->item($i)->parentNode) {
                        if ($elems->item($i) instanceof DOMAttr) {
                            $elems->item($i)->parentNode->removeAttributeNode($elems->item($i));
                        } else {
                            $elems->item($i)->parentNode->removeChild($elems->item($i));
                        }
                    }
                }
            }
        }

        foreach ($this->config->strip_id_or_class as $string) {
            $string = strtr($string, ["'" => '', '"' => '']);
            $elems = @$xpath->query("//*[contains(@class, '$string') or contains(@id, '$string')]",
                $this->readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('Stripping '.$elems->length.' elements (strip_id_or_class: '.$string.')');
                for ($i = $elems->length - 1; $i >= 0; $i--) {
                    $elems->item($i)->parentNode->removeChild($elems->item($i));
                }
            }
        }

        foreach ($this->config->strip_image_src as $string) {
            $string = strtr($string, ["'" => '', '"' => '']);
            $elems = @$xpath->query("//img[contains(@src, '$string')]", $this->readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('Stripping '.$elems->length.' elements (strip_image_src: '.$string.')');
                for ($i = $elems->length - 1; $i >= 0; $i--) {
                    $elems->item($i)->parentNode->removeChild($elems->item($i));
                }
            }
        }

        $elems = @$xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' entry-unrelated ') or contains(concat(' ',normalize-space(@class),' '),' instapaper_ignore ')]",
            $this->readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Stripping '.$elems->length.' .entry-unrelated,.instapaper_ignore elements');
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $elems->item($i)->parentNode->removeChild($elems->item($i));
            }
        }

        $elems = @$xpath->query("//*[contains(@style,'display:none')]", $this->readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Stripping '.$elems->length.' elements with inline display:none style');
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $elems->item($i)->parentNode->removeChild($elems->item($i));
            }
        }

        $elems = $xpath->query("//a[not(./*) and normalize-space(.)='']", $this->readability->dom);
        if ($elems && $elems->length > 0) {
            $this->debug('Stripping '.$elems->length.' empty a elements');
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $elems->item($i)->parentNode->removeChild($elems->item($i));
            }
        }

        foreach ($this->config->body as $pattern) {
            $elems = @$xpath->query($pattern, $this->readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('Body matched');
                $this->debug("...XPath match: $pattern");
                if ($elems->length === 1) {
                    $this->body = $elems->item(0);
                    if ($this->config->prune()) {
                        $this->debug('...pruning content');
                        $this->readability->prepArticle($this->body);
                    }
                    break;
                }
                $this->body = $this->readability->dom->createElement('div');
                $this->debug($elems->length.' body elems found');
                foreach ($elems as $elem) {
                    if (! isset($elem->parentNode)) {
                        continue;
                    }
                    $isDescendant = false;
                    foreach ($this->body->childNodes as $parent) {
                        if ($this->isDescendant($parent, $elem)) {
                            $isDescendant = true;
                            break;
                        }
                    }
                    if ($isDescendant) {
                        $this->debug('...element is child of another body element, skipping.');
                    } else {
                        if ($this->config->prune()) {
                            $this->debug('Pruning content');
                            $this->readability->prepArticle($elem);
                        }
                        $this->debug('...element added to body');
                        $this->body->appendChild($elem);
                    }
                }
                if ($this->body->hasChildNodes()) {
                    break;
                }

            }
        }

        $detect_title = $detect_body = $detect_author = $detect_date = false;
        if (! isset($this->title)) {
            if (empty($this->config->title) || $this->config->autodetect_on_failure()) {
                $detect_title = true;
            }
        }
        if (! isset($this->body)) {
            if (empty($this->config->body) || $this->config->autodetect_on_failure()) {
                $detect_body = true;
            }
        }
        if (empty($this->author)) {
            if (empty($this->config->author) || $this->config->autodetect_on_failure()) {
                $detect_author = true;
            }
        }
        if (! isset($this->date)) {
            if (empty($this->config->date) || $this->config->autodetect_on_failure()) {
                $detect_date = true;
            }
        }

        if (! $this->config->skip_json_ld()) {
            $elems = @$xpath->query("//script[@type='application/ld+json']", $this->readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('JSON+LD: found script tag');
                $jsonld = [];
                foreach ($elems as $elem) {
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
                $this->readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('hNews: found hentry');
                $hentry = $elems->item(0);

                if ($detect_title) {
                    $elems = @$xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' entry-title ')]",
                        $hentry);
                    if ($elems && $elems->length > 0) {
                        $this->title = $elems->item(0)->textContent;
                        $this->debug('hNews: found entry-title: '.$this->title);
                        $elems->item(0)->parentNode->removeChild($elems->item(0));
                        $detect_title = false;
                    }
                }

                if ($detect_date) {
                    $elems = @$xpath->query(".//time[@pubdate or @pubDate] | .//abbr[contains(concat(' ',normalize-space(@class),' '),' published ')]",
                        $hentry);
                    if ($elems && $elems->length > 0) {
                        $this->date = strtotime(mb_trim($elems->item(0)->textContent));
                        if ($this->date) {
                            $this->debug('hNews: found publication date: '.date('Y-m-d H:i:s', $this->date));
                            $detect_date = false;
                        } else {
                            $this->date = null;
                        }
                    }
                }

                if ($detect_author) {
                    $elems = @$xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' vcard ') and (contains(concat(' ',normalize-space(@class),' '),' author ') or contains(concat(' ',normalize-space(@class),' '),' byline '))]",
                        $hentry);
                    if ($elems && $elems->length > 0) {
                        $author = $elems->item(0);
                        $fn = @$xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' fn ')]", $author);
                        if ($fn && $fn->length > 0) {
                            foreach ($fn as $_fn) {
                                if (mb_trim($_fn->textContent) !== '') {
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

                if ($detect_body) {
                    $elems = @$xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' entry-content ')]",
                        $hentry);
                    if ($elems && $elems->length > 0) {
                        $this->debug('hNews: found entry-content');
                        if ($elems->length === 1) {
                            $e = $elems->item(0);
                            if (($e->tagName === 'img') || (mb_trim($e->textContent) !== '')) {
                                $this->body = $elems->item(0);
                                if ($this->config->prune()) {
                                    $this->debug('Pruning content');
                                    $this->readability->prepArticle($this->body);
                                }
                                $detect_body = false;
                            } else {
                                $this->debug('hNews: skipping entry-content - appears not to contain content');
                            }
                            unset($e);
                        } else {
                            $this->body = $this->readability->dom->createElement('div');
                            $this->debug($elems->length.' entry-content elems found');
                            foreach ($elems as $elem) {
                                if (! isset($elem->parentNode)) {
                                    continue;
                                }
                                $isDescendant = false;
                                foreach ($this->body->childNodes as $parent) {
                                    if ($this->isDescendant($parent, $elem)) {
                                        $isDescendant = true;
                                        break;
                                    }
                                }
                                if ($isDescendant) {
                                    $this->debug('Element is child of another body element, skipping.');
                                } else {
                                    if ($this->config->prune()) {
                                        $this->debug('Pruning content');
                                        $this->readability->prepArticle($elem);
                                    }
                                    $this->debug('Element added to body');
                                    $this->body->appendChild($elem);
                                }
                            }
                            $detect_body = false;
                        }
                    }
                }
            }
        }

        if ($detect_title) {
            $elems = @$xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_title ')]",
                $this->readability->dom);
            if ($elems && $elems->length > 0) {
                $this->title = $elems->item(0)->textContent;
                $this->debug('Title found (.instapaper_title): '.$this->title);
                $elems->item(0)->parentNode->removeChild($elems->item(0));
                $detect_title = false;
            }
        }
        if ($detect_body) {
            $elems = @$xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_body ')]",
                $this->readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('body found (.instapaper_body)');
                $this->body = $elems->item(0);
                if ($this->config->prune()) {
                    $this->debug('Pruning content');
                    $this->readability->prepArticle($this->body);
                }
                $detect_body = false;
            }
        }

        if ($detect_body) {
            $elems = @$xpath->query("//*[@itemprop='articleBody']", $this->readability->dom);
            if ($elems && $elems->length > 0) {
                $this->debug('body found (Schema.org itemprop="articleBody")');
                if ($elems->length === 1) {
                    $e = $elems->item(0);
                    if (($e->tagName === 'img') || (mb_trim($e->textContent) !== '')) {
                        $this->body = $elems->item(0);
                        if ($this->config->prune()) {
                            $this->debug('Pruning content');
                            $this->readability->prepArticle($this->body);
                        }
                        $detect_body = false;
                    } else {
                        $this->debug('Schema.org: skipping itemprop="articleBody" - appears not to contain content');
                    }
                    unset($e);
                } else {
                    $this->body = $this->readability->dom->createElement('div');
                    $this->debug($elems->length.' itemprop="articleBody" elems found');
                    foreach ($elems as $elem) {
                        if (! isset($elem->parentNode)) {
                            continue;
                        }
                        $isDescendant = false;
                        foreach ($this->body->childNodes as $parent) {
                            if ($this->isDescendant($parent, $elem)) {
                                $isDescendant = true;
                                break;
                            }
                        }
                        if ($isDescendant) {
                            $this->debug('Element is child of another body element, skipping.');
                        } else {
                            if ($this->config->prune()) {
                                $this->debug('Pruning content');
                                $this->readability->prepArticle($elem);
                            }
                            $this->debug('Element added to body');
                            $this->body->appendChild($elem);
                        }
                    }
                    $detect_body = false;
                }
            }
        }

        if ($detect_author) {
            $elems = @$xpath->query("//a[contains(concat(' ',normalize-space(@rel),' '),' author ')]",
                $this->readability->dom);
            if ($elems && $elems->length === 1) {
                $author = mb_trim($elems->item(0)->textContent);
                if ($author !== '') {
                    $this->debug("Author found (rel=\"author\"): $author");
                    $this->author[] = $author;
                    $detect_author = false;
                }
            }
        }

        if ($detect_date) {
            $elems = @$xpath->query("//meta[@property='article:published_time' and @content]", $this->readability->dom);
            if ($elems && $elems->length === 1) {
                $this->date = strtotime(mb_trim($elems->item(0)->getAttribute('content')));
                if ($this->date) {
                    $this->debug('Date found (article:published_time): '.date('Y-m-d H:i:s', $this->date));
                    $detect_date = false;
                } else {
                    $this->date = null;
                }
            }
        }

        if ($detect_date) {
            $elems = @$xpath->query('//time[@pubdate or @pubDate]', $this->readability->dom);
            if ($elems && $elems->length === 1) {
                $this->date = strtotime(mb_trim($elems->item(0)->textContent));
                if ($this->date) {
                    $this->debug('Date found (pubdate marked time element): '.date('Y-m-d H:i:s', $this->date));
                    $detect_date = false;
                } else {
                    $this->date = null;
                }
            }
        }

        $success = false;
        if ($detect_title || $detect_body) {
            $this->debug('Using Readability');
            if (isset($this->body)) {
                $this->body = $this->body->cloneNode(true);
            }
            $success = $this->readability->init();
        }
        if ($detect_title) {
            $this->debug('Detecting title');
            $this->title = $this->readability->getTitle()->textContent;
        }
        if ($detect_body && $success) {
            $this->debug('Detecting body');
            $this->body = $this->readability->getContent();
            if ($this->body->childNodes->length === 1 && $this->body->firstChild->nodeType === XML_ELEMENT_NODE) {
                $this->body = $this->body->firstChild;
            }
            if ($this->config->prune()) {
                $this->debug('Pruning content');
                $this->readability->prepArticle($this->body);
            }
        }
        if (isset($this->body)) {
            $this->readability->removeScripts($this->body);
            if (! $is_next_page) {
                if (isset($this->title) && ($this->title !== '') && $this->body->hasChildNodes()) {
                    $firstChild = $this->body->firstChild;
                    while ($firstChild->nodeType && ($firstChild->nodeType !== XML_ELEMENT_NODE)) {
                        $firstChild = $firstChild->nextSibling;
                    }
                    if (($firstChild !== null) && ($firstChild->nodeType === XML_ELEMENT_NODE)
                        && in_array(mb_strtolower($firstChild->tagName), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])
                        && (mb_strtolower(mb_trim($firstChild->textContent)) === mb_strtolower(mb_trim($this->title)))) {
                        $this->body->removeChild($firstChild);
                    }
                }
            }
            $_dont_self_close = ['iframe', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
            foreach ($_dont_self_close as $_tagname) {
                if ($this->body->tagName === $_tagname) {
                    if (! $this->body->hasChildNodes()) {
                        if ($_tagname === 'iframe') {
                            $this->body->appendChild($this->body->ownerDocument->createTextNode('[embedded content]'));
                        } else {
                            $this->body->appendChild($this->body->ownerDocument->createTextNode(''));
                        }
                    }
                } else {
                    $elems = $this->body->getElementsByTagName($_tagname);
                    for ($i = $elems->length - 1; $i >= 0; $i--) {
                        $e = $elems->item($i);
                        if (! $e->hasChildNodes()) {
                            if ($_tagname === 'iframe') {
                                $e->appendChild($this->body->ownerDocument->createTextNode('[embedded content]'));
                            } else {
                                $e->appendChild($this->body->ownerDocument->createTextNode(''));
                            }
                        }
                    }
                }
            }

            $elems = @$xpath->query('.//img[@data-lazy-src]', $this->body);
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $e = $elems->item($i);
                if ($e->nextSibling !== null && $e->nextSibling->nodeName === 'noscript') {
                    $_new_elem = $e->ownerDocument->createDocumentFragment();
                    @$_new_elem->appendXML($e->nextSibling->innerHTML);
                    $e->nextSibling->parentNode->replaceChild($_new_elem, $e->nextSibling);
                    $e->parentNode->removeChild($e);
                } else {
                    $e->setAttribute('src', $e->getAttribute('data-lazy-src'));
                    $e->removeAttribute('data-lazy-src');
                }
            }
            $elems = @$xpath->query(".//img[(@data-src or @data-srcset) and (contains(@src, 'data:image') or contains(@src, '.gif'))]",
                $this->body);
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $e = $elems->item($i);
                if ($e->hasAttribute('data-src')) {
                    $e->setAttribute('src', $e->getAttribute('data-src'));
                    $e->removeAttribute('data-src');
                }
                if ($e->hasAttribute('data-srcset')) {
                    $e->setAttribute('srcset', $e->getAttribute('data-srcset'));
                    $e->removeAttribute('data-srcset');
                }
            }
            $elems = @$xpath->query(".//source[@data-srcset and (not(@srcset) or contains(@srcset, 'data:image'))]",
                $this->body);
            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $e = $elems->item($i);
                $e->setAttribute('srcset', $e->getAttribute('data-srcset'));
                $e->removeAttribute('data-srcset');
            }
            if ($this->stripImages && $this->body->hasChildNodes()) {
                $elems = @$xpath->query('.//picture | .//figure | .//img | .//figcaption', $this->body);
                if ($elems && $elems->length > 0) {
                    $this->debug('Stripping images: '.$elems->length.' img/picture/figure/figcaption elements');
                    for ($i = $elems->length - 1; $i >= 0; $i--) {
                        @$elems->item($i)->parentNode->removeChild($elems->item($i));
                    }
                }
            } else {
                if (! $is_next_page) {
                    if ($this->config->insert_detected_image() && $this->body->hasChildNodes() && isset($this->opengraph['og:image']) && mb_substr($this->opengraph['og:image'],
                        0, 4) === 'http') {
                        $elems = @$xpath->query('.//img', $this->body);
                        if ($elems->length === 0) {
                            $_new_elem = $this->body->ownerDocument->createDocumentFragment();
                            @$_new_elem->appendXML('<div><img src="'.htmlspecialchars($this->opengraph['og:image']).'" class="ff-og-image-inserted" /></div>');
                            $this->body->insertBefore($_new_elem, $this->body->firstChild);
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
