<?php

declare(strict_types=1);

namespace Grav\Plugin\VisitorInsights\Tests;

use Grav\Plugin\VisitorInsights\Classifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase
{
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
    private const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';

    public static function bots(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'headless chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/131.0 Safari/537.36'],
            'curl' => ['curl/8.5.0'],
            'python' => ['python-requests/2.32.3'],
            'lighthouse' => ['Mozilla/5.0 (Linux; Android 11) AppleWebKit/537.36 Chrome/131 Mobile Safari/537.36 Chrome-Lighthouse'],
            'facebook preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('bots')]
    public function bots_are_recognised(string $ua): void
    {
        $this->assertTrue(Classifier::isBot($ua));
    }

    #[Test]
    public function real_browsers_are_not_bots(): void
    {
        $this->assertFalse(Classifier::isBot(self::IPHONE));
        $this->assertFalse(Classifier::isBot(self::MAC));
    }

    #[Test]
    public function devices(): void
    {
        $this->assertSame('phone', Classifier::device(self::IPHONE, 390));
        $this->assertSame('computer', Classifier::device(self::MAC, 1440));
        $this->assertSame('phone', Classifier::device(self::MAC, 500));
        $this->assertSame('tablet', Classifier::device('Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1', 820));
    }

    public static function sources(): array
    {
        return [
            'google' => ['https://www.google.com/', '', 'Google'],
            'google uk' => ['https://www.google.co.uk/', '', 'Google'],
            'google maps' => ['https://www.google.com/maps/place/x', '', 'Google Maps'],
            'maps subdomain' => ['https://maps.google.com/', '', 'Google Maps'],
            'instagram' => ['https://l.instagram.com/', '', 'Instagram'],
            'facebook mobile' => ['https://m.facebook.com/', '', 'Facebook'],
            'chatgpt' => ['https://chatgpt.com/', '', 'ChatGPT'],
            'x short links' => ['https://t.co/abc', '', 'X (Twitter)'],
            'gemini' => ['https://gemini.google.com/app', '', 'Gemini'],
            'facebook uk' => ['https://www.facebook.co.uk/', '', 'Facebook'],
            'unknown site' => ['https://www.localdirectory.co.uk/listing/9', '', 'localdirectory.co.uk'],
            'direct' => ['', '', 'Direct'],
            'utm wins' => ['https://www.google.com/', 'qr-poster', 'QR code'],
            'utm other' => ['', 'spring sale', 'Spring sale'],
        ];
    }

    #[Test]
    #[DataProvider('sources')]
    public function sources_are_named(string $ref, string $utm, string $expected): void
    {
        $this->assertSame($expected, Classifier::source($ref, $utm, 'example.com'));
    }

    #[Test]
    public function same_site_referrer_is_not_a_source(): void
    {
        $this->assertNull(Classifier::source('https://example.com/menu', '', 'example.com'));
        $this->assertNull(Classifier::source('https://www.example.com/menu', '', 'example.com'));
    }

    #[Test]
    public function paths_and_keys_are_cleaned(): void
    {
        $this->assertSame('/menu', Classifier::cleanPath('/menu/?q=<script>'));
        $this->assertSame('/', Classifier::cleanPath(''));
        $this->assertSame('scriptalert1script', Classifier::cleanKey('<script>alert(1)</script>'));
        $this->assertSame('Our menu', Classifier::cleanLabel("  <b>Our</b>\n menu "));
    }
}
