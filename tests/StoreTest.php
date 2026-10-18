<?php

declare(strict_types=1);

namespace Grav\Plugin\VisitorInsights\Tests;

use Grav\Plugin\VisitorInsights\Store;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
    private const UA2 = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';

    private string $dir;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vi-test-' . bin2hex(random_bytes(4));
        $this->now = new \DateTimeImmutable('2026-10-18 12:00:00', new \DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    private function store(int $cap = 50, int $retention = 13): Store
    {
        return new Store($this->dir, $cap, $retention, fn () => $this->now);
    }

    #[Test]
    public function one_person_counts_once_a_day_but_every_view_counts(): void
    {
        $s = $this->store();
        $s->recordView('/', 'https://www.google.com/', '', 390, '10.0.0.1', self::UA, 'example.com');
        $s->recordView('/menu', 'https://example.com/', '', 390, '10.0.0.1', self::UA, 'example.com');
        $s->recordView('/', '', '', 1440, '10.0.0.2', self::UA2, 'example.com');

        $m = $s->summary()['this_month'];
        $this->assertSame(3, $m['views']);
        $this->assertSame(2, $m['visitors']);
        $this->assertSame(['Google' => 1, 'Direct' => 1], $m['sources']);
        $this->assertSame(['phone' => 1, 'computer' => 1], $m['devices']);
        $this->assertSame(['/' => 2, '/menu' => 1], $m['pages']);
    }

    #[Test]
    public function bots_are_not_counted(): void
    {
        $s = $this->store();
        $this->assertFalse($s->recordView('/', '', '', 0, '10.0.0.9', 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'example.com'));
        $this->assertSame(0, $s->summary()['this_month']['views']);
    }

    #[Test]
    public function the_daily_cap_stops_one_visitor_inflating_views(): void
    {
        $s = $this->store(cap: 3);
        for ($i = 0; $i < 10; $i++) {
            $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        }
        $this->assertSame(3, $s->summary()['this_month']['views']);
    }

    #[Test]
    public function taps_only_count_for_visitors_already_counted(): void
    {
        $s = $this->store();
        $this->assertFalse($s->recordEvent('call', '10.0.0.5', self::UA), 'no view yet');
        $s->recordView('/', '', '', 390, '10.0.0.5', self::UA, 'example.com');
        $this->assertTrue($s->recordEvent('call', '10.0.0.5', self::UA));
        $this->assertTrue($s->recordEvent('directions', '10.0.0.5', self::UA));
        $this->assertSame(['call' => 1, 'directions' => 1], $s->summary()['this_month']['events']);
    }

    #[Test]
    public function the_visitor_list_and_salt_are_replaced_each_day(): void
    {
        $s = $this->store();
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $this->assertFileExists($this->dir . '/visitors-2026-10-18.json');

        $this->now = $this->now->modify('+1 day');
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');

        $this->assertFileDoesNotExist($this->dir . '/visitors-2026-10-18.json');
        $this->assertFileDoesNotExist($this->dir . '/salt-2026-10-18.txt');
        $this->assertSame(2, $s->summary()['this_month']['visitors'], 'the same person is new again the next day');
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            $this->assertStringNotContainsString('10.0.0.1', (string) file_get_contents($f), 'no IP address is ever written');
        }
    }

    #[Test]
    public function scroll_depth_and_taps_are_recorded_per_section(): void
    {
        $s = $this->store();
        $sections = [['key' => 'hero', 'label' => 'Welcome'], ['key' => 'menu', 'label' => 'Our menu'], ['key' => 'find', 'label' => 'Find us']];
        foreach ([['10.0.0.1', 100, ['hero', 'menu', 'find']], ['10.0.0.2', 45, ['hero', 'menu']], ['10.0.0.3', 15, ['hero']]] as [$ip, $depth, $reached]) {
            $s->recordView('/', '', '', 390, $ip, self::UA, 'example.com');
            $s->recordSession('/', 'phone', $depth, $sections, $reached, [['menu', 0.51, 0.22], ['menu', 0.52, 0.24], ['ghost', 0.5, 0.5]], $ip, self::UA);
        }

        $d = $s->pageDetail('2026-10', '/', 'phone');
        $this->assertSame(3, $d['sessions']);
        $this->assertSame([100, 67, 33], array_column($d['sections'], 'percent'));
        $this->assertSame('Our menu', $d['sections'][1]['label']);
        $this->assertEquals(33, $d['depth'][100]);
        $this->assertEquals(100, $d['depth'][0]);
        $this->assertSame(['10,4' => 6], $d['taps']['menu'], 'two nearby taps per visit fall in one 5% cell');
        $this->assertSame(6, $d['taps_total'], 'taps on unknown sections are dropped');
    }

    #[Test]
    public function sessions_from_unknown_visitors_are_ignored(): void
    {
        $s = $this->store();
        $this->assertFalse($s->recordSession('/', 'phone', 50, [['key' => 'a']], ['a'], [], '10.9.9.9', self::UA));
        $this->assertSame(0, $s->pageDetail('2026-10', '/', 'phone')['sessions']);
    }

    #[Test]
    public function tap_coordinates_outside_the_section_are_clamped(): void
    {
        $s = $this->store();
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $s->recordSession('/', 'phone', 50, [['key' => 'a']], ['a'], [['a', -3, 7], ['a', 1, 1]], '10.0.0.1', self::UA);
        $this->assertSame(['0,19' => 1, '19,19' => 1], $s->pageDetail('2026-10', '/', 'phone')['taps']['a']);
    }

    #[Test]
    public function old_months_are_deleted_after_the_retention_period(): void
    {
        $s = $this->store(retention: 2);
        $this->now = new \DateTimeImmutable('2026-07-10', new \DateTimeZone('UTC'));
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $this->now = new \DateTimeImmutable('2026-08-10', new \DateTimeZone('UTC'));
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $this->now = new \DateTimeImmutable('2026-09-10', new \DateTimeZone('UTC'));
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');

        $this->assertSame(['2026-09', '2026-08'], $s->months());
    }

    #[Test]
    public function summary_compares_with_last_month_and_lists_30_days(): void
    {
        $s = $this->store();
        $this->now = new \DateTimeImmutable('2026-09-20 10:00', new \DateTimeZone('UTC'));
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $this->now = new \DateTimeImmutable('2026-10-02 10:00', new \DateTimeZone('UTC'));
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $s->recordView('/', '', '', 1440, '10.0.0.2', self::UA2, 'example.com');

        $sum = $s->summary();
        $this->assertSame(2, $sum['this_month']['visitors']);
        $this->assertSame(1, $sum['last_month']['visitors']);
        $this->assertCount(30, $sum['last_30_days']);
        $this->assertSame('2026-10-02', end($sum['last_30_days'])['date']);
        $this->assertSame(2, end($sum['last_30_days'])['visitors']);
    }
}
