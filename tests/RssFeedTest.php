<?php

namespace Kalimeromk\Rssfeed\Tests;

use Illuminate\Support\Facades\Storage;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;
use Kalimeromk\Rssfeed\Helpers\UrlUploadedFile;
use Kalimeromk\Rssfeed\RssFeed;
use PHPUnit\Framework\MockObject\Exception;
use Tests\TestCase;

class RssFeedTest extends TestCase
{
    protected RssFeed $rssFeed;

    public function setUp(): void
    {
        parent::setUp();

        $this->rssFeed = new RssFeed();
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public function testParseRssFeeds()
    {
        $feedUrls = ['http://example.com/feed1', 'http://example.com/feed2'];

        // Create a stub for the some_method method.
        $stub = $this->createStub(RssFeed::class);

        // Configure the stub.
        $stub->method('parseRssFeeds')
            ->with($feedUrls)
            ->willReturn([
                [
                    'title' => 'Item 1',
                    'link' => 'https://example.com',
                    'pub_date' => '2023-06-11',
                    'description' => 'Item 1 description',
                    'content' => 'Item 1 full content',
                    'image_path' => 'image1.jpg',
                    'channel_title' => 'Channel Title',
                    'channel_link' => 'https://channel.com',
                    'channel_description' => 'Channel description',
                ],
            ]);

        $this->assertSame('Item 1', $stub->parseRssFeeds($feedUrls)[0]['title']);
    }

    /**
     * @throws CantOpenFileFromUrlException
     */
    public function testSaveImageToStorage()
    {
        Storage::fake('public');

        $url = 'https://example.com/image.jpg';
        $file = UrlUploadedFile::createFromUrl($url);
        $imageName = $this->rssFeed->saveImageToStorage('<img src="' . $url . '" />');

        // Assert the file was stored...
        Storage::disk('public')->assertExists('images/' . $imageName);

        // Assert a file does not exist...
        Storage::disk('public')->assertMissing('missing.jpg');
    }

    public function testRetrieveFullContent()
    {
        $postLink = 'https://example.com/post1';
        $content = $this->rssFeed->retrieveFullContent($postLink);

        $this->assertIsString($content);
    }

    public function testGetImageWithSizeGreaterThan()
    {
        $html = '<img src="https://example.com/image.jpg" width="500" height="500">';
        $imageSrc = RssFeed::getImageWithSizeGreaterThan($html, 200);

        $this->assertEquals('https://example.com/image.jpg', $imageSrc);
    }
}
