<?php

namespace Kalimeromk\Rssfeed\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;
use Kalimeromk\Rssfeed\RssFeed;
use Kalimeromk\Rssfeed\RssfeedServiceProvider;
use Orchestra\Testbench\TestCase;
use SimplePie\SimplePie;

class RssFeedTest extends TestCase
{
    protected RssFeed $rssFeed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(RssfeedServiceProvider::class);

        $this->rssFeed = new RssFeed(app());
    }

    protected function getPackageProviders($app): array
    {
        return [
            RssfeedServiceProvider::class,
        ];
    }

    /**
     * Test that the RSS feed can be parsed.
     *
     * This test fakes an HTTP response for a given URL and checks if the RSS feed
     * can be parsed correctly. It asserts that the parsed RSS feed is an instance
     * of SimplePie and that the title of the feed is a non-empty string.
     *
     * @return void
     */
    public function test_it_can_parse_rss_feeds(): void
    {
        $url = 'https://www.tiveriopol.mk/feed/';
        $rss = $this->rssFeed->RssFeeds($url);
        $this->assertInstanceOf(SimplePie::class, $rss);
        $this->assertIsString($rss->get_title());
        $this->assertNotEmpty($rss->get_title());
    }

    /** @test */
    public function it_throws_exception_when_cannot_open_rss_feed(): void
    {
        $this->expectException(CantOpenFileFromUrlException::class);

        $url = 'https://invalid-url.com/rss.xml';

        Http::fake([
            $url => Http::response('', 404),
        ]);

        $this->rssFeed->RssFeeds($url);
    }

    /** @test */
    public function it_can_check_if_url_exists(): void
    {
        $validUrl = 'http://mistagogia.mk/feed';
        $invalidUrl = 'https://example.com/invalid-url';

        Http::fake([
            $validUrl => Http::response('Valid response', 200),
            $invalidUrl => Http::response('', 404),
        ]);

        $this->assertTrue($this->rssFeed->urlExists($validUrl));
        $this->assertFalse($this->rssFeed->urlExists($invalidUrl));

    }

    /** @test */
    public function it_extracts_image_from_description()
    {
        $description = '<p>Some text <img src="https://example.com/image.jpg" /> more text</p>';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/image.jpg', $imageUrl);
    }

    /** @test */
    public function it_returns_null_if_no_image_in_description()
    {
        $description = '<p>Some text without an image</p>';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description);

        $this->assertNull($imageUrl);
    }
    public function it_infers_extension_from_mime_type()
    {
        $imageUrls = [
            'https://example.com/image_without_extension',
        ];

        Http::fake([
            'https://example.com/image_without_extension' => Http::response('image content', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $rssFeed = new RssFeed(app());
        $savedImages = $rssFeed->saveImagesToStorage($imageUrls);

        $this->assertCount(1, $savedImages);
        $this->assertStringEndsWith('.jpg', $savedImages[0]);
        Storage::disk('public')->assertExists('images/' . $savedImages[0]);
    }

}
