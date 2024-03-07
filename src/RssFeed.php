<?php

namespace Kalimeromk\Rssfeed;

use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;
use Kalimeromk\Rssfeed\Helpers\UrlUploadedFile;
use SimpleXMLElement;
use Illuminate\Support\Facades\Log;
class RssFeed implements ShouldQueue
{
    use Dispatchable;

    /**
     * @param  array  $feedUrls
     * @param  null  $jobId
     * @return array
     * @throws Exception
     */
    public function parseRssFeeds(array $feedUrls, $jobId = null): array
    {
        $parsedItems = [];

        foreach ($feedUrls as $feedUrl) {
            try {
                $xml = @file_get_contents($feedUrl);
                if ($xml === false) {
                    // Log error or handle it as required
                    throw new CantOpenFileFromUrlException("Cannot open RSS feed URL: {$feedUrl}");
                }
                $xmlObject = new SimpleXMLElement($xml);

                $channelTitle = (string) $xmlObject->channel->title;
                $channelLink = (string) $xmlObject->channel->link;
                $channelDescription = (string) $xmlObject->channel->description;
                foreach ($xmlObject->channel->item as $item) {
                    $itemTitle = (string) $item->title;
                    $itemLink = (string) $item->link;
                    $itemPubDate = (string) $item->pubDate;
                    $itemDescription = (string) $item->description;

                    $fullContent = $this->retrieveFullContent($itemLink); // Make sure this always returns an array
                    $images = $this->saveImagesToStorage($fullContent['images']); // Ensure this returns an array, even if empty

                    $parsedItems[] = [
                        'title' => $itemTitle,
                        'link' => $itemLink,
                        'pub_date' => $itemPubDate,
                        'description' => $itemDescription,
                        'content' => $fullContent['content'], // Make sure to access the 'content' key
                        'image_path' => $images,
                        'channel_title' => $channelTitle,
                        'channel_link' => $channelLink,
                        'channel_description' => $channelDescription,
                    ];
                }
            } catch (Exception $e) {
                // Handle exception (log or continue)
                Log::error("Error parsing RSS Feed: " . $e->getMessage());
                // Optionally skip current feedUrl or handle as needed
                continue; // Skip this feedUrl if there's an error
            }
        }
        return $parsedItems;
    }
    /**
     * @param  array  $images
     * @return array|bool
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
     * @param  string  $postLink
     * @return bool|array
     */
    public function retrieveFullContent(string $postLink): bool|array
    {
        $html = $this->fetchContentUsingCurl($postLink);
        $parsedUrl = parse_url($postLink);
        $host = $parsedUrl['host'] ?? '';
        $domains = config('rssfeed.domain_xpaths');
        $contentElementXPaths = collect($domains)->firstWhere('domain', $host)['content_element_xpaths'];
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $imageUrls = [];
        $selectedContent = '';
        $minImageWidth = config('rssfeed.min_image_width', 600); // Retrieve the minimum image width from config

        foreach ($contentElementXPaths as $xpathQuery) {
            $elements = $xpath->query($xpathQuery);
            foreach ($elements as $element) {
                $selectedContent .= $dom->saveHTML($element);
                $images = $xpath->query('.//img', $element);
                foreach ($images as $img) {
                    // Check the 'src' attribute; if it's a data URI or SVG, try 'data-src' instead
                    $src = $img->getAttribute('src');
                    if (strpos($src, 'data:image') === 0 || strpos($src, '.svg') !== false) {
                        // Try alternative attributes common in lazy loading scenarios
                        $src = $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src') ?: $img->getAttribute('data-original');
                    }
                    // Skip if no valid source is found
                    if (!$src || strpos($src, 'data:image') === 0 || strpos($src, '.svg') !== false) continue;

                    // Convert relative URLs to absolute
                    $src = $this->convertToAbsoluteUrl($src, $parsedUrl['scheme'] . '://' . $parsedUrl['host']);

                    // Add to the array if not already added
                    if (!in_array($src, $imageUrls)) {
                        $imageUrls[] = $src;
                    }
                }
            }
        }

        $selectedContent = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $selectedContent);
        $selectedContent = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $selectedContent);
        return [
            'content' => trim($selectedContent),
            'images' => $imageUrls,
        ];
    }






// The cURL fetching function from previous examples
    private function fetchContentUsingCurl(string $url): bool|string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MyRSSReader/1.0)');
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200 ? $data : false;
    }

    /**
     * Convert a relative URL to an absolute URL based on a base URL.
     *
     * @param string $url The relative or absolute URL.
     * @param string $base The base URL.
     * @return string The absolute URL.
     */
    private function convertToAbsoluteUrl(string $url, string $base): string
    {
        // If URL is already absolute, return it unchanged
        if (parse_url($url, PHP_URL_SCHEME) != '') {
            return $url;
        }

        // Parse base URL and convert relative URL to absolute
        $parts = parse_url($base);

        // Remove any non-directory component from the path
        $path = preg_replace('#/[^/]*$#', '', $parts['path']);

        // If the relative URL starts with a slash, it's relative to the root of the domain
        if ($url[0] == '/') {
            return $parts['scheme'] . '://' . $parts['host'] . $url;
        }

        // Otherwise, it's relative to the path of the base URL
        return $parts['scheme'] . '://' . $parts['host'] . rtrim($path, '/') . '/' . $url;
    }


}
