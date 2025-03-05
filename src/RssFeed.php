<?php

namespace Kalimeromk\Rssfeed;

use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;
use Kalimeromk\Rssfeed\Helpers\UrlUploadedFile;
use SimplePie\SimplePie;

class RssFeed implements ShouldQueue
{
    use Dispatchable;

    private Container $app;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Parses the RSS feeds from a given URL.
     *
     * This method creates an instance of SimplePie, disables caching and ordering by date,
     * sets some default cURL options for SSL verification, and optionally sets additional cURL options.
     * It then sets the feed URL, initializes the SimplePie object, and returns it.
     *
     * @param  string  $url  The URL of the RSS feed to parse.
     * @param  mixed  $jobId  An optional job ID. Default is null.
     * @return SimplePie The initialized SimplePie object.
     *
     * @throws Exception If the SimplePie object cannot be created or initialized.
     * @throws CantOpenFileFromUrlException
     */
    public function RssFeeds(string $url, $jobId = null): SimplePie
    {
        if (!$this->urlExists($url)) {
            throw new CantOpenFileFromUrlException("Cannot open RSS feed URL: {$url}");
        }
        $simplePie = $this->app->make(SimplePie::class);

        $simplePie->enable_cache(false);
        $simplePie->enable_order_by_date(false);

        // Set default cURL options
        $simplePie->set_curl_options([
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        // If additional cURL options are provided, set them
        if (isset($options['curl_options'])) {
            $simplePie->set_curl_options($options['curl_options']);
        }

        $simplePie->set_feed_url($url);
        $simplePie->init();
        return $simplePie;
    }

    /**
     * Saves images to storage.
     *
     * This method takes an array of image URLs, downloads them, and saves them to storage.
     * It generates a random name for each image and stores the image in the path specified by the 'rssfeed.image_storage_path' configuration value.
     * If this configuration value is not set, it defaults to 'images'.
     * The images are stored with 'public' visibility.
     * The method returns an array of the generated image names.
     *
     * @param  array  $images  An array of image URLs to download and save.
     * @return array An array of the generated image names.
     */
    public function saveImagesToStorage(array $images, $model = null): array
    {
        $savedImageNames = [];
        $imageStoragePath = config('rssfeed.image_storage_path', 'images');
        $spatieEnabled = config('rssfeed.spatie_enabled', false);
        $spatieDisk = config('rssfeed.spatie_disk', 'public');
        $spatieMediaType = config('rssfeed.spatie_media_type', 'image');
        $baseUrl = config('app.url'); // Get base URL from .env

        foreach ($images as $image) {
            if (!is_string($image) || empty($image)) {
                continue;
            }

            try {
                $file = UrlUploadedFile::createFromUrl($image);
                $extension = $file->extension();

                if (empty($extension)) {
                    $extension = $this->inferExtension($image, $file->getMimeType());
                }

                $imageName = Str::random(15) . '.' . $extension;

                if ($spatieEnabled && $model && method_exists($model, 'addMediaFromUrl')) {
                    // Ensure the model implements HasMedia before using Spatie
                    $media = $model->addMediaFromUrl($image)
                        ->toMediaCollection($spatieMediaType, $spatieDisk);

                    $savedImageNames[] = $media->getUrl(); // Store Spatie media URL
                } else {
                    // Default Laravel Storage
                    $file->storeAs($imageStoragePath, $imageName, $spatieDisk);
                    $savedImageNames[] = "{$baseUrl}/storage/{$imageStoragePath}/{$imageName}"; // Use APP_URL
                }
            } catch (\Exception $e) {
                Log::error('Error processing image URL: ' . $image, ['exception' => $e]);
                continue;
            }
        }

        return $savedImageNames;
    }


    /**
     * Infers the file extension from the URL or MIME type.
     *
     * This method first attempts to infer the file extension from the URL.
     * If the URL does not contain an extension, it falls back to mapping MIME types to extensions.
     * If the MIME type is unknown, it defaults to 'bin'.
     *
     * @param  string  $url The URL of the file.
     * @param  string  $mimeType The MIME type of the file.
     * @return string The inferred file extension.
     */
    private function inferExtension(string $url, string $mimeType): string
    {
        // Attempt to infer the extension from the URL
        $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH));
        if (isset($pathInfo['extension'])) {
            return $pathInfo['extension'];
        }

        // Fallback to mapping MIME types to extensions
        $mimeTypeMapping = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            // Add more MIME type mappings as needed
        ];

