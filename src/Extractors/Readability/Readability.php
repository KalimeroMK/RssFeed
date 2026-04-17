<?php

declare(strict_types=1);

/**
* Arc90's Readability ported to PHP for FiveFilters.org
* Based on readability.js version 1.7.1 (without multi-page support)
* Updated to allow HTML5 parsing with html5lib
* Updated with lightClean mode to preserve more images and youtube/vimeo/viddler embeds
* Updated to allow HTML5 parsing with Gumbo PHP
* ------------------------------------------------------
* Original URL: http://lab.arc90.com/experiments/readability/js/readability.js
* Arc90's project URL: http://lab.arc90.com/experiments/readability/
* JS Source: http://code.google.com/p/arc90labs-readability
* Ported by: Keyvan Minoukadeh, http://www.keyvan.net
* More information: http://fivefilters.org/content-only/
* License: Apache License, Version 2.0
* Requires: PHP5
* Date: 2017-02-05
*/

namespace Kalimeromk\Rssfeed\Extractors\Readability;

use DOMDocument;
use DOMElement;
use DOMAttr;
use DOMXPath;
use Exception;
use stdClass;
use Masterminds\HTML5;

class Readability
{
    public const FLAG_STRIP_UNLIKELYS = 1;

    public const FLAG_WEIGHT_CLASSES = 2;

    public const FLAG_CLEAN_CONDITIONALLY = 4;

    public string $version = '1.7.1-without-multi-page';

    public bool $convertLinksToFootnotes = false;

    public bool $revertForcedParagraphElements = true;

    public ?DOMElement $articleTitle = null;

    public ?DOMElement $articleContent = null;

    public ?DOMDocument $dom = null;

    public ?string $url = null;

    public bool $debug = false;

    public bool $lightClean = true;

    public array $regexps = [
        'unlikelyCandidates' => '/combx|comment|community|disqus|extra|foot|header|menu|remark|rss|shoutbox|sidebar|sponsor|ad-break|agegate|pagination|pager|popup/i',
        'okMaybeItsACandidate' => '/and|article|body|column|main|shadow/i',
        'positive' => '/article|body|content|entry|hentry|main|page|attachment|pagination|post|text|blog|story/i',
        'negative' => '/combx|comment|com-|contact|foot|footer|_nav|footnote|masthead|media|meta|outbrain|promo|related|scroll|shoutbox|sidebar|sponsor|shopping|tags|tool|widget/i',
        'divToPElements' => '/<(a|blockquote|dl|div|img|ol|p|pre|table|ul)/i',
        'replaceBrs' => '/(<br[^>]*>[ \n\r\t]*){2,}/i',
        'replaceFonts' => '/<(\/?)font[^>]*>/i',
        'normalize' => '/\s{2,}/',
        'killBreaks' => '/(<br\s*\/?>(\s|&nbsp;?)*){1,}/',
        'video' => '!//(player\.|www\.)?(youtube\.com|vimeo\.com|viddler\.com|soundcloud\.com|twitch\.tv|openload\.co)!i',
        'skipFootnoteLink' => '/^\s*(\[?[a-z0-9]{1,2}\]?|^|edit|citation needed)\s*$/i',
    ];

    protected ?DOMElement $body = null;

    protected ?string $bodyCache = null;

    protected int $flags = 7;

    protected bool $success = false;

    /**
     * Create instance of Readability
     *
     * @param  string UTF-8 encoded string
     * @param  string (optional) URL associated with HTML (used for footnotes)
     * @param  string which parser to use for turning raw HTML into a DOMDocument (either 'libxml' or 'html5lib')
     */
    public function __construct(string $html, ?string $url = null, string $parser = 'libxml')
    {
        $this->url = $url;
        $html = preg_replace($this->regexps['replaceBrs'], '</p><p>', $html);
        $html = preg_replace($this->regexps['replaceFonts'], '<$1span>', $html);
        if (mb_trim($html) === '') {
            $html = '<html></html>';
        }

        if ($parser === 'gumbo') {
            $html = str_replace('&apos;', "'", $html);
            $this->dom = @\Layershifter\Gumbo\Parser::load($html);
        } elseif ($parser === 'html5lib' || $parser === 'html5php') {
            $html5 = new HTML5(['disable_html_ns' => true]);
            $this->dom = $html5->loadHTML($html);
        }

        if ($this->dom === null) {
            $this->dom = new DOMDocument();
            $this->dom->preserveWhiteSpace = false;
            @$this->dom->loadHTML('<?xml encoding="UTF-8"?>'.$html);
        }

        $this->dom->registerNodeClass('DOMElement', JSLikeHTMLElement::class);
    }

