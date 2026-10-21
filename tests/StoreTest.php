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

    private function store(int $cap = 50, int $retention = 13, int $actionCap = 100): Store
    {
        return new Store($this->dir, $cap, $retention, fn () => $this->now, $actionCap);
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

    #[Test]
    public function taps_and_scroll_reports_stop_at_the_action_cap(): void
    {
        $s = $this->store(actionCap: 3);
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $this->assertTrue($s->recordEvent('call', '10.0.0.1', self::UA));
        $this->assertTrue($s->recordEvent('call', '10.0.0.1', self::UA));
        $this->assertTrue($s->recordSession('/', 'phone', 50, [['key' => 'a']], ['a'], [], '10.0.0.1', self::UA), 'sessions share the same limit');
        $this->assertFalse($s->recordEvent('call', '10.0.0.1', self::UA), 'fourth action is over the cap');
        $this->assertFalse($s->recordSession('/', 'phone', 50, [['key' => 'a']], ['a'], [], '10.0.0.1', self::UA));

        $this->assertSame(['call' => 2], $s->summary()['this_month']['events']);
        $this->assertSame(1, $s->pageDetail('2026-10', '/', 'phone')['sessions']);
    }

    #[Test]
    public function the_action_cap_is_per_visitor(): void
    {
        $s = $this->store(actionCap: 1);
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $s->recordView('/', '', '', 1440, '10.0.0.2', self::UA2, 'example.com');
        $this->assertTrue($s->recordEvent('call', '10.0.0.1', self::UA));
        $this->assertFalse($s->recordEvent('call', '10.0.0.1', self::UA));
        $this->assertTrue($s->recordEvent('call', '10.0.0.2', self::UA2), 'another visitor has their own allowance');
        $this->assertSame(['call' => 2], $s->summary()['this_month']['events']);
    }

    #[Test]
    public function taps_do_not_use_up_the_view_cap(): void
    {
        $s = $this->store(cap: 2);
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($s->recordEvent('call', '10.0.0.1', self::UA));
        }
        $this->assertTrue($s->recordView('/menu', '', '', 390, '10.0.0.1', self::UA, 'example.com'), 'second view is still within the view cap');
        $this->assertFalse($s->recordView('/menu', '', '', 390, '10.0.0.1', self::UA, 'example.com'));
        $this->assertSame(2, $s->summary()['this_month']['views']);
    }

    #[Test]
    public function sources_past_the_limit_count_as_other(): void
    {
        $s = $this->store();
        $n = Store::MAX_SOURCES_PER_DAY + 10;
        for ($i = 1; $i <= $n; $i++) {
            $s->recordView('/', "https://site{$i}.example/", '', 390, '10.1.0.' . $i, self::UA, 'example.com');
        }

        $m = $s->summary()['this_month'];
        $this->assertSame($n, $m['visitors'], 'every visitor is still counted');
        $this->assertSame($n, $m['views']);
        $this->assertLessThanOrEqual(Store::MAX_SOURCES_PER_DAY, count($m['sources']), 'the list never exceeds the limit');
        $this->assertArrayHasKey(Store::OTHER, $m['sources']);
        $this->assertSame($n, array_sum($m['sources']), 'every visitor lands in some source');
    }

    #[Test]
    public function pages_past_the_limit_count_as_other(): void
    {
        $s = $this->store(cap: 1000);
        $n = Store::MAX_PAGES_PER_DAY + 10;
        for ($i = 1; $i <= $n; $i++) {
            $s->recordView("/page-{$i}", '', '', 390, '10.0.0.1', self::UA, 'example.com');
        }

        $day = json_decode((string) file_get_contents($this->dir . '/insights-2026-10.json'), true)['days']['2026-10-18'];
        $this->assertSame($n, $day['views'], 'every view is still counted');
        $this->assertLessThanOrEqual(Store::MAX_PAGES_PER_DAY, count($day['pages']));
        $this->assertArrayHasKey(Store::OTHER, $day['pages']);
        $this->assertSame($n, array_sum($day['pages']));
    }

    #[Test]
    public function a_source_already_listed_keeps_counting_by_name_after_the_limit(): void
    {
        $s = $this->store();
        $s->recordView('/', 'https://www.google.com/', '', 390, '10.0.0.1', self::UA, 'example.com');
        for ($i = 1; $i <= Store::MAX_SOURCES_PER_DAY + 10; $i++) {
            $s->recordView('/', "https://site{$i}.example/", '', 390, '10.1.0.' . $i, self::UA, 'example.com');
        }
        $s->recordView('/', 'https://www.google.com/', '', 1440, '10.0.0.2', self::UA2, 'example.com');

        $m = $s->summary()['this_month'];
        $this->assertSame(2, $m['sources']['Google'], 'Google was listed before the limit, so it keeps its own count');
        $this->assertLessThanOrEqual(Store::MAX_SOURCES_PER_DAY, count($m['sources']));
    }

    #[Test]
    public function a_visitor_list_in_the_first_release_format_is_still_read(): void
    {
        $s = $this->store(cap: 3);
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $file = $this->dir . '/visitors-2026-10-18.json';
        $hash = $this->visitorHashes()[0];
        // the code before the 1.0.0 tag wrote a plain view count per visitor
        file_put_contents($file, json_encode([$hash => 2]));

        $this->assertTrue($s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com'), 'third view is within the cap');
        $this->assertFalse($s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com'), 'fourth is not');
        $this->assertTrue($s->recordEvent('call', '10.0.0.1', self::UA), 'taps start from zero');
        $this->assertSame(1, $s->summary()['this_month']['visitors'], 'still the same person, not a new one');
        $this->assertSame(['v' => 4, 'a' => 1], json_decode((string) file_get_contents($file), true)[$hash]);
    }

    #[Test]
    public function two_stores_on_the_same_day_share_one_salt_and_count_one_visitor(): void
    {
        $a = $this->store();
        $b = $this->store();
        $this->assertTrue($a->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com'));
        $this->assertTrue($b->recordView('/menu', '', '', 390, '10.0.0.1', self::UA, 'example.com'));

        $this->assertCount(1, glob($this->dir . '/salt-*.txt') ?: []);
        $this->assertCount(1, $this->visitorHashes(), 'one hash for the one person');
        $this->assertSame(1, $b->summary()['this_month']['visitors']);
        $this->assertSame(2, $b->summary()['this_month']['views']);
    }

    #[Test]
    public function changing_the_user_agent_does_not_make_new_visitors_past_the_ip_limit(): void
    {
        $s = $this->store();
        for ($i = 1; $i <= 300; $i++) {
            $s->recordView('/', '', '', 1440, '10.0.0.1', "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.{$i} Safari/537.36", 'example.com');
        }

        $m = $s->summary()['this_month'];
        $this->assertSame(20, $m['visitors'], 'one IP adds at most 20 new visitors a day');
        $this->assertSame(20, $m['views']);
        $this->assertCount(20, $this->visitorHashes(), 'refused visitors are not added to the list');
    }

    #[Test]
    public function an_ipv6_network_shares_one_limit(): void
    {
        $s = $this->store();
        for ($i = 1; $i <= 30; $i++) {
            $s->recordView('/', '', '', 390, sprintf('2001:db8:1:2::%x', $i), self::UA, 'example.com');
        }
        $this->assertSame(20, $s->summary()['this_month']['visitors'], 'addresses in one /64 count together');

        $this->assertTrue($s->recordView('/', '', '', 390, '2001:db8:1:3::1', self::UA, 'example.com'), 'another /64 has its own limit');
        $this->assertTrue($s->recordView('/', '', '', 390, '::ffff:10.0.0.7', self::UA, 'example.com'), 'an IPv4-mapped address counts as IPv4');
        $this->assertSame(22, $s->summary()['this_month']['visitors']);
    }

    #[Test]
    public function no_ip_address_or_network_is_written(): void
    {
        $s = $this->store();
        for ($i = 1; $i <= 25; $i++) {
            $s->recordView('/', '', '', 390, '10.0.0.1', self::UA . " {$i}", 'example.com');
            $s->recordView('/', '', '', 390, sprintf('2001:db8:1:2::%x', $i), self::UA, 'example.com');
        }

        $needles = ['10.0.0.1', '2001:db8:1:2', '20010db800010002', (string) inet_pton('10.0.0.1'), substr((string) inet_pton('2001:db8:1:2::1'), 0, 8)];
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            $content = (string) file_get_contents($f);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $content, basename($f) . ' must not hold an IP address or network');
            }
        }
    }

    #[Test]
    public function visitors_already_counted_carry_on_after_their_ip_reaches_the_limit(): void
    {
        $s = $this->store();
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        for ($i = 1; $i <= 30; $i++) {
            $s->recordView('/', '', '', 390, '10.0.0.1', self::UA . " {$i}", 'example.com');
        }

        $this->assertTrue($s->recordView('/menu', '', '', 390, '10.0.0.1', self::UA, 'example.com'));
        $this->assertTrue($s->recordEvent('call', '10.0.0.1', self::UA));
        $this->assertTrue($s->recordSession('/', 'phone', 50, [['key' => 'a']], ['a'], [], '10.0.0.1', self::UA));
        $this->assertFalse($s->recordEvent('call', '10.0.0.1', self::UA . ' 30'), 'a refused visitor is not on the list, so cannot tap');
        $this->assertSame(20, $s->summary()['this_month']['visitors']);
    }

    #[Test]
    public function a_full_visitor_list_takes_no_new_visitors(): void
    {
        $s = $this->store();
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $file = $this->dir . '/visitors-2026-10-18.json';
        $seen = json_decode((string) file_get_contents($file), true);
        for ($i = count($seen); $i < Store::MAX_VISITOR_ENTRIES; $i++) {
            $seen[sprintf('%020x', $i)] = ['v' => 1, 'a' => 0];
        }
        file_put_contents($file, json_encode($seen));

        $this->assertFalse($s->recordView('/', '', '', 1440, '10.0.0.2', self::UA2, 'example.com'), 'no room for a new visitor');
        $this->assertTrue($s->recordView('/menu', '', '', 390, '10.0.0.1', self::UA, 'example.com'), 'a listed visitor still counts');
        $this->assertCount(Store::MAX_VISITOR_ENTRIES, json_decode((string) file_get_contents($file), true), 'the list does not grow');
        $this->assertSame(1, $s->summary()['this_month']['visitors']);
    }

    #[Test]
    public function event_names_past_the_limit_count_as_other(): void
    {
        $s = $this->store(actionCap: 1000);
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $this->assertTrue($s->recordEvent('call', '10.0.0.1', self::UA));
        for ($i = 1; $i <= 200; $i++) {
            $s->recordEvent("junk-{$i}", '10.0.0.1', self::UA);
        }
        $this->assertTrue($s->recordEvent('call', '10.0.0.1', self::UA));

        $events = $s->summary()['this_month']['events'];
        $this->assertCount(50, $events, 'a day lists at most 50 tap names, including Other');
        $this->assertArrayHasKey(Store::OTHER, $events);
        $this->assertSame(2, $events['call'], 'a name already listed keeps counting by name');
        $this->assertSame(202, array_sum($events), 'every tap lands somewhere');
    }

    #[Test]
    public function section_keys_per_page_and_device_are_limited(): void
    {
        $s = $this->store(actionCap: 1000);
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $s->recordSession('/', 'phone', 50, [['key' => 'hero']], ['hero'], [['hero', 0.5, 0.5]], '10.0.0.1', self::UA);
        for ($batch = 0; $batch < 14; $batch++) {
            $sections = [];
            $taps = [];
            for ($i = 0; $i < 30; $i++) {
                $key = 'junk-' . ($batch * 30 + $i);
                $sections[] = ['key' => $key];
                $taps[] = [$key, 0.5, 0.5];
            }
            $s->recordSession('/', 'phone', 50, $sections, array_column($sections, 'key'), $taps, '10.0.0.1', self::UA);
        }
        $s->recordSession('/', 'phone', 50, [['key' => 'hero']], ['hero'], [['hero', 0.5, 0.5]], '10.0.0.1', self::UA);

        $page = json_decode((string) file_get_contents($this->dir . '/insights-2026-10.json'), true)['pages']['/']['phone'];
        $keys = array_unique(array_merge(array_keys($page['reached']), array_keys($page['taps'])));
        $this->assertLessThanOrEqual(60, count($keys), 'a page and device keep at most 60 section keys');
        $this->assertSame(2, $page['reached']['hero'], 'a key already stored keeps counting');
        $this->assertSame(['10,10' => 2], $page['taps']['hero']);
    }

    #[Test]
    public function a_list_mixing_old_entries_and_ip_counts_is_read(): void
    {
        $s = $this->store(cap: 3);
        $s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com');
        $file = $this->dir . '/visitors-2026-10-18.json';
        $seen = json_decode((string) file_get_contents($file), true);
        $hash = $this->visitorHashes()[0];
        $seen[$hash] = 2;   // the pre-1.0.0 plain view count, next to the new per-IP entry
        file_put_contents($file, json_encode($seen));

        $this->assertTrue($s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com'));
        $this->assertFalse($s->recordView('/', '', '', 390, '10.0.0.1', self::UA, 'example.com'));
        $this->assertTrue($s->recordView('/', '', '', 1440, '10.0.0.1', self::UA2, 'example.com'), 'a second browser on the same IP is within the limit');
        $this->assertSame(2, $s->summary()['this_month']['visitors']);
    }

    /** Keys of today's visitor list that are visitors, not per-IP counts. */
    private function visitorHashes(): array
    {
        $seen = json_decode((string) file_get_contents($this->dir . '/visitors-2026-10-18.json'), true);
        return array_values(array_filter(array_keys($seen), static fn ($k) => !str_starts_with((string) $k, 'i:')));
    }
}
