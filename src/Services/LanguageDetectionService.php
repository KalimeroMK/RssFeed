<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

/**
 * Language Detection Service
 * Detects the language of article content
 * 
 * Note: For full functionality, install patrickschur/language-detection package
 * composer require patrickschur/language-detection
 */
class LanguageDetectionService
{
    /**
     * Detect language from text
     * Returns ISO 639-1 language code (e.g., 'en', 'mk', 'bg')
     */
    public function detect(string $text): ?string
    {
        $text = trim($text);
        if (empty($text)) {
            return null;
        }

        // Try to use external library if available
        if (class_exists(\LanguageDetection\Language::class)) {
            $detector = new \LanguageDetection\Language();
            $result = $detector->detect($text);
            $languages = $result->close();
            
            return $languages[0] ?? null;
        }

        // Fallback: detect from HTML lang attribute or common words
        return $this->detectFromCommonWords($text);
    }

    /**
     * Detect language from HTML
     */
    public function detectFromHtml(string $html): ?string
    {
        // Try to get from HTML lang attribute
        if (preg_match('/<html[^>]+lang=[\'"]?([a-zA-Z]{2})[^\'">]*[\'"]?/i', $html, $matches)) {
            return strtolower($matches[1]);
        }

        if (preg_match('/<meta[^>]+lang=[\'"]?([a-zA-Z]{2})[^\'">]*[\'"]?/i', $html, $matches)) {
            return strtolower($matches[1]);
        }

        // Extract text from HTML and detect
        $text = strip_tags($html);
        return $this->detect($text);
    }

    /**
     * Simple language detection based on common words
     * This is a basic fallback method
     */
    private function detectFromCommonWords(string $text): ?string
    {
        $text = strtolower($text);
        
        // Define common words for different languages
        $patterns = [
            'mk' => '/\b(и|на|во|се|од|да|е|што|со|за|ги|го|не|ја|та|те|ите|сите|македонски)\b/u',
            'bg' => '/\b(и|на|в|се|от|да|е|какво|с|за|ги|го|не|та|те|ите|всички|български)\b/u',
            'sr' => '/\b(и|на|у|се|од|да|је|шта|са|за|их|га|не|та|те|сви|српски)\b/u',
            'hr' => '/\b(i|na|u|se|od|da|je|što|sa|za|ih|ga|ne|ta|te|svi|hrvatski)\b/u',
            'sl' => '/\b(in|na|v|se|od|da|je|kaj|z|za|jih|ga|ne|ta|te|vsi|slovenski)\b/u',
            'en' => '/\b(the|and|of|to|in|a|is|that|for|it|with|as|this|on|by|from)\b/i',
            'de' => '/\b(der|die|und|in|den|von|mit|ist|das|für|auf|als|bei|nach|sich)\b/u',
            'fr' => '/\b(le|de|et|à|un|il|être|et|en|avoir|que|pour|dans|ce|qui)\b/u',
            'es' => '/\b(el|de|y|a|que|en|un|ser|se|no|haber|por|con|su|para|como)\b/u',
            'it' => '/\b(il|di|e|che|è|la|un|essere|per|non|con|su|come|da|si)\b/u',
            'ru' => '/\b(и|в|не|на|я|быть|он|с|что|а|по|это|она|к|но|мы|как|из)\b/u',
        ];

        $scores = [];
        foreach ($patterns as $lang => $pattern) {
            $count = preg_match_all($pattern, $text);
            if ($count > 0) {
                $scores[$lang] = $count;
            }
        }

        if (empty($scores)) {
            return null;
        }

        arsort($scores);
        return array_key_first($scores);
    }

    /**
     * Get language name from code
     */
    public function getLanguageName(string $code): ?string
    {
        $languages = [
            'mk' => 'Macedonian',
            'bg' => 'Bulgarian',
            'sr' => 'Serbian',
            'hr' => 'Croatian',
            'sl' => 'Slovenian',
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'ru' => 'Russian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'ro' => 'Romanian',
            'el' => 'Greek',
            'tr' => 'Turkish',
            'sq' => 'Albanian',
            'bs' => 'Bosnian',
            'cs' => 'Czech',
            'sk' => 'Slovak',
            'hu' => 'Hungarian',
            'uk' => 'Ukrainian',
        ];

        return $languages[$code] ?? null;
    }
}
