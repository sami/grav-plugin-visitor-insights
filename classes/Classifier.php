<?php

declare(strict_types=1);

namespace Grav\Plugin\VisitorInsights;

/**
 * Small, pure helpers: is this a bot, what kind of device, where did the visit come from.
 * No Grav dependency, so it can be unit tested on its own.
 */
final class Classifier
{
    private const BOT_PATTERN = '/bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|embedly|preview|headless|phantom|puppeteer|playwright|selenium|python|curl|wget|httpclient|http-client|lighthouse|pagespeed|pingdom|uptime|monitor|scan|okhttp|java\/|go-http|axios|node-fetch|insomnia|postman/i';

    /** Known search engines and social sites, matched against the referrer's host. */
    private const SOURCES = [
        'bing.' => 'Bing', 'duckduckgo.' => 'DuckDuckGo', 'yahoo.' => 'Yahoo', 'ecosia.' => 'Ecosia',
        'facebook.' => 'Facebook', 'fb.' => 'Facebook', 'instagram.' => 'Instagram',
        'tiktok.' => 'TikTok', 'whatsapp.' => 'WhatsApp', 'linkedin.' => 'LinkedIn', 'lnkd.in' => 'LinkedIn',
        't.co' => 'X (Twitter)', 'x.com' => 'X (Twitter)', 'twitter.' => 'X (Twitter)',
        'reddit.' => 'Reddit', 'pinterest.' => 'Pinterest', 'youtube.' => 'YouTube',
        'chatgpt.' => 'ChatGPT', 'openai.' => 'ChatGPT', 'claude.ai' => 'Claude',
        'perplexity.' => 'Perplexity', 'gemini.google.' => 'Gemini', 'copilot.microsoft.' => 'Copilot',
    ];

    public static function isBot(string $userAgent): bool
    {
        if (strlen($userAgent) < 20) {
            return true;
        }
        return (bool) preg_match(self::BOT_PATTERN, $userAgent);
    }

    public static function device(string $userAgent, int $viewportWidth): string
    {
        if (preg_match('/iPad|Tablet/i', $userAgent)) {
            return 'tablet';
        }
        if (preg_match('/Mobi|Android|iPhone|iPod/i', $userAgent)) {
            return 'phone';
        }
        if ($viewportWidth > 0 && $viewportWidth < 768) {
            return 'phone';
        }
        return 'computer';
    }

    /**
     * Where a visit came from, as a plain label. Null when the referrer is the
     * site itself (an internal click, not a new arrival).
     */
    public static function source(string $referrer, string $utmSource, string $siteHost): ?string
    {
        $utm = strtolower(trim($utmSource));
        if ($utm !== '') {
            foreach (['google' => 'Google', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok',
                      'whatsapp' => 'WhatsApp', 'newsletter' => 'Newsletter', 'email' => 'Email', 'qr' => 'QR code',
                      'flyer' => 'Flyer', 'poster' => 'Poster'] as $needle => $label) {
                if (str_contains($utm, $needle)) {
                    return $label;
                }
            }
            return ucfirst(substr(preg_replace('/[^a-z0-9 _-]/', '', $utm) ?? '', 0, 30)) ?: 'Campaign';
        }

        if (trim($referrer) === '') {
            return 'Direct';
        }

        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        $path = strtolower((string) parse_url($referrer, PHP_URL_PATH));
        $site = strtolower(preg_replace('/^www\./', '', $siteHost) ?? $siteHost);
        $bare = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host === '' || $bare === $site) {
            return null;
        }
        foreach (self::SOURCES as $needle => $label) {
            if (self::hostMatches($host, $needle)) {
                return $label;
            }
        }
        if (preg_match('/(^|\.)google\./', $host)) {
            return (str_starts_with($path, '/maps') || str_starts_with($host, 'maps.')) ? 'Google Maps' : 'Google';
        }
        return $bare;
    }

    /**
     * `facebook.` matches facebook.com, m.facebook.com and facebook.co.uk;
     * `t.co` matches only t.co and its subdomains, never chatgpt.com.
     */
    private static function hostMatches(string $host, string $needle): bool
    {
        if (str_ends_with($needle, '.')) {
            return (bool) preg_match('/(^|\.)' . preg_quote(rtrim($needle, '.'), '/') . '\./', $host);
        }
        return $host === $needle || str_ends_with($host, '.' . $needle);
    }

    /** Keep a path short and safe to use as a key. */
    public static function cleanPath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = preg_replace('#[^a-z0-9/_.~-]#i', '', $path) ?? '/';
        $path = '/' . trim($path, '/');
        return substr($path, 0, 120);
    }

    /** Keep a section key or event name short and safe. */
    public static function cleanKey(string $key, int $max = 40): string
    {
        return substr(preg_replace('/[^a-z0-9_.-]/i', '', $key) ?? '', 0, $max);
    }

    /** A human label, stripped of markup and trimmed. */
    public static function cleanLabel(string $label, int $max = 60): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', strip_tags($label)) ?? '');
        return mb_substr($label, 0, $max);
    }
}
