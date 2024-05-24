<?php

namespace Kalimeromk\Rssfeed;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use Kalimeromk\Rssfeed\Helpers\UrlUploadedFile;
use SimplePie\SimplePie;

class RssFeed implements ShouldQueue
{
    use Dispatchable;

    private $app;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }
    public function parseRssFeeds(string $url, $jobId = null)
    {
        $simplePie = $this->app->make(SimplePie::class);

        $simplePie->enable_cache(false);
        $simplePie->enable_order_by_date(false);

        $simplePie->set_curl_options([
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        if (isset($options['curl_options'])) {
            $simplePie->set_curl_options($options['curl_options']);
        }

        $simplePie->set_feed_url($url);
        $simplePie->init();
        return $simplePie;
    }

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
    public function extractImageFromDescription($description)
    {
        if (preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $description, $image)) {
            return $image['src'];
        }
        return null;
    }
}
