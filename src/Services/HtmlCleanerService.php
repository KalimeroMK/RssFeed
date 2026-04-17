<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

use DOMDocument;
use DOMXPath;

class HtmlCleanerService
{
    /**
     * Extract clean text content from HTML.
     * Removes scripts, styles, and normalizes whitespace.
     */
    public function extractTextContent(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8"?>'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $scripts = $xpath->query('//script|//style|//noscript|//iframe|//embed|//object');
        if ($scripts) {
            foreach ($scripts as $script) {
                if ($script instanceof \DOMNode && $script->parentNode) {
                    $script->parentNode->removeChild($script);
                }
            }
        }

        $text = $dom->textContent;
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        $text = trim($text);
        $text = $this->removeDonationTextPatterns($text);

        return $text;
    }

    /**
     * Remove common donation and payment text patterns.
     */
    public function removeDonationTextPatterns(string $text): string
    {
        $patterns = [
            '/дарител/is',
            '/Donation Amount/is',
            '/Превод от чужд език/is',
            '/Авторски хонорар/is',
            '/Такса за поддръжка/is',
            '/Избрана от Вас сума/is',
            '/Дарете сега/is',
            '/Donation Total/is',
            '/Споделете/is',
            '/donate now/i',
            '/make a donation/i',
            '/support us/i',
            '/become a patron/i',
            '/donation amount/i',
            '/credit card info/i',
            '/secure ssl encrypted payment/i',
            '/select payment method/i',
            '/personal info/i',
            '/first name/i',
            '/last name/i',
            '/email address/i',
            '/make this an anonymous donation/i',
            '/donation total/i',
            '/subscribe to our newsletter/i',
            '/sign up for our newsletter/i',
            '/newsletter signup/i',
            '/email newsletter/i',
            '/\d+\.\d{2}€/',
            '/\$\d+\.\d{2}/',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? '';
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return trim($text);
    }
}