        return $mimeTypeMapping[$mimeType] ?? 'bin'; // Default to 'bin' if MIME type is unknown
    }



    /**
     * Extracts the image source URL from the provided HTML description.
     *
     * This method uses a regular expression to match an <img> tag and its source URL in the provided HTML description.
     * If an <img> tag is found, the source URL is returned. If no <img> tag is found, the method returns null.
     *
     * @param  string  $content  The HTML description from which to extract the image source URL.
     * @return string|null The extracted image source URL, or null if no <img> tag is found.
     */
    public function extractImageFromDescription(string $content): ?string
    {
        if (preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $content, $image)) {
            return $image['src'];
        }
        return null;
    }

    /**
     * Checks if a given URL exists.
     *
     * This method sends a GET request to the provided URL with specific headers and options.
     * The 'verify' option is set to false to skip SSL verification, and the 'timeout' option is set to 60 seconds.
     * The headers include a specific 'User-Agent' and 'Accept' values.
     * If the GET request is successful, the method returns true.
     * If the GET request fails (throws an exception), the method returns false.
     *
     * @param string $url The URL to check.
     * @return bool Returns true if the URL exists (the GET request is successful), false otherwise.
     * @throws Exception If the GET request fails.
     */
    public function urlExists(string $url): bool
    {
        try {
            $response = Http::withOptions([
                'verify' => false, // Skip SSL verification
                'timeout' => 60, // Increase timeout duration
            ])->withHeaders([
                'User-Agent' => 'Full-Text RSS',
                'Accept' => 'application/rss+xml, application/xml, text/xml',
            ])->get($url);

            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param  string  $url
     * @return array
     */
    public function parseRssFeeds(string $url): array
    {
        $feed = new SimplePie();
        $feed->set_feed_url($url);
        $feed->enable_cache(false);
        $feed->init();
        $feed->handle_content_type();

        $parsedItems = [];

        foreach ($feed->get_items() as $item) {
            $title       = $item->get_title();
            $description = $item->get_description();
            $content     = $item->get_content();
            $link        = $item->get_link();
            $categories  = $item->get_categories();
            $date        = $item->get_date();
            $enclosure   = $item->get_enclosure();


            if ($content === $description || strlen(strip_tags($content)) < 200) {
                $content = $this->fetchFullContentFromPost($link);
            }

            $parsedItems[] = [
                'title'       => $title,
                'description' => $description,
                'content'     => $content,
                'url'         => $link,
                'categories'  => $categories,
                'date'        => $date,
                'enclosure'   => $enclosure
            ];
        }

        return $parsedItems;
    }

    /**
     * @param  string  $postUrl
     * @return string
     */
    /**
     * Fetches the full content from a post URL using a domain-specific XPath selector.
     */
    public function fetchFullContentFromPost(string $postUrl): string
    {
        try {
            $response = Http::get($postUrl);

            if ($response->failed()) {
                return '';
            }

            $html = $response->body();

            libxml_use_internal_errors(true);

            $dom = new DOMDocument();
            $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);

            // 1. Extract just the host/domain from the URL
            $domain = parse_url($postUrl, PHP_URL_HOST);

            // 2. Fetch the selector from config; if not found, use the default
            $selector = config("rssfeed.content_selectors.{$domain}")
                ?? config('rssfeed.default_selector');

            // 3. Use the resolved selector in the XPath query
            $nodes = $xpath->query($selector);

            if ($nodes->length === 0) {
                return '';
            }

            $fullContent = '';
            foreach ($nodes as $node) {
                $fullContent .= $dom->saveHTML($node);
            }

            return $fullContent;
        } catch (\Exception $e) {
            return $e->getMessage();
        }

    }
}
