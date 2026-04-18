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

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Exception;
use Masterminds\HTML5;
use stdClass;

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
     * @param  string  $html  UTF-8 encoded string
     * @param  string|null  $url  (optional) URL associated with HTML (used for footnotes)
     * @param  string  $parser  which parser to use for turning raw HTML into a DOMDocument (either 'libxml' or 'html5lib')
     */
    public function __construct(string $html, ?string $url = null, string $parser = 'libxml')
    {
        $this->url = $url;
        $html = (string) preg_replace($this->regexps['replaceBrs'], '</p><p>', $html);
        $html = (string) preg_replace($this->regexps['replaceFonts'], '<$1span>', $html);
        if (mb_trim($html) === '') {
            $html = '<html></html>';
        }

        if ($parser === 'gumbo') {
            $html = str_replace('&apos;', "'", $html);
            $this->dom = null;
        } elseif ($parser === 'html5lib' || $parser === 'html5php') {
            $html5 = new HTML5(['disable_html_ns' => true]);
            $this->dom = $html5->loadHTML($html);
        }

        if ($this->dom === null) {
            $this->dom = new DOMDocument;
            $this->dom->preserveWhiteSpace = false;
            @$this->dom->loadHTML('<?xml encoding="UTF-8"?>'.$html);
        }

        $this->dom->registerNodeClass('DOMElement', JSLikeHTMLElement::class);
    }

    /**
     * Get article title element
     */
    public function getTitle(): ?DOMElement
    {
        return $this->articleTitle;
    }

    /**
     * Get article content element
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
        if ($this->dom === null || ! isset($this->dom->documentElement)) {
            return false;
        }
        $dom = $this->dom;
        $this->removeScripts($dom);

        $this->success = true;

        $bodyElems = $dom->getElementsByTagName('body');
        if ($bodyElems->length > 0) {
            $bodyItem = $bodyElems->item(0);
            if ($bodyItem instanceof DOMElement) {
                if ($this->bodyCache === null) {
                    $this->bodyCache = $this->getInnerHtml($bodyItem);
                }
                if ($this->body === null) {
                    $this->body = $bodyItem;
                }
            }
        }

        $this->prepDocument();

        $overlay = $dom->createElement('div');
        $innerDiv = $dom->createElement('div');
        $articleTitle = $this->getArticleTitle();
        $articleContent = $this->grabArticle();

        if (! $articleContent) {
            $this->success = false;
            $articleContent = $dom->createElement('div');
            $articleContent->setAttribute('id', 'readability-content');
            $this->setInnerHtml($articleContent, '<p>Sorry, Readability was unable to parse this page for content.</p>');
        }

        $overlay->setAttribute('id', 'readOverlay');
        $innerDiv->setAttribute('id', 'readInner');

        $innerDiv->appendChild($articleTitle);
        $innerDiv->appendChild($articleContent);
        $overlay->appendChild($innerDiv);

        if ($this->body === null || ! isset($this->body->childNodes)) {
            $this->body = $dom->createElement('div');
        }

        $body = $this->body;
        $this->setInnerHtml($body, '');
        $body->appendChild($overlay);
        $body->removeAttribute('style');

        $this->postProcessContent($articleContent);

        $this->articleTitle = $articleTitle;
        $this->articleContent = $articleContent;

        return $this->success;
    }

    /**
     * Run any post-process modifications to article content as necessary.
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
     */
    public function addFootnotes(DOMElement $articleContent): void
    {
        $dom = $this->dom;
        if ($dom === null) {
            return;
        }

        $footnotesWrapper = $dom->createElement('div');
        $footnotesWrapper->setAttribute('id', 'readability-footnotes');
        $this->setInnerHtml($footnotesWrapper, '<h3>References</h3>');

        $articleFootnotes = $dom->createElement('ol');
        $articleFootnotes->setAttribute('id', 'readability-footnotes-list');
        $footnotesWrapper->appendChild($articleFootnotes);

        $articleLinks = $articleContent->getElementsByTagName('a');

        $linkCount = 0;
        for ($i = 0; $i < $articleLinks->length; $i++) {
            $articleLink = $articleLinks->item($i);
            if (! $articleLink instanceof DOMElement) {
                continue;
            }
            $footnoteLink = $articleLink->cloneNode(true);
            if (! $footnoteLink instanceof DOMElement) {
                continue;
            }
            $refLink = $dom->createElement('a');
            $footnote = $dom->createElement('li');
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
            $this->setInnerHtml($refLink, '<small><sup>['.$linkCount.']</sup></small>');
            $refLink->setAttribute('class', 'readability-DoNotFootnote');
            $refLink->setAttribute('style', 'color: inherit;');

            if ($articleLink->parentNode !== null && $articleLink->parentNode->lastChild === $articleLink) {
                $articleLink->parentNode->appendChild($refLink);
            } elseif ($articleLink->parentNode !== null && $articleLink->nextSibling !== null) {
                $articleLink->parentNode->insertBefore($refLink, $articleLink->nextSibling);
            }

            $articleLink->setAttribute('style', 'color: inherit; text-decoration: none;');
            $articleLink->setAttribute('name', 'readabilityLink-'.$linkCount);

            $this->setInnerHtml($footnote, '<small><sup><a href="#readabilityLink-'.$linkCount.'" title="Jump to Link in Article">^</a></sup></small> ');

            $footnoteTitle = $footnoteLink->getAttribute('title');
            $this->setInnerHtml($footnoteLink, ($footnoteTitle !== '' ? $footnoteTitle : $linkText));
            $footnoteLink->setAttribute('name', 'readabilityFootnoteLink-'.$linkCount);

            $footnote->appendChild($footnoteLink);
            if ($linkDomain) {
                $this->setInnerHtml($footnote, $this->getInnerHtml($footnote).'<small> ('.$linkDomain.')</small>');
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
     */
    public function revertReadabilityStyledElements(DOMElement $articleContent): void
    {
        $ownerDoc = $articleContent->ownerDocument;
        if ($ownerDoc === null) {
            return;
        }
        $xpath = new DOMXPath($ownerDoc);
        $elems = $xpath->query('.//p[@class="readability-styled"]', $articleContent);
        if ($elems === false) {
            return;
        }
        for ($i = $elems->length - 1; $i >= 0; $i--) {
            $e = $elems->item($i);
            if ($e === null || $e->parentNode === null) {
                continue;
            }
            if ($e instanceof DOMElement) {
                $e->parentNode->replaceChild($ownerDoc->createTextNode($e->textContent), $e);
            }
        }
    }

    /**
     * Prepare the article node for display. Clean out any inline styles,
     * iframes, forms, strip extraneous <p> tags, etc.
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
            $pItem = $articleParagraphs->item($i);
            if (! $pItem instanceof DOMElement) {
                continue;
            }
            $imgCount = $pItem->getElementsByTagName('img')->length;
            $embedCount = $pItem->getElementsByTagName('embed')->length;
            $objectCount = $pItem->getElementsByTagName('object')->length;
            $iframeCount = $pItem->getElementsByTagName('iframe')->length;

            if ($imgCount === 0 && $embedCount === 0 && $objectCount === 0 && $iframeCount === 0 && $this->getInnerText($pItem,
                false) === '') {
                if ($pItem->parentNode !== null) {
                    $pItem->parentNode->removeChild($pItem);
                }
            }
        }

        try {
            $this->setInnerHtml($articleContent, (string) preg_replace('/<br[^>]*>\s*<p/i', '<p', $this->getInnerHtml($articleContent)));
        } catch (Exception $e) {
            $this->dbg('Cleaning innerHTML of breaks failed. This is an IE strict-block-elements bug. Ignoring.: '.$e);
        }
    }

    /**
     * Remove script tags from document
     */
    public function removeScripts(DOMDocument|DOMElement $doc): void
    {
        $scripts = $doc->getElementsByTagName('script');
        for ($i = $scripts->length - 1; $i >= 0; $i--) {
            try {
                $script = $scripts->item($i);
                if ($script !== null && $script->parentNode !== null) {
                    $script->parentNode->removeChild($script);
                }
            } catch (Exception $e) {
            }
        }
    }

    /**
     * Get the inner text of a node.
     * This also strips out any excess whitespace to be found.
     *
     * @param  bool  $normalizeSpaces  (default: true)
     */
    public function getInnerText(?DOMElement $e, bool $normalizeSpaces = true): string
    {
        $textContent = '';

        if ($e === null || ! isset($e->textContent) || $e->textContent === '') {
            return '';
        }

        $textContent = mb_trim($e->textContent);

        if ($normalizeSpaces) {
            return (string) preg_replace($this->regexps['normalize'], ' ', $textContent);
        }

        return $textContent;
    }

    /**
     * Get the number of times a string $s appears in the node $e.
     *
     * @param  string  $s  - what to count. Default is ","
     */
    public function getCharCount(DOMElement $e, string $s = ','): int
    {
        return mb_substr_count($this->getInnerText($e), $s);
    }

    /**
     * Remove the style attribute on every $e and under.
     */
    public function cleanStyles(?DOMElement $e): void
    {
        if ($e === null) {
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
     */
    public function getLinkDensity(DOMElement $e): float
    {
        $links = $e->getElementsByTagName('a');
        $textLength = mb_strlen($this->getInnerText($e));
        $linkLength = 0;
        for ($i = 0, $il = $links->length; $i < $il; $i++) {
            $link = $links->item($i);
            if ($link instanceof DOMElement) {
                $linkLength += mb_strlen($this->getInnerText($link));
            }
        }
        if ($textLength > 0) {
            return $linkLength / $textLength;
        }

        return 0;
    }

    /**
     * Get an elements class/id weight. Uses regular expressions to tell if this
     * element looks good or bad.
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
     */
    public function killBreaks(DOMElement $node): void
    {
        $html = $this->getInnerHtml($node);
        $html = (string) preg_replace($this->regexps['killBreaks'], '<br />', $html);
        $this->setInnerHtml($node, $html);
    }

    /**
     * Clean a node of all elements of type "tag".
     * (Unless it's a youtube/vimeo video. People love movies.)
     */
    public function clean(DOMElement $e, string $tag): void
    {
        $targetList = $e->getElementsByTagName($tag);
        $isEmbed = ($tag === 'iframe' || $tag === 'object' || $tag === 'embed');

        for ($y = $targetList->length - 1; $y >= 0; $y--) {
            $targetItem = $targetList->item($y);
            if (! $targetItem instanceof DOMElement) {
                continue;
            }
            if ($isEmbed) {
                $attributeValues = '';
                $attributes = $targetItem->attributes;
                for ($i = 0, $il = $attributes->length; $i < $il; $i++) {
                    $attr = $attributes->item($i);
                    if ($attr !== null) {
                        $attributeValues .= $attr->value.'|';
                    }
                }

                if (preg_match($this->regexps['video'], $attributeValues)) {
                    continue;
                }

                if (preg_match($this->regexps['video'], $this->getInnerHtml($targetItem))) {
                    continue;
                }
            }
            if ($targetItem->parentNode !== null) {
                $targetItem->parentNode->removeChild($targetItem);
            }
        }
    }

    /**
     * Clean an element of all tags of type "tag" if they look fishy.
     * "Fishy" is an algorithm based on content length, classnames,
     * link density, number of images & embeds, etc.
     */
    public function cleanConditionally(DOMElement $e, string $tag): void
    {
        if (! $this->flagIsActive(self::FLAG_CLEAN_CONDITIONALLY)) {
            return;
        }

        $tagsList = $e->getElementsByTagName($tag);
        $curTagsLength = $tagsList->length;

        for ($i = $curTagsLength - 1; $i >= 0; $i--) {
            $tagItem = $tagsList->item($i);
            if (! $tagItem instanceof DOMElement) {
                continue;
            }
            $weight = $this->getClassWeight($tagItem);
            $contentScore = ($tagItem->hasAttribute('readability')) ? (int) $tagItem->getAttribute('readability') : 0;

            $this->dbg('Cleaning Conditionally '.$tagItem->tagName.' ('.$tagItem->getAttribute('class').':'.$tagItem->getAttribute('id').')'.(($tagItem->hasAttribute('readability')) ? (' with score '.$tagItem->getAttribute('readability')) : ''));

            if ($weight + $contentScore < 0) {
                if ($tagItem->parentNode !== null) {
                    $tagItem->parentNode->removeChild($tagItem);
                }
            } elseif ($this->getCharCount($tagItem, ',') < 10) {
                $p = $tagItem->getElementsByTagName('p')->length;
                $img = $tagItem->getElementsByTagName('img')->length;
                $li = $tagItem->getElementsByTagName('li')->length - 100;
                $input = $tagItem->getElementsByTagName('input')->length;
                $a = $tagItem->getElementsByTagName('a')->length;

                $embedCount = 0;
                $embeds = $tagItem->getElementsByTagName('embed');
                for ($ei = 0, $il = $embeds->length; $ei < $il; $ei++) {
                    $embedItem = $embeds->item($ei);
                    if ($embedItem instanceof DOMElement && preg_match($this->regexps['video'], $embedItem->getAttribute('src'))) {
                        $embedCount++;
                    }
                }
                $embeds = $tagItem->getElementsByTagName('iframe');
                for ($ei = 0, $il = $embeds->length; $ei < $il; $ei++) {
                    $embedItem = $embeds->item($ei);
                    if ($embedItem instanceof DOMElement && preg_match($this->regexps['video'], $embedItem->getAttribute('src'))) {
                        $embedCount++;
                    }
                }

                $linkDensity = $this->getLinkDensity($tagItem);
                $contentLength = mb_strlen($this->getInnerText($tagItem));
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

                if ($toRemove && $tagItem->parentNode !== null) {
                    $tagItem->parentNode->removeChild($tagItem);
                }
            }
        }
    }

    /**
     * Clean out spurious headers from an Element. Checks things like classnames and link density.
     */
    public function cleanHeaders(DOMElement $e): void
    {
        for ($headerIndex = 1; $headerIndex < 3; $headerIndex++) {
            $headers = $e->getElementsByTagName('h'.$headerIndex);
            for ($i = $headers->length - 1; $i >= 0; $i--) {
                $headerItem = $headers->item($i);
                if ($headerItem instanceof DOMElement) {
                    if ($this->getClassWeight($headerItem) < 0 || $this->getLinkDensity($headerItem) > 0.33) {
                        if ($headerItem->parentNode !== null) {
                            $headerItem->parentNode->removeChild($headerItem);
                        }
                    }
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
     */
    protected function getArticleTitle(): DOMElement
    {
        $curTitle = '';
        $origTitle = '';

        try {
            if ($this->dom !== null) {
                $titleNode = $this->dom->getElementsByTagName('title')->item(0);
                if ($titleNode instanceof DOMElement) {
                    $curTitle = $origTitle = $this->getInnerText($titleNode);
                }
            }
        } catch (Exception $e) {
        }

        if (preg_match('/ [\|\-] /', $curTitle)) {
            $curTitle = (string) preg_replace('/(.*)[\|\-] .*/i', '$1', $origTitle);

            if (count(explode(' ', $curTitle)) < 3) {
                $curTitle = (string) preg_replace('/[^\|\-]*[\|\-](.*)/i', '$1', $origTitle);
            }
        } elseif (mb_strpos($curTitle, ': ') !== false) {
            $curTitle = (string) preg_replace('/.*:(.*)/i', '$1', $origTitle);

            if (count(explode(' ', $curTitle)) < 3) {
                $curTitle = (string) preg_replace('/[^:]*[:](.*)/i', '$1', $origTitle);
            }
        } elseif (mb_strlen($curTitle) > 150 || mb_strlen($curTitle) < 15) {
            if ($this->dom !== null) {
                $hOnes = $this->dom->getElementsByTagName('h1');
                if ($hOnes->length === 1) {
                    $hOne = $hOnes->item(0);
                    if ($hOne instanceof DOMElement) {
                        $curTitle = $this->getInnerText($hOne);
                    }
                }
            }
        }

        $curTitle = mb_trim($curTitle);

        if (count(explode(' ', $curTitle)) <= 4) {
            $curTitle = $origTitle;
        }

        if ($this->dom === null) {
            return new DOMElement('h1');
        }
        $articleTitle = $this->dom->createElement('h1');
        $this->setInnerHtml($articleTitle, $curTitle);

        return $articleTitle;
    }

    /**
     * Prepare the HTML document for readability to scrape it.
     * This includes things like stripping javascript, CSS, and handling terrible markup.
     */
    protected function prepDocument(): void
    {
        if ($this->dom === null) {
            return;
        }
        if ($this->body === null) {
            $this->body = $this->dom->createElement('body');
            if ($this->dom->documentElement !== null) {
                $this->dom->documentElement->appendChild($this->body);
            }
        }
        $this->body->setAttribute('id', 'readabilityBody');

        $styleTags = $this->dom->getElementsByTagName('style');
        for ($i = $styleTags->length - 1; $i >= 0; $i--) {
            try {
                $styleTag = $styleTags->item($i);
                if ($styleTag !== null && $styleTag->parentNode !== null) {
                    @$styleTag->parentNode->removeChild($styleTag);
                }
            } catch (Exception $e) {
            }
        }
    }

    /**
     * Initialize a node with the readability object. Also checks the
     * className/id for special names to add to its score.
     */
    protected function initializeNode(DOMElement $node): void
    {
        if ($this->dom === null) {
            return;
        }
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
     */
    protected function grabArticle(DOMDocument|DOMElement|null $page = null): DOMElement|false
    {
        $stripUnlikelyCandidates = $this->flagIsActive(self::FLAG_STRIP_UNLIKELYS);
        if (! $page) {
            $page = $this->dom;
        }
        if ($page === null) {
            return false;
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
                    if ($node->parentNode !== null) {
                        $node->parentNode->removeChild($node);
                    }
                    $nodeIndex--;

                    continue;
                }
            }

            if ($tagName === 'P' || $tagName === 'TD' || $tagName === 'PRE') {
                $nodesToScore[] = $node;
            }

            if ($tagName === 'DIV') {
                if (! preg_match($this->regexps['divToPElements'], $this->getInnerHtml($node))) {
                    $newNode = $this->dom !== null ? $this->dom->createElement('p') : new DOMElement('p');
                    try {
                        $this->setInnerHtml($newNode, $this->getInnerHtml($node));
                        if ($node->parentNode !== null) {
                            $node->parentNode->replaceChild($newNode, $node);
                        }
                        $nodeIndex--;
                        $nodesToScore[] = $newNode;
                    } catch (Exception $e) {
                        $this->dbg('Could not alter div to p, reverting back to div.: '.$e);
                    }
                } else {
                    for ($i = 0, $il = $node->childNodes->length; $i < $il; $i++) {
                        $childNode = $node->childNodes->item($i);
                        if ($childNode !== null && $childNode->nodeType === XML_TEXT_NODE) {
                            if ($this->dom !== null && $childNode->parentNode !== null) {
                                $p = $this->dom->createElement('p');
                                $this->setInnerHtml($p, (string) $childNode->nodeValue);
                                $p->setAttribute('style', 'display: inline;');
                                $p->setAttribute('class', 'readability-styled');
                                $childNode->parentNode->replaceChild($p, $childNode);
                            }
                        }
                    }
                }
            }
        }

        $candidates = [];
        for ($pt = 0; $pt < count($nodesToScore); $pt++) {
            $parentNode = $nodesToScore[$pt]->parentNode;
            $grandParentNode = ! $parentNode instanceof DOMElement ? null : (($parentNode->parentNode instanceof DOMElement) ? $parentNode->parentNode : null);
            $innerText = $this->getInnerText($nodesToScore[$pt]);

            if (! $parentNode instanceof DOMElement || ! isset($parentNode->tagName)) {
                continue;
            }

            if (mb_strlen($innerText) < 25) {
                continue;
            }

            if (! $parentNode->hasAttribute('readability')) {
                $this->initializeNode($parentNode);
                $candidates[] = $parentNode;
            }

            if ($grandParentNode instanceof DOMElement && ! $grandParentNode->hasAttribute('readability') && isset($grandParentNode->tagName)) {
                $this->initializeNode($grandParentNode);
                $candidates[] = $grandParentNode;
            }

            $contentScore = 0;

            $contentScore++;

            $contentScore += count(explode(',', $innerText));

            $contentScore += min(floor(mb_strlen($innerText) / 100), 3);

            $parentReadability = $parentNode->getAttributeNode('readability');
            if ($parentReadability instanceof DOMAttr) {
                $this->addReadabilityScore($parentReadability, $contentScore);
            }

            if ($grandParentNode instanceof DOMElement) {
                $grandParentReadability = $grandParentNode->getAttributeNode('readability');
                if ($grandParentReadability instanceof DOMAttr) {
                    $this->addReadabilityScore($grandParentReadability, $contentScore / 2);
                }
            }
        }

        $topCandidate = null;
        for ($c = 0, $cl = count($candidates); $c < $cl; $c++) {
            $readability = $candidates[$c]->getAttributeNode('readability');
            if ($readability instanceof DOMAttr) {
                $this->multiplyReadabilityScore($readability, 1 - $this->getLinkDensity($candidates[$c]));

                $this->dbg('Candidate: '.$candidates[$c]->tagName.' ('.$candidates[$c]->getAttribute('class').':'.$candidates[$c]->getAttribute('id').') with score '.$readability->value);

                if (! $topCandidate || $readability->value > (int) $topCandidate->getAttribute('readability')) {
                    $topCandidate = $candidates[$c];
                }
            }
        }

        if ($topCandidate === null || mb_strtoupper($topCandidate->tagName) === 'BODY') {
            $topCandidate = $this->dom !== null ? $this->dom->createElement('div') : new DOMElement('div');
            if ($page instanceof DOMDocument) {
                if (isset($page->documentElement)) {
                    $this->setInnerHtml($topCandidate, $this->getInnerHtml($page->documentElement));
                    $this->setInnerHtml($page->documentElement, '');
                    $page->documentElement->appendChild($topCandidate);
                }
            } else {
                $this->setInnerHtml($topCandidate, $this->getInnerHtml($page));
                $this->setInnerHtml($page, '');
                $page->appendChild($topCandidate);
            }
            $this->initializeNode($topCandidate);
        }

        if ($this->dom === null) {
            return false;
        }

        $articleContent = $this->dom->createElement('div');
        $articleContent->setAttribute('id', 'readability-content');
        $siblingScoreThreshold = max(10, ((int) $topCandidate->getAttribute('readability')) * 0.2);
        $siblingNodes = null;
        if ($topCandidate->parentNode !== null) {
            $siblingNodes = $topCandidate->parentNode->childNodes;
        }
        if (! $siblingNodes instanceof DOMNodeList) {
            $siblingNodes = new stdClass;
            $siblingNodes->length = 0;
        }

        for ($s = 0, $sl = $siblingNodes->length; $s < $sl; $s++) {
            if (! $siblingNodes instanceof DOMNodeList) {
                continue;
            }
            $siblingNode = $siblingNodes->item($s);
            if (! $siblingNode instanceof DOMNode) {
                continue;
            }
            $append = false;

            $this->dbg('Looking at sibling node: '.$siblingNode->nodeName.(($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode instanceof DOMElement && $siblingNode->hasAttribute('readability')) ? (' with score '.$siblingNode->getAttribute('readability')) : ''));

            if ($siblingNode === $topCandidate) {
                $append = true;
            }

            $contentBonus = 0;
            if ($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode instanceof DOMElement && $siblingNode->getAttribute('class') === $topCandidate->getAttribute('class') && $topCandidate->getAttribute('class') !== '') {
                $contentBonus += ((int) $topCandidate->getAttribute('readability')) * 0.2;
            }

            if ($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode instanceof DOMElement && $siblingNode->hasAttribute('readability') && (((int) $siblingNode->getAttribute('readability')) + $contentBonus) >= $siblingScoreThreshold) {
                $append = true;
            }

            if ($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode instanceof DOMElement && mb_strtoupper($siblingNode->nodeName) === 'P') {
                $linkDensity = $this->getLinkDensity($siblingNode);
                $nodeContent = $this->getInnerText($siblingNode);
                $nodeLength = mb_strlen($nodeContent);

                if ($nodeLength > 80 && $linkDensity < 0.25) {
                    $append = true;
                } elseif ($nodeLength < 80 && $linkDensity == 0 && preg_match('/\.( |$)/', $nodeContent)) {
                    $append = true;
                }
            }

            if ($append) {
                $this->dbg('Appending node: '.$siblingNode->nodeName);

                $nodeToAppend = null;
                $sibNodeName = $siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode instanceof DOMElement ? mb_strtoupper($siblingNode->nodeName) : '';
                if ($sibNodeName !== 'DIV' && $sibNodeName !== 'P') {
                    $this->dbg('Altering siblingNode of '.$sibNodeName.' to div.');
                    if ($this->dom === null) {
                        continue;
                    }
                    $nodeToAppend = $this->dom->createElement('div');
                    try {
                        if ($siblingNode instanceof DOMElement) {
                            $nodeToAppend->setAttribute('id', $siblingNode->getAttribute('id'));
                            $this->setInnerHtml($nodeToAppend, $this->getInnerHtml($siblingNode));
                        }
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
            if ($this->body === null || ! isset($this->body->childNodes)) {
                if ($this->dom === null) {
                    return false;
                }
                $this->body = $this->dom->createElement('body');
            }
            if ($this->bodyCache !== null) {
                $this->setInnerHtml($this->body, $this->bodyCache);
            }

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

    /**
     * Get innerHTML of a DOMElement, working with JSLikeHTMLElement.
     */
    protected function getInnerHtml(DOMElement $node): string
    {
        if ($node instanceof JSLikeHTMLElement) {
            return (string) $node->__get('innerHTML');
        }

        if ($node->ownerDocument !== null) {
            $html = '';
            foreach ($node->childNodes as $child) {
                $html .= $node->ownerDocument->saveXML($child);
            }

            return $html;
        }

        return '';
    }

    /**
     * Set innerHTML of a DOMElement, working with JSLikeHTMLElement.
     */
    protected function setInnerHtml(DOMElement $node, string $html): void
    {
        if ($node instanceof JSLikeHTMLElement) {
            $node->__set('innerHTML', $html);
        }
    }
}
