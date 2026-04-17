<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Kalimeromk\Rssfeed\RssfeedServiceProvider;
use Kalimeromk\Rssfeed\Services\ContentFetcherService;
use Orchestra\Testbench\TestCase;

class ContentFetcherServiceTest extends TestCase
{
    protected ContentFetcherService $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = new ContentFetcherService();
    }

    protected function getPackageProviders($app): array
    {
        return [
            RssfeedServiceProvider::class,
        ];
    }

    /** @test */
    public function it_fetches_content_from_url(): void
    {
        $url = 'https://example.com/post';
        $html = '<html><body><article><p>Content</p></article></body></html>';

        Http::fake([
            $url => Http::response($html, 200),
        ]);

        $result = $this->fetcher->fetch($url);

        $this->assertEquals($html, $result);
    }

    /** @test */
    public function it_returns_null_on_http_failure(): void
    {
        $url = 'https://example.com/post';

        Http::fake([
            $url => Http::response('Not Found', 404),
        ]);

        $result = $this->fetcher->fetch($url);

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_on_exception(): void
    {
        $url = 'https://example.com/post';

        Http::fake(function () {
            throw new \Exception('Network error');
        });

        $result = $this->fetcher->fetch($url);

        $this->assertNull($result);
    }

    /** @test */
    public function it_extracts_full_content_using_selector(): void
    {
        $postUrl = 'http://example.com/post';
        $html = '<html><body><div class="content"><p>Full content</p></div></body></html>';

        Http::fake([
            $postUrl => Http::response($html, 200),
        ]);

        config()->set('rssfeed.content_selectors', ['example.com' => '//div[@class="content"]']);

        $result = $this->fetcher->fetchFullContentFromPost($postUrl);

        $this->assertStringContainsString('Full content', $result);
    }

    /** @test */
    public function it_removes_unwanted_elements_by_selector(): void
    {
        $postUrl = 'http://example.com/post';
        $html = '<html><body>'
            .'<div class="content">'
            .'<p>Real article content</p>'
            .'<div class="donation-form">Donate please!</div>'
            .'<div class="share-buttons">Share this</div>'
            .'<p>More content</p>'
            .'</div>'
            .'</body></html>';

        Http::fake([
            $postUrl => Http::response($html, 200),
        ]);

        config()->set('rssfeed.content_selectors', ['example.com' => '//div[@class="content"]']);

        $result = $this->fetcher->fetchFullContentFromPost($postUrl);

        $this->assertStringContainsString('Real article content', $result);
        $this->assertStringContainsString('More content', $result);
        $this->assertStringNotContainsString('Donate please', $result);
        $this->assertStringNotContainsString('Share this', $result);
    }

    /** @test */
    public function it_removes_comments_from_content(): void
    {
        $postUrl = 'http://example.com/post';
        $html = '<html><body>'
            .'<article>'
            .'<p>Article content</p>'
            .'<div class="comments">'
            .'<div class="comment">User comment here</div>'
            .'</div>'
            .'</article>'
            .'</body></html>';

        Http::fake([
            $postUrl => Http::response($html, 200),
        ]);

        config()->set('rssfeed.content_selectors.example.com', '//article');

        $result = $this->fetcher->fetchFullContentFromPost($postUrl);

        $this->assertStringContainsString('Article content', $result);
        $this->assertStringNotContainsString('User comment here', $result);
    }

    /** @test */
    public function it_returns_empty_string_when_no_selector_match(): void
    {
        $postUrl = 'http://example.com/post';
        $html = '<html><body><div class="other">No match</div></body></html>';

        Http::fake([
            $postUrl => Http::response($html, 200),
        ]);

        config()->set('rssfeed.content_selectors.example.com', '//div[@class="nonexistent"]');

        $result = $this->fetcher->fetchFullContentFromPost($postUrl);

        $this->assertEquals('', $result);
    }

    /** @test */
    public function it_handles_different_encodings(): void
    {
        $postUrl = 'http://example.com/post';
        $html = "<html><head><meta charset=\"ISO-8859-1\"></head><body>"
            ."<div class=\"content\"><p>Special chars: \xe9 \xfc</p></div>"
            ."</body></html>";

        Http::fake([
            $postUrl => Http::response($html, 200),
        ]);

        config()->set('rssfeed.content_selectors', ['example.com' => '//div[@class="content"]']);

        $result = $this->fetcher->fetchFullContentFromPost($postUrl);

        $this->assertStringContainsString('Special chars', $result);
    }
}
