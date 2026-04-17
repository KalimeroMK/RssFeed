<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;
use Kalimeromk\Rssfeed\RssFeed;
use Kalimeromk\Rssfeed\RssfeedServiceProvider;
use Orchestra\Testbench\TestCase;
use SimplePie\Item;
use SimplePie\SimplePie;

class RssFeedTest extends TestCase
{
    protected RssFeed $rssFeed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rssFeed = new RssFeed(app());
    }

    protected function getPackageProviders($app): array
    {
        return [
            RssfeedServiceProvider::class,
        ];
    }

    public function test_it_can_parse_rss_feeds(): void
    {
        $url = 'https://example.com/feed/';

        Http::fake([
            $url => Http::response('<rss></rss>', 200),
        ]);

        $simplePie = $this->createMock(SimplePie::class);
        $simplePie->method('get_title')->willReturn('Test Feed');
        app()->instance(SimplePie::class, $simplePie);

        $rss = $this->rssFeed->rssFeeds($url);
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

        $this->rssFeed->rssFeeds($url);
    }

    /** @test */
    public function it_can_check_if_url_exists(): void
    {
        $validUrl = 'http://example.com/feed';
        $invalidUrl = 'https://example.com/invalid-url';

        Http::fake([
            $validUrl => Http::response('Valid response', 200),
            $invalidUrl => Http::response('', 404),
        ]);

        $this->assertTrue($this->rssFeed->urlExists($validUrl));
        $this->assertFalse($this->rssFeed->urlExists($invalidUrl));
    }

    /** @test */
    public function it_extracts_image_from_description(): void
    {
        $description = '<p>Some text <img src="https://example.com/image.jpg"  alt=""/> more text</p>';

        $imageUrl = $this->rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/image.jpg', $imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_enclosure_tag(): void
    {
        $description = '<enclosure url="https://example.com/enclosure.jpg" length="123" type="image/jpeg" />';

        $imageUrl = $this->rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/enclosure.jpg', $imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_media_content_tag(): void
    {
        $description = '<media:content url="https://example.com/media-content.jpg" type="image/jpeg" />';

        $imageUrl = $this->rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/media-content.jpg', $imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_media_thumbnail_tag(): void
    {
        $description = '<media:thumbnail url="https://example.com/media-thumb.jpg" />';

        $imageUrl = $this->rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/media-thumb.jpg', $imageUrl);
    }

    /** @test */
    public function it_returns_null_if_no_image_in_description(): void
    {
        $description = '<p>Some text without an image</p>';

        $imageUrl = $this->rssFeed->extractImageFromDescription($description);

        $this->assertNull($imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_img_srcset(): void
    {
        $description = '<img srcset="https://example.com/small.jpg 320w, https://example.com/large.jpg 640w" />';

        $imageUrl = $this->rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/large.jpg', $imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_img_data_src(): void
    {
        $description = '<img data-src="https://example.com/lazy.jpg" />';

        $imageUrl = $this->rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/lazy.jpg', $imageUrl);
    }

    /** @test */
    public function it_resolves_relative_image_url_with_base(): void
    {
        $description = '<img src="/images/cover.jpg" />';

        $imageUrl = $this->rssFeed->extractImageFromDescription($description, 'https://example.com/posts/1');

        $this->assertEquals('https://example.com/images/cover.jpg', $imageUrl);
    }

    public function test_fetches_full_content_from_valid_post_url(): void
    {
        $postUrl = 'http://example.com/post';
        $htmlContent = '<html><body><div class="content">Full content</div></body></html>';
        $expectedContent = '<div class="content">Full content</div>';

        Http::fake([
            $postUrl => Http::response($htmlContent, 200),
        ]);

        config()->set('rssfeed.content_selectors', ['example.com' => '//div[@class="content"]']);

        $result = $this->rssFeed->fetchFullContentFromPost($postUrl);

        $this->assertEquals($expectedContent, $result);
    }

    public function test_returns_empty_string_when_http_request_fails(): void
    {
        $postUrl = 'http://example.com/post';

        Http::fake([
            $postUrl => Http::response('', 404),
        ]);

        $result = $this->rssFeed->fetchFullContentFromPost($postUrl);

        $this->assertEquals('', $result);
    }

    public function test_returns_empty_string_when_no_matching_nodes_found(): void
    {
        $postUrl = 'http://example.com/post';
        $htmlContent = '<html><body><div class="other">No matching content</div></body></html>';

        Http::fake([
            $postUrl => Http::response($htmlContent, 200),
        ]);

        config()->set('rssfeed.content_selectors.example.com', '//div[@class="nonexistent"]');

        $result = $this->rssFeed->fetchFullContentFromPost($postUrl);

        $this->assertEquals('', $result);
    }

    public function test_returns_empty_string_when_exception_thrown(): void
    {
        $postUrl = 'http://example.com/post';

        Http::fake(function () {
            throw new \Exception('Error message');
        });

        $result = $this->rssFeed->fetchFullContentFromPost($postUrl);

        $this->assertEquals('', $result);
    }

    public function test_content_selectors_for_specific_domain(): void
    {
        $domain = 'example.com';
        $selector = '//div[@class="content"]';

        config()->set('rssfeed.content_selectors', [$domain => $selector]);
        $this->assertEquals($selector, config('rssfeed.content_selectors')[$domain]);
    }

    /** @test */
    public function it_extracts_clean_text_from_post(): void
    {
        $postUrl = 'http://example.com/post';
        $htmlContent = '<html><body>'
            .'<article>'
            .'<h1>Article Title</h1>'
            .'<p>This is the main content.</p>'
            .'<div class="donation-form">Donate now!</div>'
            .'<div class="share-buttons">Share</div>'
            .'</article>'
            .'</body></html>';

        Http::fake([
            $postUrl => Http::response($htmlContent, 200),
        ]);

        config()->set('rssfeed.content_selectors', ['example.com' => '//article']);

        $result = $this->rssFeed->fetchCleanTextFromPost($postUrl);

        $this->assertStringContainsString('Article Title', $result);
        $this->assertStringContainsString('This is the main content', $result);
        $this->assertStringNotContainsString('Donate now', $result);
        $this->assertStringNotContainsString('<div', $result);
    }

    /** @test */
    public function it_parses_rss_feeds_and_extracts_items(): void
    {
        $url = 'https://example.com/rss.xml';

        $itemMock = $this->createMock(Item::class);
        $itemMock->method('get_title')->willReturn('Item 1');
        $itemMock->method('get_description')->willReturn('Desc 1');
        $itemMock->method('get_link')->willReturn('https://example.com/1');
        $itemMock->method('get_categories')->willReturn([]);
        $itemMock->method('get_date')->willReturn(null);
        $itemMock->method('get_enclosure')->willReturn(null);
        $itemMock->method('get_content')->willReturn('');
        $itemMock->method('get_author')->willReturn(null);
        $itemMock->method('get_copyright')->willReturn(null);

        $simplePie = $this->createMock(SimplePie::class);
        $simplePie->method('get_items')->willReturn([$itemMock]);
        $simplePie->method('get_permalink')->willReturn('https://example.com');
        $simplePie->method('get_language')->willReturn('en');
        app()->instance(SimplePie::class, $simplePie);

        $items = $this->rssFeed->parseRssFeeds($url);

        $this->assertCount(1, $items);
        $this->assertEquals('Item 1', $items[0]['title']);
        $this->assertEquals('Desc 1', $items[0]['description']);
    }

    /** @test */
    public function it_parses_rss_feeds_clean(): void
    {
        $url = 'https://example.com/rss.xml';

        $itemMock = $this->createMock(Item::class);
        $itemMock->method('get_title')->willReturn('Item 1');
        $itemMock->method('get_description')->willReturn('<p>Desc 1</p><script>alert(1)</script>');
        $itemMock->method('get_link')->willReturn('https://example.com/1');
        $itemMock->method('get_categories')->willReturn([]);
        $itemMock->method('get_date')->willReturn(null);
        $itemMock->method('get_enclosure')->willReturn(null);
        $itemMock->method('get_content')->willReturn('');
        $itemMock->method('get_author')->willReturn(null);
        $itemMock->method('get_copyright')->willReturn(null);

        $simplePie = $this->createMock(SimplePie::class);
        $simplePie->method('get_items')->willReturn([$itemMock]);
        $simplePie->method('get_permalink')->willReturn('https://example.com');
        $simplePie->method('get_language')->willReturn('en');
        app()->instance(SimplePie::class, $simplePie);

        $items = $this->rssFeed->parseRssFeedsClean($url);

        $this->assertCount(1, $items);
        $this->assertStringContainsString('Desc 1', $items[0]['description']);
        $this->assertStringNotContainsString('alert', $items[0]['description']);
        $this->assertStringNotContainsString('<script', $items[0]['description']);
    }
}
