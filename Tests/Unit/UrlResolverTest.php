<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Tests\Unit;

use Kalimeromk\Rssfeed\Services\UrlResolver;
use PHPUnit\Framework\TestCase;

class UrlResolverTest extends TestCase
{
    protected UrlResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new UrlResolver;
    }

    /** @test */
    public function it_returns_absolute_url_unchanged(): void
    {
        $this->assertEquals('https://example.com/image.jpg', $this->resolver->resolveUrl('https://example.com/image.jpg', 'https://example.com'));
    }

    /** @test */
    public function it_resolves_protocol_relative_url(): void
    {
        $this->assertEquals('https://cdn.example.com/file.js', $this->resolver->resolveUrl('//cdn.example.com/file.js', 'https://example.com'));
    }

    /** @test */
    public function it_resolves_absolute_path(): void
    {
        $this->assertEquals('https://example.com/images/photo.jpg', $this->resolver->resolveUrl('/images/photo.jpg', 'https://example.com/blog/post'));
    }

    /** @test */
    public function it_resolves_relative_path(): void
    {
        $this->assertEquals('https://example.com/blog/assets/style.css', $this->resolver->resolveUrl('assets/style.css', 'https://example.com/blog/post'));
    }

    /** @test */
    public function it_returns_null_for_data_uri(): void
    {
        $this->assertNull($this->resolver->resolveUrl('data:image/png;base64,abc123', 'https://example.com'));
    }

    /** @test */
    public function it_returns_null_for_empty_url(): void
    {
        $this->assertNull($this->resolver->resolveUrl('', 'https://example.com'));
        $this->assertNull($this->resolver->resolveUrl(null, 'https://example.com'));
    }

    /** @test */
    public function it_returns_url_as_is_without_base(): void
    {
        $this->assertEquals('relative/path.jpg', $this->resolver->resolveUrl('relative/path.jpg', null));
    }

    /** @test */
    public function it_returns_null_for_invalid_base(): void
    {
        $this->assertNull($this->resolver->makeAbsolute('not-a-url', 'path.jpg'));
    }
}