    /**
     * Get article title element
     *
     * @return DOMElement|null
     */
    public function getTitle(): ?DOMElement
    {
        return $this->articleTitle;
    }

    /**
     * Get article content element
     *
     * @return DOMElement|null
     */
    public function getContent(): ?DOMElement
    {
        return $this->articleContent;
    }

    /**
     * Runs readability.
     *
     * Workflow:
     *  1. Prep the document by removing script tags, css, etc.
     *  2. Build readability's DOM tree.
     *  3. Grab the article content from the current dom tree.
     *  4. Replace the current DOM tree with the new one.
     *  5. Read peacefully.
     *
     * @return bool true if we found content, false otherwise
     */
    public function init(): bool
    {
        if (! isset($this->dom->documentElement)) {
            return false;
        }
        $this->removeScripts($this->dom);

        $this->success = true;

        $bodyElems = $this->dom->getElementsByTagName('body');
        if ($bodyElems->length > 0) {
            if ($this->bodyCache === null) {
                $this->bodyCache = $bodyElems->item(0)->innerHTML;
            }
            if ($this->body === null) {
                $this->body = $bodyElems->item(0);
            }
        }

        $this->prepDocument();

        $overlay = $this->dom->createElement('div');
        $innerDiv = $this->dom->createElement('div');
        $articleTitle = $this->getArticleTitle();
        $articleContent = $this->grabArticle();

        if (! $articleContent) {
            $this->success = false;
            $articleContent = $this->dom->createElement('div');
            $articleContent->setAttribute('id', 'readability-content');
            $articleContent->innerHTML = '<p>Sorry, Readability was unable to parse this page for content.</p>';
        }

        $overlay->setAttribute('id', 'readOverlay');
        $innerDiv->setAttribute('id', 'readInner');

        $innerDiv->appendChild($articleTitle);
        $innerDiv->appendChild($articleContent);
        $overlay->appendChild($innerDiv);

        if (! isset($this->body->childNodes)) {
            $this->body = $this->dom->createElement('div');
        }

        $this->body->innerHTML = '';
        $this->body->appendChild($overlay);
        $this->body->removeAttribute('style');

        $this->postProcessContent($articleContent);

        $this->articleTitle = $articleTitle;
        $this->articleContent = $articleContent;

        return $this->success;
    }

    /**
     * Run any post-process modifications to article content as necessary.
     *
     * @param  DOMElement $articleContent
     * @return void
     */
    public function postProcessContent(DOMElement $articleContent): void
    {
        if ($this->convertLinksToFootnotes && ! preg_match('/wikipedia\.org/', (string) @$this->url)) {
            $this->addFootnotes($articleContent);
        }
    }

    /**
     * For easier reading, convert this document to have footnotes at the bottom rather than inline links.
     *
     * @see http://www.roughtype.com/archives/2010/05/experiments_in.php
     *
     * @param  DOMElement $articleContent
     * @return void
     */
    public function addFootnotes(DOMElement $articleContent): void
    {
        $footnotesWrapper = $this->dom->createElement('div');
        $footnotesWrapper->setAttribute('id', 'readability-footnotes');
        $footnotesWrapper->innerHTML = '<h3>References</h3>';

        $articleFootnotes = $this->dom->createElement('ol');
        $articleFootnotes->setAttribute('id', 'readability-footnotes-list');
        $footnotesWrapper->appendChild($articleFootnotes);

        $articleLinks = $articleContent->getElementsByTagName('a');

        $linkCount = 0;
        for ($i = 0; $i < $articleLinks->length; $i++) {
            $articleLink = $articleLinks->item($i);
            $footnoteLink = $articleLink->cloneNode(true);
            $refLink = $this->dom->createElement('a');
            $footnote = $this->dom->createElement('li');
            $linkDomain = @parse_url($footnoteLink->getAttribute('href'), PHP_URL_HOST);
            if (! $linkDomain && isset($this->url)) {
                $linkDomain = @parse_url($this->url, PHP_URL_HOST);
            }
            $linkText = $this->getInnerText($articleLink);

            if ((mb_strpos($articleLink->getAttribute('class'),
                'readability-DoNotFootnote') !== false) || preg_match($this->regexps['skipFootnoteLink'],
                    $linkText)) {
                continue;
            }

            $linkCount++;

            $refLink->setAttribute('href', '#readabilityFootnoteLink-'.$linkCount);
            $refLink->innerHTML = '<small><sup>['.$linkCount.']</sup></small>';
            $refLink->setAttribute('class', 'readability-DoNotFootnote');
            $refLink->setAttribute('style', 'color: inherit;');

            if ($articleLink->parentNode->lastChild === $articleLink) {
                $articleLink->parentNode->appendChild($refLink);
            } else {
                $articleLink->parentNode->insertBefore($refLink, $articleLink->nextSibling);
            }

            $articleLink->setAttribute('style', 'color: inherit; text-decoration: none;');
            $articleLink->setAttribute('name', 'readabilityLink-'.$linkCount);

            $footnote->innerHTML = '<small><sup><a href="#readabilityLink-'.$linkCount.'" title="Jump to Link in Article">^</a></sup></small> ';

            $footnoteLink->innerHTML = ($footnoteLink->getAttribute('title') !== '' ? $footnoteLink->getAttribute('title') : $linkText);
            $footnoteLink->setAttribute('name', 'readabilityFootnoteLink-'.$linkCount);

            $footnote->appendChild($footnoteLink);
            if ($linkDomain) {
                $footnote->innerHTML = $footnote->innerHTML.'<small> ('.$linkDomain.')</small>';
            }

            $articleFootnotes->appendChild($footnote);
        }

        if ($linkCount > 0) {
            $articleContent->appendChild($footnotesWrapper);
        }
    }

