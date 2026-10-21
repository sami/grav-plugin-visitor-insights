<?php

declare(strict_types=1);

namespace Grav\Plugin\VisitorInsights\Tests;

use Grav\Plugin\VisitorInsights\ClientIp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    #[Test]
    public function grav_ip_is_used_by_default(): void
    {
        $this->assertSame('203.0.113.5', ClientIp::resolve('203.0.113.5', ['REMOTE_ADDR' => '10.0.0.1'], false));
    }

    #[Test]
    public function cloudflare_header_is_ignored_unless_trusted(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'];
        $this->assertSame('10.0.0.1', ClientIp::resolve('10.0.0.1', $server, false));
        $this->assertSame('198.51.100.7', ClientIp::resolve('10.0.0.1', $server, true));
    }

    #[Test]
    public function an_invalid_cloudflare_header_falls_back_to_grav(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip'];
        $this->assertSame('203.0.113.5', ClientIp::resolve('203.0.113.5', $server, true));
        $this->assertSame('203.0.113.5', ClientIp::resolve('203.0.113.5', ['REMOTE_ADDR' => '10.0.0.1'], true), 'header missing');
    }

    #[Test]
    public function remote_addr_is_used_when_grav_cannot_tell(): void
    {
        // Grav 2.0.x reads the address with getenv() only, which PHP's own server and some CGI hosts don't answer.
        $this->assertSame('10.0.0.1', ClientIp::resolve('UNKNOWN', ['REMOTE_ADDR' => '10.0.0.1'], false));
        $this->assertSame('2001:db8::1', ClientIp::resolve('', ['REMOTE_ADDR' => '2001:db8::1'], false));
        $this->assertSame('', ClientIp::resolve('UNKNOWN', [], false));
    }
}
