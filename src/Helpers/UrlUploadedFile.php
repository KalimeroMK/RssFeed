<?php

namespace Kalimeromk\Rssfeed\Helpers;

use Illuminate\Http\UploadedFile;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;

class UrlUploadedFile extends UploadedFile
{
    /**
     * @throws CantOpenFileFromUrlException
     */
    public static function createFromUrl(string $url, string $originalName = '', string $mimeType = null, int $error = null, bool $test = false): self
    {
        if (! $stream = @fopen($url, 'r')) {
            throw new CantOpenFileFromUrlException($url);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'url-file-');

        file_put_contents($tempFile, $stream);

        return new static($tempFile, $originalName, $mimeType, $error, $test);
    }
}