    /**
     * Reverts P elements with class 'readability-styled'
     * to text nodes - which is what they were before.
     *
     * @param  DOMElement $articleContent
     * @return void
     */
    public function revertReadabilityStyledElements(DOMElement $articleContent): void
    {
        $xpath = new DOMXPath($articleContent->ownerDocument);
        $elems = $xpath->query('.//p[@class="readability-styled"]', $articleContent);
        for ($i = $elems->length - 1; $i >= 0; $i--) {
            $e = $elems->item($i);
            $e->parentNode->replaceChild($articleContent->ownerDocument->createTextNode($e->textContent), $e);
        }
    }

    /**
     * Prepare the article node for display. Clean out any inline styles,
     * iframes, forms, strip extraneous <p> tags, etc.
     *
     * @param  DOMElement $articleContent
     * @return void
     */
    public function prepArticle(DOMElement $articleContent): void
    {
        $this->cleanStyles($articleContent);
        $this->killBreaks($articleContent);
        if ($this->revertForcedParagraphElements) {
            $this->revertReadabilityStyledElements($articleContent);
        }

        $this->cleanConditionally($articleContent, 'form');
        $this->clean($articleContent, 'object');
        $this->clean($articleContent, 'h1');

        if (! $this->lightClean && ($articleContent->getElementsByTagName('h2')->length === 1)) {
            $this->clean($articleContent, 'h2');
        }
        $this->clean($articleContent, 'iframe');

        $this->cleanHeaders($articleContent);

        $this->cleanConditionally($articleContent, 'table');
        $this->cleanConditionally($articleContent, 'ul');
        $this->cleanConditionally($articleContent, 'div');

        $articleParagraphs = $articleContent->getElementsByTagName('p');
        for ($i = $articleParagraphs->length - 1; $i >= 0; $i--) {
            $imgCount = $articleParagraphs->item($i)->getElementsByTagName('img')->length;
            $embedCount = $articleParagraphs->item($i)->getElementsByTagName('embed')->length;
            $objectCount = $articleParagraphs->item($i)->getElementsByTagName('object')->length;
            $iframeCount = $articleParagraphs->item($i)->getElementsByTagName('iframe')->length;

            if ($imgCount === 0 && $embedCount === 0 && $objectCount === 0 && $iframeCount === 0 && $this->getInnerText($articleParagraphs->item($i),
                false) === '') {
                $articleParagraphs->item($i)->parentNode->removeChild($articleParagraphs->item($i));
            }
        }

        try {
            $articleContent->innerHTML = preg_replace('/<br[^>]*>\s*<p/i', '<p', $articleContent->innerHTML);
        } catch (Exception $e) {
            $this->dbg('Cleaning innerHTML of breaks failed. This is an IE strict-block-elements bug. Ignoring.: '.$e);
        }
    }

    /**
     * Remove script tags from document
     *
     * @param  DOMDocument|DOMElement $doc
     * @return void
     */
    public function removeScripts(DOMDocument|DOMElement $doc): void
    {
        $scripts = $doc->getElementsByTagName('script');
        for ($i = $scripts->length - 1; $i >= 0; $i--) {
            try {
                $scripts->item($i)->parentNode->removeChild($scripts->item($i));
            } catch (Exception $e) {
            }
        }
    }

    /**
     * Get the inner text of a node.
     * This also strips out any excess whitespace to be found.
     *
     * @param  DOMElement|null $e
     * @param  bool  $normalizeSpaces  (default: true)
     * @return string
     */
    public function getInnerText(?DOMElement $e, bool $normalizeSpaces = true): string
    {
        $textContent = '';

        if (! isset($e->textContent) || $e->textContent === '') {
            return '';
        }

        $textContent = mb_trim($e->textContent);

        if ($normalizeSpaces) {
            return preg_replace($this->regexps['normalize'], ' ', $textContent);
        }

        return $textContent;
    }

