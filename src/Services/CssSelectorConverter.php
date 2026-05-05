<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

/**
 * Converts simple CSS selectors to XPath expressions safely.
 *
 * Supported selectors:
 * - .class
 * - #id
 * - tag
 * - [attr]
 * - [attr=value]
 * - [attr*="value"]
 * - tag.class
 * - tag#id
 */
class CssSelectorConverter
{
    /**
     * Convert a CSS selector to an XPath expression.
     *
     * @throws \InvalidArgumentException
     */
    public function toXPath(string $selector): string
    {
        $selector = trim($selector);

        if ($selector === '') {
            throw new \InvalidArgumentException('Empty CSS selector');
        }

        // Handle attribute contains selector [class*="value"]
        if (preg_match('/^\[([a-zA-Z0-9_-]+)\*="([^"]*)"\]$/', $selector, $matches)) {
            return '//*[contains(@'.$this->escapeName($matches[1]).", '".$this->escapeValue($matches[2])."')]";
        }

        // Handle attribute selector [attr=value]
        if (preg_match('/^\[([a-zA-Z0-9_-]+)="([^"]*)"\]$/', $selector, $matches)) {
            return '//*[@'.$this->escapeName($matches[1])."='".$this->escapeValue($matches[2])."']";
        }

        // Handle attribute selector [attr] (no value)
        if (preg_match('/^\[([a-zA-Z0-9_-]+)\]$/', $selector, $matches)) {
            return '//*[@'.$this->escapeName($matches[1]).']';
        }

        // Handle ID selector #id
        if (str_starts_with($selector, '#')) {
            $id = substr($selector, 1);
            if ($id === '') {
                throw new \InvalidArgumentException('Empty ID in CSS selector');
            }

            return "//*[@id='".$this->escapeValue($id)."']";
        }

        // Handle class selector .class
        if (str_starts_with($selector, '.')) {
            $class = substr($selector, 1);
            if ($class === '') {
                throw new \InvalidArgumentException('Empty class in CSS selector');
            }

            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' ".$this->escapeValue($class)." ')]";
        }

        // Handle tag.class
        if (str_contains($selector, '.')) {
            [$tag, $class] = explode('.', $selector, 2);
            if ($tag === '' || $class === '') {
                throw new \InvalidArgumentException('Invalid tag.class selector');
            }

            return '//'.$this->escapeName($tag)."[contains(concat(' ', normalize-space(@class), ' '), ' ".$this->escapeValue($class)." ')]";
        }

        // Handle tag#id
        if (str_contains($selector, '#')) {
            [$tag, $id] = explode('#', $selector, 2);
            if ($tag === '' || $id === '') {
                throw new \InvalidArgumentException('Invalid tag#id selector');
            }

            return '//'.$this->escapeName($tag)."[@id='".$this->escapeValue($id)."']";
        }

        // Default: tag name
        return '//'.$this->escapeName($selector);
    }

    /**
     * Escape an XML name to prevent injection.
     */
    private function escapeName(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($clean === '' || $clean !== $name) {
            throw new \InvalidArgumentException("Invalid name in CSS selector: {$name}");
        }

        return $clean;
    }

    /**
     * Escape an attribute value for XPath safely.
     */
    private function escapeValue(string $value): string
    {
        // Escape single quotes by wrapping each segment
        if (str_contains($value, "'")) {
            $parts = explode("'", $value);

            return "concat('".implode("', \"'\", '", $parts)."')";
        }

        return $value;
    }
}
