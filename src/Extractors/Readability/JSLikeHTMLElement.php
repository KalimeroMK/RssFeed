<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Extractors\Readability;

use DOMElement;

/**
 * JavaScript-like HTML DOM Element
 *
 * This class extends PHP's DOMElement to allow
 * users to get and set the innerHTML property of
 * HTML elements in the same way it's done in JavaScript.
 *
 * @author Keyvan Minoukadeh - http://www.keyvan.net
 * @see http://fivefilters.org
 */
class JSLikeHTMLElement extends DOMElement
{
    /**
     * Used for setting innerHTML like it's done in JavaScript.
     */
    public function __set(string $name, mixed $value): void
    {
        if ($name === 'innerHTML') {
            // First, empty the element
            for ($x = $this->childNodes->length - 1; $x >= 0; $x--) {
                $this->removeChild($this->childNodes->item($x));
            }

            // $value holds our new inner HTML
            if ($value !== '' && $value !== null) {
                $f = $this->ownerDocument->createDocumentFragment();
                // appendXML() expects well-formed markup (XHTML)
                $result = @$f->appendXML($value); // @ to suppress PHP warnings
                if ($result && $f->hasChildNodes()) {
                    $this->appendChild($f);
                } else {
                    // $value is probably ill-formed
                    $f = new \DOMDocument();
                    $value = mb_convert_encoding((string) $value, 'HTML-ENTITIES', 'UTF-8');
                    $result = @$f->loadHTML('<htmlfragment>'.$value.'</htmlfragment>');
                    if ($result) {
                        $import = $f->getElementsByTagName('htmlfragment')->item(0);
                        if ($import !== null) {
                            foreach ($import->childNodes as $child) {
                                $importedNode = $this->ownerDocument->importNode($child, true);
                                $this->appendChild($importedNode);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Used for getting innerHTML like it's done in JavaScript.
     */
    public function __get(string $name): ?string
    {
        if ($name === 'innerHTML') {
            $inner = '';
            foreach ($this->childNodes as $child) {
                $inner .= $this->ownerDocument->saveXML($child);
            }

            return $inner;
        }

        return null;
    }

    public function __toString(): string
    {
        return '['.$this->tagName.']';
    }
}
