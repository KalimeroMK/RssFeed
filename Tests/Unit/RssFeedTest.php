<?php

namespace Kalimeromk\Rssfeed\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
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
     * @throws CantOpenFileFromUrlException
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
    public function it_extracts_image_from_description(): void
    {
        $description = '<p>Some text <img src="https://example.com/image.jpg"  alt=""/> more text</p>';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/image.jpg', $imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_enclosure_tag(): void
    {
        $description = '<enclosure url="https://example.com/enclosure.jpg" length="123" type="image/jpeg" />';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/enclosure.jpg', $imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_media_content_tag(): void
    {
        $description = '<media:content url="https://example.com/media-content.jpg" type="image/jpeg" />';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/media-content.jpg', $imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_media_thumbnail_tag(): void
    {
        $description = '<media:thumbnail url="https://example.com/media-thumb.jpg" />';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/media-thumb.jpg', $imageUrl);
    }

    /** @test */
    public function it_returns_null_if_no_image_in_description(): void
    {
        $description = '<p>Some text without an image</p>';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description);

        $this->assertNull($imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_img_srcset(): void
    {
        $description = '<img srcset="https://example.com/small.jpg 320w, https://example.com/large.jpg 640w" />';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/large.jpg', $imageUrl);
    }

    /** @test */
    public function it_extracts_image_from_img_data_src(): void
    {
        $description = '<img data-src="https://example.com/lazy.jpg" />';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description);

        $this->assertEquals('https://example.com/lazy.jpg', $imageUrl);
    }

    /** @test */
    public function it_resolves_relative_image_url_with_base(): void
    {
        $description = '<img src="/images/cover.jpg" />';

        $rssFeed = new RssFeed(app());
        $imageUrl = $rssFeed->extractImageFromDescription($description, 'https://example.com/posts/1');

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

        config()->set('rssfeed.content_selectors.example.com', '//div[@class="content"]');

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

        config()->set("rssfeed.content_selectors.$domain", $selector);
        $this->assertEquals($selector, config("rssfeed.content_selectors.$domain"));
    }

    /** @test */
    public function it_extracts_clean_text_from_html(): void
    {
        $html = '<div><p>This is the article content.</p><div class="donation-form">Donate now!</div></div>';
        
        // Use reflection to test private method
        $method = new \ReflectionMethod($this->rssFeed, 'extractTextContent');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $html);
        
        $this->assertStringContainsString('This is the article content', $result);
        $this->assertStringNotContainsString('Donate now', $result);
    }

    /** @test */
    public function it_removes_donation_text_patterns(): void
    {
        $text = 'Article content можете да станете редовен дарител на Православие.БГ Още текст';
        
        // Use reflection to test private method
        $method = new \ReflectionMethod($this->rssFeed, 'removeDonationTextPatterns');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $text);
        
        $this->assertStringContainsString('Article content', $result);
        $this->assertStringNotContainsString('дарител', $result);
        $this->assertStringContainsString('Още текст', $result);
    }

    /** @test */
    public function it_removes_english_donation_patterns(): void
    {
        $text = 'Great article content. Donate now to support our work. More content here.';
        
        $method = new \ReflectionMethod($this->rssFeed, 'removeDonationTextPatterns');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $text);
        
        $this->assertStringContainsString('Great article content', $result);
        $this->assertStringNotContainsString('Donate now', $result);
        $this->assertStringContainsString('More content here', $result);
    }

    /** @test */
    public function it_removes_share_buttons_text(): void
    {
        $text = 'Article content Споделете on social media. End of article.';
        
        $method = new \ReflectionMethod($this->rssFeed, 'removeDonationTextPatterns');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $text);
        
        $this->assertStringContainsString('Article content', $result);
        $this->assertStringNotContainsString('Споделете', $result);
    }

    /** @test */
    public function it_removes_unwanted_elements_from_dom(): void
    {
        $html = '<html><body>
            <div class="content">
                <p>Real article content</p>
                <div class="donation-form">Donate please!</div>
                <div class="share-buttons">Share this</div>
                <p>More content</p>
            </div>
        </body></html>';
        
        // Create a mock DOM and XPath
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        
        // Store original content
        $originalContent = $dom->textContent;
        $this->assertStringContainsString('Donate please', $originalContent);
        
        // Use reflection to test private method
        $method = new \ReflectionMethod($this->rssFeed, 'removeUnwantedElements');
        $method->setAccessible(true);
        $method->invoke($this->rssFeed, $dom, $xpath);
        
        // Check that unwanted elements are removed
        $cleanContent = $dom->textContent;
        $this->assertStringContainsString('Real article content', $cleanContent);
        $this->assertStringContainsString('More content', $cleanContent);
        $this->assertStringNotContainsString('Donate please', $cleanContent);
        $this->assertStringNotContainsString('Share this', $cleanContent);
    }

    /** @test */
    public function it_converts_css_selectors_to_xpath(): void
    {
        $method = new \ReflectionMethod($this->rssFeed, 'cssSelectorToXPath');
        $method->setAccessible(true);
        
        // Test class selector
        $this->assertEquals("//*[contains(@class, 'donation-form')]", $method->invoke($this->rssFeed, '.donation-form'));
        
        // Test ID selector
        $this->assertEquals("//*[@id='comments']", $method->invoke($this->rssFeed, '#comments'));
        
        // Test tag selector
        $this->assertEquals('//script', $method->invoke($this->rssFeed, 'script'));
        
        // Test tag.class selector
        $this->assertEquals("//div[contains(@class, 'content')]", $method->invoke($this->rssFeed, 'div.content'));
        
        // Test attribute contains selector
        $this->assertEquals("//*[contains(@class, 'ad')]", $method->invoke($this->rssFeed, '[class*="ad"]'));
        
        // Test attribute equals selector
        $this->assertEquals("//*[@role='main']", $method->invoke($this->rssFeed, '[role="main"]'));
    }

    /** @test */
    public function it_extracts_clean_text_from_post(): void
    {
        $postUrl = 'http://example.com/post';
        $htmlContent = '<html><body>
            <article>
                <h1>Article Title</h1>
                <p>This is the main content.</p>
                <div class="donation-form">Donate now!</div>
                <div class="share-buttons">Share</div>
            </article>
        </body></html>';

        Http::fake([
            $postUrl => Http::response($htmlContent, 200),
        ]);

        config()->set('rssfeed.content_selectors.example.com', '//article');

        $result = $this->rssFeed->fetchCleanTextFromPost($postUrl);
        
        $this->assertStringContainsString('Article Title', $result);
        $this->assertStringContainsString('This is the main content', $result);
        $this->assertStringNotContainsString('Donate now', $result);
        $this->assertStringNotContainsString('<div', $result); // No HTML tags
    }

    /** @test */
    public function it_removes_scripts_and_styles_from_content(): void
    {
        $html = '<div><p>Content here</p><script>alert("xss")</script><style>.red{color:red}</style></div>';
        
        $method = new \ReflectionMethod($this->rssFeed, 'extractTextContent');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $html);
        
        $this->assertStringContainsString('Content here', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringNotContainsString('color:red', $result);
        $this->assertStringNotContainsString('<script', $result);
    }

    /** @test */
    public function it_normalizes_whitespace_in_text(): void
    {
        $html = '<div><p>Text   with    multiple     spaces</p></div>';
        
        $method = new \ReflectionMethod($this->rssFeed, 'extractTextContent');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $html);
        
        $this->assertEquals('Text with multiple spaces', $result);
    }

    /** @test */
    public function it_handles_empty_html_content(): void
    {
        $method = new \ReflectionMethod($this->rssFeed, 'extractTextContent');
        $method->setAccessible(true);
        
        $this->assertEquals('', $method->invoke($this->rssFeed, ''));
        $this->assertEquals('', $method->invoke($this->rssFeed, '   '));
    }

    /** @test */
    public function it_removes_iframes_and_embeds(): void
    {
        $html = '<div><p>Content</p><iframe src="ad.html"></iframe><embed src="video.swf"><object>Flash</object></div>';
        
        $method = new \ReflectionMethod($this->rssFeed, 'extractTextContent');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $html);
        
        $this->assertStringContainsString('Content', $result);
        $this->assertStringNotContainsString('ad.html', $result);
        $this->assertStringNotContainsString('video.swf', $result);
        $this->assertStringNotContainsString('Flash', $result);
    }

    /** @test */
    public function it_removes_comments_from_content(): void
    {
        $html = '<html><body>
            <article>
                <p>Article content</p>
                <div class="comments">
                    <div class="comment">User comment here</div>
                </div>
            </article>
        </body></html>';
        
        // Create DOM and XPath
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        
        // Remove unwanted elements
        $method = new \ReflectionMethod($this->rssFeed, 'removeUnwantedElements');
        $method->setAccessible(true);
        $method->invoke($this->rssFeed, $dom, $xpath);
        
        $result = $dom->textContent;
        
        $this->assertStringContainsString('Article content', $result);
        $this->assertStringNotContainsString('User comment here', $result);
    }

    /** @test */
    public function it_extracts_text_without_donation_sidebar(): void
    {
        $html = '<div class="article-wrapper">
            <div class="main-content">
                <p>Important article text here.</p>
            </div>
            <div class="sidebar">
                <div class="donation-box">Support us with $10</div>
                <div class="ad">Buy our product</div>
            </div>
        </div>';
        
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        
        // Remove unwanted elements
        $removeMethod = new \ReflectionMethod($this->rssFeed, 'removeUnwantedElements');
        $removeMethod->setAccessible(true);
        $removeMethod->invoke($this->rssFeed, $dom, $xpath);
        
        $result = $dom->textContent;
        
        $this->assertStringContainsString('Important article text here', $result);
        $this->assertStringNotContainsString('Support us', $result);
        $this->assertStringNotContainsString('Buy our product', $result);
    }

    /** @test */
    public function it_handles_bulgarian_donation_text(): void
    {
        $text = 'Превод от чужд език и редакция на 1 страница текст за публикация. 
        Авторски хонорар за статия от български автор. 
        Article content here.';
        
        $method = new \ReflectionMethod($this->rssFeed, 'removeDonationTextPatterns');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $text);
        
        $this->assertStringContainsString('Article content here', $result);
        $this->assertStringNotContainsString('Превод от чужд език', $result);
        $this->assertStringNotContainsString('Авторски хонорар', $result);
    }

    /** @test */
    public function it_removes_newsletter_signup_text(): void
    {
        $text = 'Great article content. Subscribe to our newsletter for updates. More info here.';
        
        $method = new \ReflectionMethod($this->rssFeed, 'removeDonationTextPatterns');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $text);
        
        $this->assertStringContainsString('Great article content', $result);
        $this->assertStringNotContainsString('newsletter', $result);
    }

    /** @test */
    public function it_removes_credit_card_info_text(): void
    {
        $text = 'Article content. Credit Card Info: This is a secure SSL encrypted payment. More content.';
        
        $method = new \ReflectionMethod($this->rssFeed, 'removeDonationTextPatterns');
        $method->setAccessible(true);
        $result = $method->invoke($this->rssFeed, $text);
        
        $this->assertStringContainsString('Article content', $result);
        $this->assertStringNotContainsString('Credit Card Info', $result);
        $this->assertStringNotContainsString('SSL encrypted', $result);
    }
}
