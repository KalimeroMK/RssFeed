<?php

namespace Kalimeromk\Rssfeed\Exceptions;

use Exception;

class CantOpenFileFromUrlException extends Exception
{
    /**
     * Constructs a new CantOpenFileFromUrlException.
     *
     * This constructor initializes a new CantOpenFileFromUrlException with a message that includes the provided URL.
     * The message indicates that a file could not be opened from the provided URL.
     *
     * @param  string  $url  The URL from which a file could not be opened.
     */
    public function __construct(string $url)
    {
        parent::__construct('Can\'t open file from url '.$url.'.');
    }
}
