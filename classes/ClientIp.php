<?php

declare(strict_types=1);

namespace Grav\Plugin\VisitorInsights;

/**
 * Works out the visitor's IP address for a hit. No Grav dependency, so it can
 * be tested on its own.
 */
final class ClientIp
{
    /**
     * @param string $gravIp           Grav's Uri::ip(), which follows system.http_x_forwarded behind a proxy
     * @param array<string,mixed> $server  $_SERVER
     * @param bool $trustCloudflare    the plugin's Cloudflare setting, which overrides Grav's answer
     */
    public static function resolve(string $gravIp, array $server, bool $trustCloudflare): string
    {
        if ($trustCloudflare) {
            $cloudflare = (string) ($server['HTTP_CF_CONNECTING_IP'] ?? '');
            if (self::isIp($cloudflare)) {
                return $cloudflare;
            }
        }
        if (self::isIp($gravIp)) {
            return $gravIp;
        }
        // Grav 2.0.x reads the address with getenv() only, which PHP's own
        // server and some CGI hosts don't answer, so it says UNKNOWN.
        return (string) ($server['REMOTE_ADDR'] ?? '');
    }

    private static function isIp(string $value): bool
    {
        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}
