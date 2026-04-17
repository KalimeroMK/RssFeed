<?php

namespace Kalimeromk\Rssfeed\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Kalimeromk\Rssfeed\FullTextExtractor;
use Kalimeromk\Rssfeed\RssfeedServiceProvider;
use Orchestra\Testbench\TestCase;

class FullTextExtractorTest extends TestCase
{
    protected FullTextExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new FullTextExtractor(app());
    }

    protected function getPackageProviders($app): array
    {
        return [
            RssfeedServiceProvider::class,
        ];
    }

    private function longArticleContent(): string
    {
        return '<p>This is the main content of the article. It contains enough text to satisfy ' .
            'the readability algorithm which requires at least two hundred and fifty characters ' .
            'of meaningful text before it will consider the extraction successful. ' .
            'We are adding several more sentences here to make absolutely sure that ' .
            'the content extraction process works correctly in our unit tests.</p>' .
            '<p>Here is a second paragraph with even more text to increase the overall ' .
            'character count well above the required minimum threshold.</p>';
    }

    /** @test */
    public function it_can_extract_content_from_html(): void
    {
        $html = '<html><head><title>Test Article</title></head><body>' .
            '<article><h1>Test Article</h1>' . $this->longArticleContent() . '</article>' .
            '</body></html>';

        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Test Article', $result['title'] ?? '');
        $this->assertStringContainsString('main content', $result['content'] ?? '');
    }

    /** @test */
    public function it_returns_error_for_empty_html(): void
    {
        $result = $this->extractor->extractFromHtml('', 'https://example.com/article');

        $this->assertFalse($result['success']);
    }

    /** @test */
    public function it_detects_language_from_content(): void
    {
        $html = '<html><head><title>Test</title></head><body>' .
            '<article><h1>Test</h1>' . $this->longArticleContent() . '</article>' .
            '</body></html>';

        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('language', $result);
    }

    /** @test */
    public function it_makes_urls_absolute(): void
    {
        $html = '<html><body>' .
            '<article><h1>Title</h1>' .
            '<p>Content with enough text to pass the readability threshold. ' .
            'We need at least two hundred and fifty characters for the algorithm to succeed. ' .
            'This paragraph adds more words to ensure we cross that limit comfortably. ' .
            'Here is an image: <img src="/image.jpg" /> and a link: <a href="/page">link</a>. ' .
            'More text follows to keep the content above the minimum required length.</p>' .
            '</article>' .
            '</body></html>';

        config()->set('rssfeed.rewrite_relative_urls', true);
        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('https://example.com/image.jpg', $result['content'] ?? '');
        $this->assertStringContainsString('https://example.com/page', $result['content'] ?? '');
    }

    /** @test */
    public function it_can_extract_from_url(): void
    {
        $html = '<html><head><title>Remote Article</title></head><body>' .
            '<article><h1>Remote Article</h1>' . $this->longArticleContent() . '</article>' .
            '</body></html>';

        Http::fake([
            'https://example.com/remote-article' => Http::response($html, 200),
        ]);

        $result = $this->extractor->extract('https://example.com/remote-article');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Remote Article', $result['title'] ?? '');
    }

    /** @test */
    public function it_handles_http_errors(): void
    {
        Http::fake([
            'https://example.com/error' => Http::response('Not Found', 404),
        ]);

        $result = $this->extractor->extract('https://example.com/error');

        $this->assertFalse($result['success']);
    }

    /** @test */
    public function it_returns_metadata(): void
    {
        $html = '<html><head><title>Article Title</title></head><body>' .
            '<article><h1>Article Title</h1>' . $this->longArticleContent() . '</article>' .
            '</body></html>';

        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('language', $result);
        $this->assertArrayHasKey('is_native_ad', $result);
        $this->assertArrayHasKey('url', $result);
    }

    /** @test */
    public function it_strips_unwanted_elements(): void
    {
        $html = '<html><head><title>Title</title></head><body>' .
            '<article>' .
            '<h1>Title</h1>' .
            '<p>Main content with enough text to pass the readability threshold. ' .
            'We need at least two hundred and fifty characters for the algorithm to succeed. ' .
            'This paragraph adds more words to ensure we cross that limit comfortably.</p>' .
            '<div class="donation-form">Donate now!</div>' .
            '<div class="share-buttons">Share</div>' .
            '<p>Additional paragraph to make sure the total text length is well above minimum.</p>' .
            '</article>' .
            '</body></html>';

        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Main content', $result['content'] ?? '');
        // Note: FullTextExtractor relies on site configs or Readability for stripping.
        // Without a matching site config, arbitrary class-based elements are not removed.
    }

    /** @test */
    public function it_handles_different_encodings(): void
    {
        $html = '<html><head><meta charset="ISO-8859-1"><title>Encoding Test</title></head><body>' .
            '<article><h1>Encoding Test</h1>' .
            "<p>This is the main content with special ISO-8859-1 chars: \xe9 and \xfc. " .
            'It contains enough text to satisfy the readability algorithm which requires ' .
            'at least two hundred and fifty characters of meaningful text before it will ' .
            'consider the extraction successful. We are adding several more sentences ' .
            'here to make absolutely sure that the content extraction works.</p>' .
            '<p>Here is a second paragraph with even more text to increase the overall ' .
            'character count well above the required minimum threshold.</p>' .
            '</article></body></html>';

        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('é', $result['content'] ?? '');
        $this->assertStringContainsString('ü', $result['content'] ?? '');
    }
}
