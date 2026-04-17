<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Tests\Unit;

use Kalimeromk\Rssfeed\Services\CssSelectorConverter;
use PHPUnit\Framework\TestCase;

class CssSelectorConverterTest extends TestCase
{
    protected CssSelectorConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new CssSelectorConverter();
    }

    /** @test */
    public function it_converts_class_selector(): void
    {
        $this->assertEquals("//*[contains(@class, 'donation-form')]", $this->converter->toXPath('.donation-form'));
    }

    /** @test */
    public function it_converts_id_selector(): void
    {
        $this->assertEquals("//*[@id='comments']", $this->converter->toXPath('#comments'));
    }

    /** @test */
    public function it_converts_tag_selector(): void
    {
        $this->assertEquals('//script', $this->converter->toXPath('script'));
    }

    /** @test */
    public function it_converts_tag_class_selector(): void
    {
        $this->assertEquals("//div[contains(@class, 'content')]", $this->converter->toXPath('div.content'));
    }

    /** @test */
    public function it_converts_tag_id_selector(): void
    {
        $this->assertEquals("//div[@id='main']", $this->converter->toXPath('div#main'));
    }

    /** @test */
    public function it_converts_attribute_contains_selector(): void
    {
        $this->assertEquals("//*[contains(@class, 'ad')]", $this->converter->toXPath('[class*="ad"]'));
    }

    /** @test */
    public function it_converts_attribute_equals_selector(): void
    {
        $this->assertEquals("//*[@role='main']", $this->converter->toXPath('[role="main"]'));
    }

    /** @test */
    public function it_converts_attribute_exists_selector(): void
    {
        $this->assertEquals('//*[@disabled]', $this->converter->toXPath('[disabled]'));
    }

    /** @test */
    public function it_escapes_single_quotes_in_values(): void
    {
        $result = $this->converter->toXPath("[data-test=\"it's\"]");
        $this->assertStringContainsString("concat('", $result);
        $this->assertStringNotContainsString("it's", $result);
    }

    /** @test */
    public function it_throws_for_empty_selector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->converter->toXPath('');
    }

    /** @test */
    public function it_throws_for_invalid_class_selector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->converter->toXPath('.');
    }

    /** @test */
    public function it_throws_for_invalid_id_selector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->converter->toXPath('#');
    }

    /** @test */
    public function it_throws_for_invalid_name_in_selector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->converter->toXPath('div<script>');
    }
}
