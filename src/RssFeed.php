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

                // Save the image to storage
                $imagePath = $this->saveImageToStorage($itemDescription);

                // Retrieve the full post content
                $fullContent = $this->retrieveFullContent($itemLink);

                // Add the extracted item data to the parsedItems array
                $parsedItems[] = [
                    'title' => $itemTitle,
                    'link' => $itemLink,
                    'pub_date' => $itemPubDate,
                    'description' => $itemDescription,
                    'content' => $fullContent,
                    'image_path' => $imagePath,
                    'channel_title' => $channelTitle,
                    'channel_link' => $channelLink,
                    'channel_description' => $channelDescription,
                ];
            }
        }

        return $parsedItems;
    }

    /**
     * @param  string  $itemDescription
     * @return string
     * @throws CantOpenFileFromUrlException
     */
    public function saveImageToStorage(string $itemDescription): string
    {
        $find_img = $this->getImageWithSizeGreaterThan($itemDescription);
        $file = UrlUploadedFile::createFromUrl($find_img);
        $imageName = Str::random(15) . '.' . $file->extension();
        $file->storeAs('images', $imageName, 'public');
        return $imageName;
    }

    public function retrieveFullContent(string $postLink): bool|string
    {
        // Fetch the HTML content of the post URL
        $html = file_get_contents($postLink);

        // Create a DOMDocument object and load the HTML content
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        // Create a DOMXPath object
        $xpath = new DOMXPath($dom);

        // Array of XPath expressions for elements where the post content may be located
        $contentElementXPaths = config('rssfeed.content_element_xpaths', []);

        $fullContent = '';

        // Loop through the content element XPath expressions
        foreach ($contentElementXPaths as $xpathExpression) {
            // Find the elements matching the current XPath expression
            $contentElements = $xpath->query($xpathExpression);

            // If elements are found, extract the content from the first match
            if ($contentElements->length > 0) {
                $contentElement = $contentElements[0];

                // Extract the content from the element
                $fullContent = $dom->saveHTML($contentElement);

                // Break the loop since we found the content
                break;
            }
        }

        // Return the retrieved full post content
        return $fullContent;
    }

    /**
     * @param  string  $html
     * @param  int  $size
     * @return string|null
     */
    public static function getImageWithSizeGreaterThan(string $html, int $size = 200): ?string
    {
        ini_set('allow_url_fopen', 1);

        $html_parser = new Htmldom();
        $html_parser->str_get_html($html);

        $featured_img = '';

        foreach ($html_parser->find('img') as $img) {
            try {
                [$width] = getimagesize($img->src);

                if ($width >= $size) {
                    $featured_img = $img->src;
                    break;
                }
            } catch (Exception $e) {
                // Do nothing if image cannot be processed
            }
        }

        return $featured_img ?: null;
    }
}
