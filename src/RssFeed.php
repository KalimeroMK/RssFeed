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
                $images = $this->saveImageToStorage($fullContent);

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
    public function saveImageToStorage(array $images): array
    {
        $savedImageNames = [];

        foreach ($images as $image) {
                $file = UrlUploadedFile::createFromUrl($image);
                $imageName = Str::random(15) . '.' . $file->extension();
                $file->storeAs('images', $imageName, 'public');
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
        // Fetch the HTML content using cURL
        $html = $this->fetchContentUsingCurl($postLink); // Use the previously defined cURL fetching function

        if ($html === false) {
            return false; // Handle the error as appropriate
        }

        // Load the HTML content into DOMDocument
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        // Use DOMXPath to work with the DOM
        $xpath = new DOMXPath($dom);

        // Initialize an array to hold the image URLs
        $imageUrls = [];
        $selectedContent = '';

        // Process each XPath query in the configuration
        foreach ($config['content_element_xpaths'] as $xpathQuery) {
            $elements = $xpath->query($xpathQuery);

            // Check if elements were found for the current XPath query
            if ($elements->length > 0) {
                foreach ($elements as $element) {
                    // Extract and concatenate the HTML of each matching element
                    $selectedContent .= $dom->saveHTML($element);

                    // Find and store all <img> tags within the current element
                    $images = $xpath->query('.//img', $element);
                    foreach ($images as $img) {
                        $src = $img->getAttribute('src');
                        $imageUrls[] = $src;
                    }
                }
            }
        }

        // Optionally, you might want to remove <script> and <style> from $selectedContent
        $selectedContent = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $selectedContent);
        $selectedContent = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $selectedContent);

        // Return the content and images
        return [
            'content' => trim($selectedContent),
            'images' => $imageUrls, // This is an array of image URLs found in the selected content
        ];
    }
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
