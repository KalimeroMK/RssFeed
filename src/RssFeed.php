<?php

namespace Kalimeromk\Rssfeed;

use DOMDocument;
use DOMXPath;
use Exception;
use Htmldom\Htmldom;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;
use Kalimeromk\Rssfeed\Helpers\UrlUploadedFile;
use SimpleXMLElement;

class RssFeed implements ShouldQueue
{
    use Dispatchable;

    /**
     * @param  array  $feedUrls
     * @param  null  $jobId
     * @return array
     * @throws CantOpenFileFromUrlException
     * @throws Exception
     */
    public function parseRssFeeds(array $feedUrls, $jobId = null): array
    {
        $parsedItems = [];

        foreach ($feedUrls as $feedUrl) {
            $xml = file_get_contents($feedUrl);
            $xmlObject = new SimpleXMLElement($xml);

            $channelTitle = (string) $xmlObject->channel->title;
            $channelLink = (string) $xmlObject->channel->link;
            $channelDescription = (string) $xmlObject->channel->description;

            foreach ($xmlObject->channel->item as $item) {
                $itemTitle = (string) $item->title;
                $itemLink = (string) $item->link;
                $itemPubDate = (string) $item->pubDate;
                $itemDescription = (string) $item->description;

                // Retrieve the full post content
                $fullContent = $this->retrieveFullContent($itemLink);

                // Save the image to storage
                $images = $this->saveImagesToStorage($fullContent['images']);

                // Add the extracted item data to the parsedItems array
                $parsedItems[] = [
                    'title' => $itemTitle,
                    'link' => $itemLink,
                    'pub_date' => $itemPubDate,
                    'description' => $itemDescription,
                    'content' => $fullContent,
                    'image_path' => $images,
                    'channel_title' => $channelTitle,
                    'channel_link' => $channelLink,
                    'channel_description' => $channelDescription,
                ];
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
            if ($elements->length > 0) {
                foreach ($elements as $element) {
                    $selectedContent .= $dom->saveHTML($element);
                    $images = $xpath->query('.//img', $element);
                    foreach ($images as $img) {
                        $src = $img->getAttribute('src');
                        // Get image dimensions
                        list($width, $height) = getimagesize($src);
                        if ($width >= $minImageWidth) {
                            // Add to array if not already added
                            if (!in_array($src, $imageUrls)) {
                                $imageUrls[] = $src;
                            }
                        }
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


}
