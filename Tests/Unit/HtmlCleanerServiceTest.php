<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Tests\Unit;

use Kalimeromk\Rssfeed\Services\HtmlCleanerService;
use PHPUnit\Framework\TestCase;

class HtmlCleanerServiceTest extends TestCase
{
    protected HtmlCleanerService $cleaner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleaner = new HtmlCleanerService();
    }

    /** @test */
    public function it_extracts_clean_text_from_html(): void
    {
        $html = '<div><p>This is the article content.</p><div class="donation-form">Donate now!</div></div>';

        $result = $this->cleaner->extractTextContent($html);

        $this->assertStringContainsString('This is the article content', $result);
        $this->assertStringNotContainsString('Donate now', $result);
    }

    /** @test */
    public function it_removes_donation_text_patterns(): void
    {
        $text = 'Article content можете да станете редовен дарител на Православие.БГ Още текст';

        $result = $this->cleaner->removeDonationTextPatterns($text);

        $this->assertStringContainsString('Article content', $result);
        $this->assertStringNotContainsString('дарител', $result);
        $this->assertStringContainsString('Още текст', $result);
    }

    /** @test */
    public function it_removes_english_donation_patterns(): void
    {
        $text = 'Great article content. Donate now to support our work. More content here.';

        $result = $this->cleaner->removeDonationTextPatterns($text);

        $this->assertStringContainsString('Great article content', $result);
        $this->assertStringNotContainsString('Donate now', $result);
        $this->assertStringContainsString('More content here', $result);
    }

    /** @test */
    public function it_removes_share_buttons_text(): void
    {
        $text = 'Article content Споделете on social media. End of article.';

        $result = $this->cleaner->removeDonationTextPatterns($text);

        $this->assertStringContainsString('Article content', $result);
        $this->assertStringNotContainsString('Споделете', $result);
    }

    /** @test */
    public function it_removes_scripts_and_styles_from_content(): void
    {
        $html = '<div><p>Content here</p><script>alert("xss")</script><style>.red{color:red}</style></div>';

        $result = $this->cleaner->extractTextContent($html);

        $this->assertStringContainsString('Content here', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringNotContainsString('color:red', $result);
        $this->assertStringNotContainsString('<script', $result);
    }

    /** @test */
    public function it_normalizes_whitespace_in_text(): void
    {
        $html = '<div><p>Text   with    multiple     spaces</p></div>';

        $result = $this->cleaner->extractTextContent($html);

        $this->assertEquals('Text with multiple spaces', $result);
    }

    /** @test */
    public function it_handles_empty_html_content(): void
    {
        $this->assertEquals('', $this->cleaner->extractTextContent(''));
        $this->assertEquals('', $this->cleaner->extractTextContent('   '));
    }

    /** @test */
    public function it_removes_iframes_and_embeds(): void
    {
        $html = '<div><p>Content</p><iframe src="ad.html"></iframe><embed src="video.swf"><object>Flash</object></div>';

        $result = $this->cleaner->extractTextContent($html);

        $this->assertStringContainsString('Content', $result);
        $this->assertStringNotContainsString('ad.html', $result);
        $this->assertStringNotContainsString('video.swf', $result);
        $this->assertStringNotContainsString('Flash', $result);
    }

    /** @test */
    public function it_handles_bulgarian_donation_text(): void
    {
        $text = 'Превод от чужд език и редакция на 1 страница текст за публикация.
        Авторски хонорар за статия от български автор.
        Article content here.';

        $result = $this->cleaner->removeDonationTextPatterns($text);

        $this->assertStringContainsString('Article content here', $result);
        $this->assertStringNotContainsString('Превод от чужд език', $result);
        $this->assertStringNotContainsString('Авторски хонорар', $result);
    }

    /** @test */
    public function it_removes_newsletter_signup_text(): void
    {
        $text = 'Great article content. Subscribe to our newsletter for updates. More info here.';

        $result = $this->cleaner->removeDonationTextPatterns($text);

        $this->assertStringContainsString('Great article content', $result);
        $this->assertStringNotContainsString('newsletter', $result);
    }

    /** @test */
    public function it_removes_credit_card_info_text(): void
    {
        $text = 'Article content. Credit Card Info: This is a secure SSL encrypted payment. More content.';

        $result = $this->cleaner->removeDonationTextPatterns($text);

        $this->assertStringContainsString('Article content', $result);
        $this->assertStringNotContainsString('Credit Card Info', $result);
        $this->assertStringNotContainsString('SSL encrypted', $result);
    }
}