    /**
     * Get the number of times a string $s appears in the node $e.
     *
     * @param  DOMElement  $e
     * @param  string $s - what to count. Default is ","
     * @return int
     */
    public function getCharCount(DOMElement $e, string $s = ','): int
    {
        return mb_substr_count($this->getInnerText($e), $s);
    }

    /**
     * Remove the style attribute on every $e and under.
     *
     * @param  DOMElement|null  $e
     * @return void
     */
    public function cleanStyles(?DOMElement $e): void
    {
        if (! is_object($e)) {
            return;
        }
        $elems = $e->getElementsByTagName('*');
        foreach ($elems as $elem) {
            $elem->removeAttribute('style');
        }
    }

    /**
     * Get the density of links as a percentage of the content
     * This is the amount of text that is inside a link divided by the total text in the node.
     *
     * @param  DOMElement  $e
     * @return float
     */
    public function getLinkDensity(DOMElement $e): float
    {
        $links = $e->getElementsByTagName('a');
        $textLength = mb_strlen($this->getInnerText($e));
        $linkLength = 0;
        for ($i = 0, $il = $links->length; $i < $il; $i++) {
            $linkLength += mb_strlen($this->getInnerText($links->item($i)));
        }
        if ($textLength > 0) {
            return $linkLength / $textLength;
        }

        return 0;
    }

    /**
     * Get an elements class/id weight. Uses regular expressions to tell if this
     * element looks good or bad.
     *
     * @param  DOMElement  $e
     * @return int
     */
    public function getClassWeight(DOMElement $e): int
    {
        if (! $this->flagIsActive(self::FLAG_WEIGHT_CLASSES)) {
            return 0;
        }

        $weight = 0;

        if ($e->hasAttribute('class') && $e->getAttribute('class') !== '') {
            if (preg_match($this->regexps['negative'], $e->getAttribute('class'))) {
                $weight -= 25;
            }
            if (preg_match($this->regexps['positive'], $e->getAttribute('class'))) {
                $weight += 25;
            }
        }

        if ($e->hasAttribute('id') && $e->getAttribute('id') !== '') {
            if (preg_match($this->regexps['negative'], $e->getAttribute('id'))) {
                $weight -= 25;
            }
            if (preg_match($this->regexps['positive'], $e->getAttribute('id'))) {
                $weight += 25;
            }
        }

        return $weight;
    }

    /**
     * Remove extraneous break tags from a node.
     *
     * @param  DOMElement  $node
     * @return void
     */
    public function killBreaks(DOMElement $node): void
    {
        $html = $node->innerHTML;
        $html = preg_replace($this->regexps['killBreaks'], '<br />', $html);
        $node->innerHTML = $html;
    }

    /**
     * Clean a node of all elements of type "tag".
     * (Unless it's a youtube/vimeo video. People love movies.)
     *
     * @param  DOMElement  $e
     * @param  string  $tag
     * @return void
     */
    public function clean(DOMElement $e, string $tag): void
    {
        $targetList = $e->getElementsByTagName($tag);
        $isEmbed = ($tag === 'iframe' || $tag === 'object' || $tag === 'embed');

        for ($y = $targetList->length - 1; $y >= 0; $y--) {
            if ($isEmbed) {
                $attributeValues = '';
                for ($i = 0, $il = $targetList->item($y)->attributes->length; $i < $il; $i++) {
                    $attributeValues .= $targetList->item($y)->attributes->item($i)->value.'|';
                }

                if (preg_match($this->regexps['video'], $attributeValues)) {
                    continue;
                }

                if (preg_match($this->regexps['video'], $targetList->item($y)->innerHTML)) {
                    continue;
                }
            }
            $targetList->item($y)->parentNode->removeChild($targetList->item($y));
        }
    }

