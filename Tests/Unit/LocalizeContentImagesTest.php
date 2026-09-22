<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Kalimeromk\Rssfeed\RssFeed;
use Kalimeromk\Rssfeed\RssfeedServiceProvider;
use Orchestra\Testbench\TestCase;

class LocalizeContentImagesTest extends TestCase
{
    protected RssFeed $rssFeed;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->rssFeed = new RssFeed(app());
    }

    protected function getPackageProviders($app): array
    {
        return [RssfeedServiceProvider::class];
    }

    private function fakeRemoteImage(string $name = 'photo.jpg'): string
    {
        // UrlUploadedFile uses fopen(), so a local file path doubles as a "remote" URL.
        $path = sys_get_temp_dir().'/'.$name;
        // 1x1 transparent PNG bytes, regardless of extension
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        ));

        return 'file://'.$path;
    }

    public function test_it_localizes_img_src_and_wrapping_anchor_href(): void
    {
        $remote = $this->fakeRemoteImage();

        $html = '<p>На многу години!</p>'
            .'<a href="'.$remote.'" target="_blank"><img src="'.$remote.'" alt="" /></a>';

        $out = $this->rssFeed->localizeContentImages($html);

        $this->assertStringNotContainsString($remote, $out);
        $this->assertStringContainsString('/storage/images/', $out);
        $this->assertStringContainsString('На многу години!', $out); // Cyrillic text untouched
        $this->assertCount(1, Storage::disk('public')->files('images'));
    }

    public function test_it_skips_local_and_blacklisted_images(): void
    {
        $html = '<img src="/storage/images/already-local.jpg" alt="">'
            .'<img src="https://s.w.org/images/core/emoji/17.0.2/72x72/21a9.png" alt="↩">'
            .'<img src="data:image/png;base64,AAAA" alt="">';

        $out = $this->rssFeed->localizeContentImages($html);

        $this->assertSame($html, $out);
        $this->assertCount(0, Storage::disk('public')->files('images'));
    }

    public function test_it_keeps_url_when_download_fails(): void
    {
        $missing = 'file://'.sys_get_temp_dir().'/does-not-exist-'.uniqid().'.jpg';
        $html = '<img src="'.$missing.'" alt="">';

        $out = $this->rssFeed->localizeContentImages($html);

        $this->assertStringContainsString($missing, $out);
    }

    public function test_it_returns_empty_input_unchanged(): void
    {
        $this->assertSame('', $this->rssFeed->localizeContentImages(''));
        $this->assertSame('   ', $this->rssFeed->localizeContentImages('   '));
    }

    public function test_it_deduplicates_repeated_urls(): void
    {
        $remote = $this->fakeRemoteImage();
        $html = '<img src="'.$remote.'"> <img src="'.$remote.'">';

        $out = $this->rssFeed->localizeContentImages($html);

        $this->assertCount(1, Storage::disk('public')->files('images'));
        $this->assertStringNotContainsString($remote, $out);
    }
}
