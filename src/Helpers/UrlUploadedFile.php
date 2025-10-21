<?php

namespace Kalimeromk\Rssfeed\Helpers;

use Illuminate\Http\UploadedFile;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;

class UrlUploadedFile extends UploadedFile
{
    /**
     * Creates an instance of UrlUploadedFile from a given URL.
     *
     * This method attempts to open a stream from the provided URL. If it cannot open the stream, it throws a CantOpenFileFromUrlException.
     * If it can open the stream, it creates a temporary file in the system's temporary directory, writes the stream to the temporary file,
     * and creates a new instance of UrlUploadedFile with the temporary file as its file path.
     * The original name, MIME type, error status, and test mode of the new UrlUploadedFile instance can be optionally specified.
     * If they are not specified, they default to an empty string, null, null, and false, respectively.
     *
     * @param  string  $url  The URL from which to create the UrlUploadedFile instance.
     * @param  string  $originalName  The original name of the file. Default is an empty string.
     * @param  string|null  $mimeType  The MIME type of the file. Default is null.
     * @param  int|null  $error  The error status of the file. Default is null.
     * @param  bool  $test  Whether the file is in test mode. Default is false.
     * @return self The created UrlUploadedFile instance.
     *
     * @throws CantOpenFileFromUrlException If a stream cannot be opened from the provided URL.
     */
    public static function createFromUrl(
        string $url,
        string $originalName = '',
        ?string $mimeType = null,
        ?int $error = null,
        bool $test = false
    ): self {
        if (! $stream = @fopen($url, 'r')) {
            throw new CantOpenFileFromUrlException($url);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'url-file-');

        $dest = fopen($tempFile, 'w');
        if ($dest === false) {
            fclose($stream);
            throw new CantOpenFileFromUrlException($url);
        }

        stream_copy_to_stream($stream, $dest);
        fclose($dest);
        fclose($stream);

        return new self($tempFile, $originalName, $mimeType, $error, $test);
    }
}
