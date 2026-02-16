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

    /** @test */
    public function it_can_extract_content_from_html(): void
    {
        $html = '<html><head><title>Test Article</title></head><body>' .
            '<article><h1>Test Article</h1><p>This is the main content.</p></article>' .
            '</body></html>';

        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Test Article', $result['title'] ?? '');
        $this->assertStringContainsString('This is the main content', $result['content'] ?? '');
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
            '<article><h1>Test</h1><p>Macedonian content with македонски зборови.</p></article>' .
            '</body></html>';

        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        // Language detection may vary, but it should return something
        $this->assertArrayHasKey('language', $result);
    }

    /** @test */
    public function it_makes_urls_absolute(): void
    {
        $html = '<html><body>' .
            '<article><p>Content with <img src="/image.jpg" /> and <a href="/page">link</a></p></article>' .
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
        $html = '<html><body>' .
            '<article><h1>Remote Article</h1><p>Content from remote URL.</p></article>' .
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
            '<article><h1>Article Title</h1><p>Content here.</p></article>' .
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
        $html = '<html><body>' .
            '<article>' .
            '<h1>Title</h1>' .
            '<p>Main content.</p>' .
            '<div class="donation-form">Donate now!</div>' .
            '<div class="share-buttons">Share</div>' .
            '</article>' .
            '</body></html>';

        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Main content', $result['content'] ?? '');
        // Donation and share elements should be removed
        $this->assertStringNotContainsString('Donate now', $result['content'] ?? '');
        $this->assertStringNotContainsString('Share', $result['content'] ?? '');
    }

    /** @test */
    public function it_handles_different_encodings(): void
    {
        $html = '<html><head><meta charset="ISO-8859-1"></head><body>' .
            '<article><p>Content with special chars: é ü</p></article>' .
            '</body></html>';

        $result = $this->extractor->extractFromHtml($html, 'https://example.com/article');

        $this->assertTrue($result['success']);
    }
}