    /**
     * Clean an element of all tags of type "tag" if they look fishy.
     * "Fishy" is an algorithm based on content length, classnames,
     * link density, number of images & embeds, etc.
     *
     * @param  DOMElement  $e
     * @param  string  $tag
     * @return void
     */
    public function cleanConditionally(DOMElement $e, string $tag): void
    {
        if (! $this->flagIsActive(self::FLAG_CLEAN_CONDITIONALLY)) {
            return;
        }

        $tagsList = $e->getElementsByTagName($tag);
        $curTagsLength = $tagsList->length;

        for ($i = $curTagsLength - 1; $i >= 0; $i--) {
            $weight = $this->getClassWeight($tagsList->item($i));
            $contentScore = ($tagsList->item($i)->hasAttribute('readability')) ? (int) $tagsList->item($i)->getAttribute('readability') : 0;

            $this->dbg('Cleaning Conditionally '.$tagsList->item($i)->tagName.' ('.$tagsList->item($i)->getAttribute('class').':'.$tagsList->item($i)->getAttribute('id').')'.(($tagsList->item($i)->hasAttribute('readability')) ? (' with score '.$tagsList->item($i)->getAttribute('readability')) : ''));

            if ($weight + $contentScore < 0) {
                $tagsList->item($i)->parentNode->removeChild($tagsList->item($i));
            } elseif ($this->getCharCount($tagsList->item($i), ',') < 10) {
                $p = $tagsList->item($i)->getElementsByTagName('p')->length;
                $img = $tagsList->item($i)->getElementsByTagName('img')->length;
                $li = $tagsList->item($i)->getElementsByTagName('li')->length - 100;
                $input = $tagsList->item($i)->getElementsByTagName('input')->length;
                $a = $tagsList->item($i)->getElementsByTagName('a')->length;

                $embedCount = 0;
                $embeds = $tagsList->item($i)->getElementsByTagName('embed');
                for ($ei = 0, $il = $embeds->length; $ei < $il; $ei++) {
                    if (preg_match($this->regexps['video'], $embeds->item($ei)->getAttribute('src'))) {
                        $embedCount++;
                    }
                }
                $embeds = $tagsList->item($i)->getElementsByTagName('iframe');
                for ($ei = 0, $il = $embeds->length; $ei < $il; $ei++) {
                    if (preg_match($this->regexps['video'], $embeds->item($ei)->getAttribute('src'))) {
                        $embedCount++;
                    }
                }

                $linkDensity = $this->getLinkDensity($tagsList->item($i));
                $contentLength = mb_strlen($this->getInnerText($tagsList->item($i)));
                $toRemove = false;

                if ($this->lightClean) {
                    $this->dbg('Light clean...');
                    if (($img > $p) && ($img > 4)) {
                        $this->dbg(' more than 4 images and more image elements than paragraph elements');
                        $toRemove = true;
                    } else {
                        if ($li > $p && $tag !== 'ul' && $tag !== 'ol') {
                            $this->dbg(' too many <li> elements, and parent is not <ul> or <ol>');
                            $toRemove = true;
                        } else {
                            if ($input > floor($p / 3)) {
                                $this->dbg(' too many <input> elements');
                                $toRemove = true;
                            } else {
                                if ($contentLength < 10 && ($embedCount === 0 && ($img === 0 || $img > 2))) {
                                    $this->dbg(' content length less than 10 chars, 0 embeds and either 0 images or more than 2 images');
                                    $toRemove = true;
                                } else {
                                    if ($weight < 25 && $linkDensity > 0.2) {
                                        $this->dbg(' weight smaller than 25 and link density above 0.2');
                                        $toRemove = true;
                                    } else {
                                        if ($a > 2 && ($weight >= 25 && $linkDensity > 0.5)) {
                                            $this->dbg(' more than 2 links and weight above 25 but link density greater than 0.5');
                                            $toRemove = true;
                                        } else {
                                            if ($embedCount > 3) {
                                                $this->dbg(' more than 3 embeds');
                                                $toRemove = true;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $this->dbg('Standard clean...');
                    if ($img > $p) {
                        $this->dbg(' more image elements than paragraph elements');
                        $toRemove = true;
                    } elseif ($li > $p && $tag !== 'ul' && $tag !== 'ol') {
                        $this->dbg(' too many <li> elements, and parent is not <ul> or <ol>');
                        $toRemove = true;
                    } elseif ($input > floor($p / 3)) {
                        $this->dbg(' too many <input> elements');
                        $toRemove = true;
                    } elseif ($contentLength < 25 && ($img === 0 || $img > 2)) {
                        $this->dbg(' content length less than 25 chars and 0 images, or more than 2 images');
                        $toRemove = true;
                    } elseif ($weight < 25 && $linkDensity > 0.2) {
                        $this->dbg(' weight smaller than 25 and link density above 0.2');
                        $toRemove = true;
                    } elseif ($weight >= 25 && $linkDensity > 0.5) {
                        $this->dbg(' weight above 25 but link density greater than 0.5');
                        $toRemove = true;
                    } elseif (($embedCount === 1 && $contentLength < 75) || $embedCount > 1) {
                        $this->dbg(' 1 embed and content length smaller than 75 chars, or more than one embed');
                        $toRemove = true;
                    }
                }

                if ($toRemove) {
                    $tagsList->item($i)->parentNode->removeChild($tagsList->item($i));
                }
            }
        }
    }

    /**
     * Clean out spurious headers from an Element. Checks things like classnames and link density.
     *
     * @param  DOMElement  $e
     * @return void
     */
    public function cleanHeaders(DOMElement $e): void
    {
        for ($headerIndex = 1; $headerIndex < 3; $headerIndex++) {
            $headers = $e->getElementsByTagName('h'.$headerIndex);
            for ($i = $headers->length - 1; $i >= 0; $i--) {
                if ($this->getClassWeight($headers->item($i)) < 0 || $this->getLinkDensity($headers->item($i)) > 0.33) {
                    $headers->item($i)->parentNode->removeChild($headers->item($i));
                }
            }
        }
    }

    public function flagIsActive(int $flag): bool
    {
        return ($this->flags & $flag) > 0;
    }

    public function addFlag(int $flag): void
    {
        $this->flags = $this->flags | $flag;
    }

    public function removeFlag(int $flag): void
    {
        $this->flags = $this->flags & ~$flag;
    }

    /**
     * Debug
     */
    protected function dbg(string $msg): void
    {
        if ($this->debug) {
            echo '* ', $msg, "\n";
        }
    }

    /**
     * Get the article title as an H1.
     *
     * @return DOMElement
     */
    protected function getArticleTitle(): DOMElement
    {
        $curTitle = '';
        $origTitle = '';

        try {
            $curTitle = $origTitle = $this->getInnerText($this->dom->getElementsByTagName('title')->item(0));
        } catch (Exception $e) {
        }

        if (preg_match('/ [\|\-] /', $curTitle)) {
            $curTitle = preg_replace('/(.*)[\|\-] .*/i', '$1', $origTitle);

            if (count(explode(' ', $curTitle)) < 3) {
                $curTitle = preg_replace('/[^\|\-]*[\|\-](.*)/i', '$1', $origTitle);
            }
        } elseif (mb_strpos($curTitle, ': ') !== false) {
            $curTitle = preg_replace('/.*:(.*)/i', '$1', $origTitle);

            if (count(explode(' ', $curTitle)) < 3) {
                $curTitle = preg_replace('/[^:]*[:](.*)/i', '$1', $origTitle);
            }
        } elseif (mb_strlen($curTitle) > 150 || mb_strlen($curTitle) < 15) {
            $hOnes = $this->dom->getElementsByTagName('h1');
            if ($hOnes->length === 1) {
                $curTitle = $this->getInnerText($hOnes->item(0));
            }
        }

        $curTitle = mb_trim($curTitle);

        if (count(explode(' ', $curTitle)) <= 4) {
            $curTitle = $origTitle;
        }

        $articleTitle = $this->dom->createElement('h1');
        $articleTitle->innerHTML = $curTitle;

        return $articleTitle;
    }

    /**
     * Prepare the HTML document for readability to scrape it.
     * This includes things like stripping javascript, CSS, and handling terrible markup.
     *
     * @return void
     */
    protected function prepDocument(): void
    {
        if ($this->body === null) {
            $this->body = $this->dom->createElement('body');
            $this->dom->documentElement->appendChild($this->body);
        }
        $this->body->setAttribute('id', 'readabilityBody');

        $styleTags = $this->dom->getElementsByTagName('style');
        for ($i = $styleTags->length - 1; $i >= 0; $i--) {
            try {
                @$styleTags->item($i)->parentNode->removeChild($styleTags->item($i));
            } catch (Exception $e) {
            }
        }
    }

    /**
     * Initialize a node with the readability object. Also checks the
     * className/id for special names to add to its score.
     *
     * @param  DOMElement $node
     * @return void
     */
    protected function initializeNode(DOMElement $node): void
    {
        $readability = $this->dom->createAttribute('readability');
        $readability->value = '0';
        $node->setAttributeNode($readability);

        switch (mb_strtoupper($node->tagName)) {
            case 'DIV':
                $this->addReadabilityScore($readability, 5);
                break;

            case 'PRE':
            case 'TD':
            case 'BLOCKQUOTE':
                $this->addReadabilityScore($readability, 3);
                break;

            case 'ADDRESS':
            case 'OL':
            case 'UL':
            case 'DL':
            case 'DD':
            case 'DT':
            case 'LI':
            case 'FORM':
                $this->addReadabilityScore($readability, -3);
                break;

            case 'H1':
            case 'H2':
            case 'H3':
            case 'H4':
            case 'H5':
            case 'H6':
            case 'TH':
                $this->addReadabilityScore($readability, -5);
                break;
        }
        $this->addReadabilityScore($readability, $this->getClassWeight($node));
    }

    /**
     * Add to readability score on a DOMAttr safely for PHP 8.4+ compatibility.
     */
    protected function addReadabilityScore(DOMAttr $attr, float $delta): void
    {
        $attr->value = (string) ((float) $attr->value + $delta);
    }

    /**
     * Multiply readability score on a DOMAttr safely for PHP 8.4+ compatibility.
     */
    protected function multiplyReadabilityScore(DOMAttr $attr, float $factor): void
    {
        $attr->value = (string) ((float) $attr->value * $factor);
    }

    /**
     * grabArticle - Using a variety of metrics (content score, classname, element types), find the content that is
     *               most likely to be the stuff a user wants to read. Then return it wrapped up in a div.
     *
     * @param  DOMDocument|DOMElement|null $page
     * @return DOMElement|false
     */
    protected function grabArticle(DOMDocument|DOMElement|null $page = null): DOMElement|false
    {
        $stripUnlikelyCandidates = $this->flagIsActive(self::FLAG_STRIP_UNLIKELYS);
        if (! $page) {
            $page = $this->dom;
        }
        $allElements = $page->getElementsByTagName('*');

        $node = null;
        $nodesToScore = [];
        for ($nodeIndex = 0; ($node = $allElements->item($nodeIndex)); $nodeIndex++) {
            $tagName = mb_strtoupper($node->tagName);

            if ($stripUnlikelyCandidates) {
                $unlikelyMatchString = $node->getAttribute('class').$node->getAttribute('id');
                if (
                    preg_match($this->regexps['unlikelyCandidates'], $unlikelyMatchString) &&
                    ! preg_match($this->regexps['okMaybeItsACandidate'], $unlikelyMatchString) &&
                    $tagName !== 'BODY'
                ) {
                    $this->dbg('Removing unlikely candidate - '.$unlikelyMatchString);
                    $node->parentNode->removeChild($node);
                    $nodeIndex--;

                    continue;
                }
            }

            if ($tagName === 'P' || $tagName === 'TD' || $tagName === 'PRE') {
                $nodesToScore[] = $node;
            }

            if ($tagName === 'DIV') {
                if (! preg_match($this->regexps['divToPElements'], $node->innerHTML)) {
                    $newNode = $this->dom->createElement('p');
                    try {
                        $newNode->innerHTML = $node->innerHTML;
                        $node->parentNode->replaceChild($newNode, $node);
                        $nodeIndex--;
                        $nodesToScore[] = $node;
                    } catch (Exception $e) {
                        $this->dbg('Could not alter div to p, reverting back to div.: '.$e);
                    }
                } else {
                    for ($i = 0, $il = $node->childNodes->length; $i < $il; $i++) {
                        $childNode = $node->childNodes->item($i);
                        if ($childNode->nodeType === 3) {
                            $p = $this->dom->createElement('p');
                            $p->innerHTML = $childNode->nodeValue;
                            $p->setAttribute('style', 'display: inline;');
                            $p->setAttribute('class', 'readability-styled');
                            $childNode->parentNode->replaceChild($p, $childNode);
                        }
                    }
                }
            }
        }

        $candidates = [];
        for ($pt = 0; $pt < count($nodesToScore); $pt++) {
            $parentNode = $nodesToScore[$pt]->parentNode;
            $grandParentNode = ! $parentNode ? null : (($parentNode->parentNode instanceof DOMElement) ? $parentNode->parentNode : null);
            $innerText = $this->getInnerText($nodesToScore[$pt]);

            if (! $parentNode || ! isset($parentNode->tagName)) {
                continue;
            }

            if (mb_strlen($innerText) < 25) {
                continue;
            }

            if (! $parentNode->hasAttribute('readability')) {
                $this->initializeNode($parentNode);
                $candidates[] = $parentNode;
            }

            if ($grandParentNode && ! $grandParentNode->hasAttribute('readability') && isset($grandParentNode->tagName)) {
                $this->initializeNode($grandParentNode);
                $candidates[] = $grandParentNode;
            }

            $contentScore = 0;

            $contentScore++;

            $contentScore += count(explode(',', $innerText));

            $contentScore += min(floor(mb_strlen($innerText) / 100), 3);

            $this->addReadabilityScore($parentNode->getAttributeNode('readability'), $contentScore);

            if ($grandParentNode) {
                $this->addReadabilityScore($grandParentNode->getAttributeNode('readability'), $contentScore / 2);
            }
        }

        $topCandidate = null;
        for ($c = 0, $cl = count($candidates); $c < $cl; $c++) {
            $readability = $candidates[$c]->getAttributeNode('readability');
            $this->multiplyReadabilityScore($readability, 1 - $this->getLinkDensity($candidates[$c]));

            $this->dbg('Candidate: '.$candidates[$c]->tagName.' ('.$candidates[$c]->getAttribute('class').':'.$candidates[$c]->getAttribute('id').') with score '.$readability->value);

            if (! $topCandidate || $readability->value > (int) $topCandidate->getAttribute('readability')) {
                $topCandidate = $candidates[$c];
            }
        }

        if ($topCandidate === null || mb_strtoupper($topCandidate->tagName) === 'BODY') {
            $topCandidate = $this->dom->createElement('div');
            if ($page instanceof DOMDocument) {
                if (isset($page->documentElement)) {
                    $topCandidate->innerHTML = $page->documentElement->innerHTML;
                    $page->documentElement->innerHTML = '';
                    $page->documentElement->appendChild($topCandidate);
                }
            } else {
                $topCandidate->innerHTML = $page->innerHTML;
                $page->innerHTML = '';
                $page->appendChild($topCandidate);
            }
            $this->initializeNode($topCandidate);
        }

        $articleContent = $this->dom->createElement('div');
        $articleContent->setAttribute('id', 'readability-content');
        $siblingScoreThreshold = max(10, ((int) $topCandidate->getAttribute('readability')) * 0.2);
        if (isset($topCandidate->parentNode)) {
            $siblingNodes = $topCandidate->parentNode->childNodes;
        }
        if (! isset($siblingNodes)) {
            $siblingNodes = new stdClass();
            $siblingNodes->length = 0;
        }

        for ($s = 0, $sl = $siblingNodes->length; $s < $sl; $s++) {
            $siblingNode = $siblingNodes->item($s);
            $append = false;

            $this->dbg('Looking at sibling node: '.$siblingNode->nodeName.(($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode->hasAttribute('readability')) ? (' with score '.$siblingNode->getAttribute('readability')) : ''));

            if ($siblingNode === $topCandidate) {
                $append = true;
            }

            $contentBonus = 0;
            if ($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode->getAttribute('class') === $topCandidate->getAttribute('class') && $topCandidate->getAttribute('class') !== '') {
                $contentBonus += ((int) $topCandidate->getAttribute('readability')) * 0.2;
            }

            if ($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode->hasAttribute('readability') && (((int) $siblingNode->getAttribute('readability')) + $contentBonus) >= $siblingScoreThreshold) {
                $append = true;
            }

            if (mb_strtoupper($siblingNode->nodeName) === 'P') {
                $linkDensity = $this->getLinkDensity($siblingNode);
                $nodeContent = $this->getInnerText($siblingNode);
                $nodeLength = mb_strlen($nodeContent);

                if ($nodeLength > 80 && $linkDensity < 0.25) {
                    $append = true;
                } elseif ($nodeLength < 80 && $linkDensity === 0 && preg_match('/\.( |$)/', $nodeContent)) {
                    $append = true;
                }
            }

            if ($append) {
                $this->dbg('Appending node: '.$siblingNode->nodeName);

                $nodeToAppend = null;
                $sibNodeName = mb_strtoupper($siblingNode->nodeName);
                if ($sibNodeName !== 'DIV' && $sibNodeName !== 'P') {
                    $this->dbg('Altering siblingNode of '.$sibNodeName.' to div.');
                    $nodeToAppend = $this->dom->createElement('div');
                    try {
                        $nodeToAppend->setAttribute('id', $siblingNode->getAttribute('id'));
                        $nodeToAppend->innerHTML = $siblingNode->innerHTML;
                    } catch (Exception $e) {
                        $this->dbg('Could not alter siblingNode to div, reverting back to original.');
                        $nodeToAppend = $siblingNode;
                        $s--;
                        $sl--;
                    }
                } else {
                    $nodeToAppend = $siblingNode;
                    $s--;
                    $sl--;
                }

                $nodeToAppend->removeAttribute('class');

                $articleContent->appendChild($nodeToAppend);
            }
        }

        $this->prepArticle($articleContent);

        if (mb_strlen($this->getInnerText($articleContent, false)) < 250) {
            if (! isset($this->body->childNodes)) {
                $this->body = $this->dom->createElement('body');
            }
            $this->body->innerHTML = $this->bodyCache;

            if ($this->flagIsActive(self::FLAG_STRIP_UNLIKELYS)) {
                $this->removeFlag(self::FLAG_STRIP_UNLIKELYS);

                return $this->grabArticle($this->body);
            }
            if ($this->flagIsActive(self::FLAG_WEIGHT_CLASSES)) {
                $this->removeFlag(self::FLAG_WEIGHT_CLASSES);

                return $this->grabArticle($this->body);
            }
            if ($this->flagIsActive(self::FLAG_CLEAN_CONDITIONALLY)) {
                $this->removeFlag(self::FLAG_CLEAN_CONDITIONALLY);

                return $this->grabArticle($this->body);
            }

            return false;
        }

        return $articleContent;
    }
}
