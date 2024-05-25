<?php

namespace Kalimeromk\Rssfeed;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
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
     */
    public function parseRssFeeds(string $url, $jobId = null)
    {
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
     * @throws CantOpenFileFromUrlException
     */
    public function saveImagesToStorage(array $images): array
    {
        $savedImageNames = [];
        $imageStoragePath = config('rssfeed.image_storage_path', 'images');

        foreach ($images as $image) {
            $file = UrlUploadedFile::createFromUrl($image);
            $imageName = Str::random(15) . '.' . $file->extension();
            $file->storeAs($imageStoragePath, $imageName, 'public');
            $savedImageNames[] = $imageName;
        }

        return $savedImageNames;
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
}
