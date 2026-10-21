<?php

declare(strict_types=1);

namespace Grav\Plugin\VisitorInsights;

/**
 * Cookie-free visit store.
 *
 * Nothing is kept on the visitor's device and no IP address is stored. A
 * visitor is recognised for one day only, by a hash of their IP address and
 * browser mixed with a random salt that is replaced every day and then deleted.
 * "Visitors" therefore means "different people today", the method Plausible
 * and Fathom use.
 *
 * Everything is kept in small JSON files, one per month, under the data folder.
 * No database, and no Grav dependency, so the class can be tested on its own.
 */
final class Store
{
    public const GRID = 20;              // tap map cells per side (5% steps)
    public const MAX_TAPS_PER_SESSION = 30;
    public const MAX_SECTIONS = 30;
    public const MAX_SOURCES_PER_DAY = 50;   // names a day's source list can hold, including Other
    public const MAX_PAGES_PER_DAY = 500;    // names a day's page list can hold, including Other
    public const MAX_EVENTS_PER_DAY = 50;    // tap names a day's list can hold, including Other
    public const MAX_SECTION_KEYS = 60;      // section keys one page and device can hold
    public const MAX_VISITORS_PER_IP = 20;   // new visitors one IP address (one /64 for IPv6) can add per day
    public const MAX_VISITOR_ENTRIES = 10000; // entries a day's visitor list can hold, visitors and per-IP counts together
    public const OTHER = 'Other';            // where sources, pages and tap names past those limits are counted

    private string $dir;
    private int $dailyCap;
    private int $actionCap;
    private int $retentionMonths;
    /** @var \Closure():\DateTimeImmutable */
    private \Closure $clock;

    public function __construct(string $dataDir, int $dailyCap = 50, int $retentionMonths = 13, ?\Closure $clock = null, int $actionCap = 100)
    {
        $this->dir = rtrim($dataDir, '/');
        $this->dailyCap = max(1, $dailyCap);
        $this->actionCap = max(1, $actionCap);
        $this->retentionMonths = max(1, $retentionMonths);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    // ------------------------------------------------------------------ writing

    /** Count an engaged page view. Returns false when it was not counted. */
    public function recordView(string $path, string $referrer, string $utm, int $width, string $ip, string $ua, string $siteHost): bool
    {
        if (Classifier::isBot($ua)) {
            return false;
        }
        [$isNew, $views] = $this->touchVisitor($ip, $ua, 'v');
        if ($views < 1 || $views > $this->dailyCap) {
            return false;
        }
        $path = Classifier::cleanPath($path);
        $device = Classifier::device($ua, $width);
        $source = $isNew ? Classifier::source($referrer, $utm, $siteHost) : null;

        $this->updateMonth(function (array $data) use ($path, $device, $source, $isNew): array {
            $day = &$data['days'][$this->today()];
            $day ??= self::emptyDay();
            $day['views']++;
            $pageKey = self::nameWithRoom($day['pages'] ?? [], $path, self::MAX_PAGES_PER_DAY);
            $day['pages'][$pageKey] = ($day['pages'][$pageKey] ?? 0) + 1;
            if ($isNew) {
                $day['visitors']++;
                $day['devices'][$device] = ($day['devices'][$device] ?? 0) + 1;
                if ($source !== null) {
                    $sourceKey = self::nameWithRoom($day['sources'] ?? [], $source, self::MAX_SOURCES_PER_DAY);
                    $day['sources'][$sourceKey] = ($day['sources'][$sourceKey] ?? 0) + 1;
                }
            }
            return $data;
        });
        return true;
    }

    /** Count a tap on something that matters (call, email, directions, ...). Only for visitors already counted today. */
    public function recordEvent(string $event, string $ip, string $ua): bool
    {
        $event = Classifier::cleanKey($event, 30);
        if ($event === '' || Classifier::isBot($ua) || !$this->allowAction($ip, $ua)) {
            return false;
        }
        $this->updateMonth(function (array $data) use ($event): array {
            $day = &$data['days'][$this->today()];
            $day ??= self::emptyDay();
            $eventKey = self::nameWithRoom($day['events'] ?? [], $event, self::MAX_EVENTS_PER_DAY);
            $day['events'][$eventKey] = ($day['events'][$eventKey] ?? 0) + 1;
            return $data;
        });
        return true;
    }

    /**
     * Record how far one visit scrolled and where it tapped.
     *
     * @param array<int, array{key?:string,label?:string}> $sections  sections on the page, top to bottom
     * @param string[] $reached                                        keys of sections the visitor saw
     * @param array<int, array{0:string,1:float|int,2:float|int}> $taps [section key, x 0-1, y 0-1]
     */
    public function recordSession(string $path, string $device, int $maxDepth, array $sections, array $reached, array $taps, string $ip, string $ua): bool
    {
        if (Classifier::isBot($ua) || !$this->allowAction($ip, $ua)) {
            return false;
        }
        $path = Classifier::cleanPath($path);
        $device = in_array($device, ['phone', 'tablet', 'computer'], true) ? $device : 'computer';
        $bucket = (string) (int) (floor(max(0, min(100, $maxDepth)) / 10) * 10);

        $order = [];
        foreach (array_slice($sections, 0, self::MAX_SECTIONS) as $s) {
            $key = Classifier::cleanKey((string) ($s['key'] ?? ''));
            if ($key !== '') {
                $order[$key] = Classifier::cleanLabel((string) ($s['label'] ?? $key)) ?: $key;
            }
        }
        $reached = array_values(array_intersect(array_map(static fn ($k) => Classifier::cleanKey((string) $k), $reached), array_keys($order)));

        $cells = [];
        foreach (array_slice($taps, 0, self::MAX_TAPS_PER_SESSION) as $t) {
            if (!is_array($t) || count($t) < 3) {
                continue;
            }
            $key = Classifier::cleanKey((string) $t[0]);
            if (!isset($order[$key])) {
                continue;
            }
            $gx = (int) min(self::GRID - 1, max(0, floor((float) $t[1] * self::GRID)));
            $gy = (int) min(self::GRID - 1, max(0, floor((float) $t[2] * self::GRID)));
            $cells[] = [$key, "$gx,$gy"];
        }

        return $this->updateMonth(function (array $data) use ($path, $device, $bucket, $order, $reached, $cells): ?array {
            // A month holds scroll and tap data for a limited number of pages. Once it is full, only pages already in it are updated.
            if (!isset($data['pages'][$path]) && count($data['pages'] ?? []) >= self::MAX_PAGES_PER_DAY) {
                return null;
            }
            if ($order) {
                $data['sections'][$path] = $order;
            }
            $p = &$data['pages'][$path][$device];
            $p ??= ['sessions' => 0, 'depth' => [], 'reached' => [], 'taps' => [], 'taps_total' => 0];
            $p['sessions']++;
            $p['depth'][$bucket] = ($p['depth'][$bucket] ?? 0) + 1;

            // Keys already stored keep counting; new ones only while the page and device have room.
            $known = array_flip(array_merge(array_keys($p['reached']), array_keys($p['taps'])));
            $hasRoom = static function (string $key) use (&$known): bool {
                if (!isset($known[$key])) {
                    if (count($known) >= self::MAX_SECTION_KEYS) {
                        return false;
                    }
                    $known[$key] = true;
                }
                return true;
            };
            foreach ($reached as $key) {
                if ($hasRoom($key)) {
                    $p['reached'][$key] = ($p['reached'][$key] ?? 0) + 1;
                }
            }
            foreach ($cells as [$key, $cell]) {
                if ($hasRoom($key)) {
                    $p['taps'][$key][$cell] = ($p['taps'][$key][$cell] ?? 0) + 1;
                    $p['taps_total']++;
                }
            }
            return $data;
        });
    }

    // ------------------------------------------------------------------ reading

    /** Everything the dashboard panel shows. */
    public function summary(): array
    {
        $now = ($this->clock)();
        $thisMonth = $now->format('Y-m');
        $lastMonthDate = $now->modify('first day of last month');
        $lastMonth = $lastMonthDate->format('Y-m');
        $days = ($this->readMonth($lastMonth)['days'] ?? []) + ($this->readMonth($thisMonth)['days'] ?? []);

        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = $now->modify("-{$i} days")->format('Y-m-d');
            $series[] = ['date' => $date, 'visitors' => (int) ($days[$date]['visitors'] ?? 0), 'views' => (int) ($days[$date]['views'] ?? 0)];
        }

        return [
            'month' => $thisMonth,
            'day_of_month' => (int) $now->format('j'),
            'this_month' => self::sumDays($days, $thisMonth),
            'last_month' => self::sumDays($days, $lastMonth),
            'last_30_days' => $series,
        ];
    }

    /** Months that have data, newest first. */
    public function months(): array
    {
        $months = [];
        foreach (glob($this->dir . '/insights-*.json') ?: [] as $file) {
            if (preg_match('/insights-(\d{4}-\d{2})\.json$/', $file, $m)) {
                $months[] = $m[1];
            }
        }
        rsort($months);
        return $months;
    }

    /** Pages with scroll or tap data in a month, busiest first. */
    public function pages(string $month): array
    {
        $data = $this->readMonth($month);
        $out = [];
        foreach ($data['pages'] ?? [] as $path => $devices) {
            $sessions = 0;
            foreach ($devices as $d) {
                $sessions += (int) ($d['sessions'] ?? 0);
            }
            $out[] = ['path' => $path, 'sessions' => $sessions];
        }
        usort($out, static fn ($a, $b) => $b['sessions'] <=> $a['sessions']);
        return $out;
    }

    /** Scroll depth and tap map for one page and device in one month. */
    public function pageDetail(string $month, string $path, string $device): array
    {
        $data = $this->readMonth($month);
        $path = Classifier::cleanPath($path);
        $p = $data['pages'][$path][$device] ?? ['sessions' => 0, 'depth' => [], 'reached' => [], 'taps' => [], 'taps_total' => 0];
        $sessions = (int) $p['sessions'];

        // "at least X% of the page" for each 10% step
        $cumulative = [];
        $running = 0;
        for ($b = 100; $b >= 0; $b -= 10) {
            $running += (int) ($p['depth'][(string) $b] ?? 0);
            $cumulative[$b] = $sessions ? (int) round($running / $sessions * 100) : 0;
        }
        ksort($cumulative);

        $sections = [];
        foreach ($data['sections'][$path] ?? [] as $key => $label) {
            $n = (int) ($p['reached'][$key] ?? 0);
            $sections[] = ['key' => $key, 'label' => $label, 'reached' => $n, 'percent' => $sessions ? (int) round($n / $sessions * 100) : 0];
        }

        return [
            'month' => $month,
            'path' => $path,
            'device' => $device,
            'sessions' => $sessions,
            'depth' => $cumulative,
            'sections' => $sections,
            'taps' => $p['taps'],
            'taps_total' => (int) $p['taps_total'],
            'grid' => self::GRID,
        ];
    }

    // ------------------------------------------------------------------ internals

    private function today(): string
    {
        return ($this->clock)()->format('Y-m-d');
    }

    private static function emptyDay(): array
    {
        return ['views' => 0, 'visitors' => 0, 'devices' => [], 'sources' => [], 'pages' => [], 'events' => []];
    }

    /**
     * The name to count $name under in a day's list of sources or pages.
     *
     * A name already in the list keeps its own entry. A new name gets one only
     * while the list has room for it and still for Other, so the list never
     * grows past $max entries. Everything else is counted as Other.
     */
    private static function nameWithRoom(array $list, string $name, int $max): string
    {
        if ($name === self::OTHER || isset($list[$name])) {
            return $name;
        }
        $reservedForOther = isset($list[self::OTHER]) ? 0 : 1;
        return count($list) + $reservedForOther < $max ? $name : self::OTHER;
    }

    private static function sumDays(array $days, string $prefix): array
    {
        $t = ['views' => 0, 'visitors' => 0, 'devices' => [], 'sources' => [], 'pages' => [], 'events' => []];
        foreach ($days as $date => $d) {
            if (!str_starts_with((string) $date, $prefix)) {
                continue;
            }
            $t['views'] += (int) ($d['views'] ?? 0);
            $t['visitors'] += (int) ($d['visitors'] ?? 0);
            foreach (['devices', 'sources', 'pages', 'events'] as $k) {
                foreach ((array) ($d[$k] ?? []) as $name => $n) {
                    $t[$k][$name] = ($t[$k][$name] ?? 0) + (int) $n;
                }
            }
        }
        foreach (['sources', 'pages', 'events'] as $k) {
            arsort($t[$k]);
        }
        $t['pages'] = array_slice($t['pages'], 0, 10, true);
        return $t;
    }

    private function monthFile(string $month): string
    {
        return $this->dir . '/insights-' . $month . '.json';
    }

    private function readMonth(string $month): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [];
        }
        $file = $this->monthFile($month);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Change this month's file under an exclusive lock. $mutate gets the
     * decoded data and returns the data to write, or null to leave the file
     * alone. Returns true when the file was written.
     *
     * @param callable(array):?array $mutate
     */
    private function updateMonth(callable $mutate): bool
    {
        $month = ($this->clock)()->format('Y-m');
        $file = $this->monthFile($month);
        $isNew = !is_file($file);
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return false;
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $data = $raw ? (json_decode($raw, true) ?: []) : [];
            $data = $mutate($data);
            if ($data === null) {
                flock($fp, LOCK_UN);
                return false;
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
        if ($isNew) {
            $this->prune();
        }
        return true;
    }

    /** Delete months older than the retention period. */
    private function prune(): void
    {
        $oldest = ($this->clock)()->modify('first day of this month')->modify('-' . ($this->retentionMonths - 1) . ' months')->format('Y-m');
        foreach ($this->months() as $month) {
            if ($month < $oldest) {
                @unlink($this->monthFile($month));
            }
        }
    }

    /**
     * Note one more view ('v') or action ('a', a tap or a scroll report) for
     * this visitor today, under the visitor list's lock.
     *
     * With $knownOnly, a visitor who is not on today's list is left off it and
     * nothing is counted.
     *
     * A new visitor is only added while their IP address (their /64 network for
     * IPv6) has added fewer than MAX_VISITORS_PER_IP visitors today and the list
     * has room. The per-IP count is kept under "i:" and a salted hash of the
     * address, never the address itself, and goes with the day's list.
     *
     * @return array{0:bool,1:int} [first time seen today, count of $kind today including this one].
     *                             [false, 0] when nothing was counted.
     */
    private function touchVisitor(string $ip, string $ua, string $kind, bool $knownOnly = false): array
    {
        $salt = $this->salt();
        $hash = self::visitorHash($salt, $ip, $ua);
        $file = $this->dir . '/visitors-' . $this->today() . '.json';
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return [false, 0];
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $seen = $raw ? (json_decode($raw, true) ?: []) : [];
            $isNew = !isset($seen[$hash]);
            if ($isNew && $knownOnly) {
                flock($fp, LOCK_UN);
                return [false, 0];
            }
            if ($isNew) {
                $ipKey = 'i:' . self::ipHash($salt, $ip);
                $ipCount = (int) ($seen[$ipKey] ?? 0);
                $added = isset($seen[$ipKey]) ? 1 : 2;
                if ($ipCount >= self::MAX_VISITORS_PER_IP || count($seen) + $added > self::MAX_VISITOR_ENTRIES) {
                    flock($fp, LOCK_UN);
                    return [false, 0];
                }
                $seen[$ipKey] = $ipCount + 1;
            }
            $entry = self::visitorEntry($seen[$hash] ?? null);
            $entry[$kind]++;
            $seen[$hash] = $entry;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($seen));
            fflush($fp);
            flock($fp, LOCK_UN);
            return [$isNew, $entry[$kind]];
        } finally {
            fclose($fp);
        }
    }

    /**
     * One visitor's counts for today: 'v' views and 'a' actions. A plain
     * number is the 1.0.0 format, which only counted views.
     *
     * @return array{v:int,a:int}
     */
    private static function visitorEntry(mixed $raw): array
    {
        if (is_array($raw)) {
            return ['v' => (int) ($raw['v'] ?? 0), 'a' => (int) ($raw['a'] ?? 0)];
        }
        return ['v' => (int) $raw, 'a' => 0];
    }

    /** May this visitor add a tap or a scroll report? Only once counted today, and only up to the daily action cap. */
    private function allowAction(string $ip, string $ua): bool
    {
        [, $actions] = $this->touchVisitor($ip, $ua, 'a', true);
        return $actions > 0 && $actions <= $this->actionCap;
    }

    private static function visitorHash(string $salt, string $ip, string $ua): string
    {
        return substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 20);
    }

    private static function ipHash(string $salt, string $ip): string
    {
        return substr(hash('sha256', $salt . '|ip|' . self::ipNetwork($ip)), 0, 20);
    }

    /**
     * The address the per-IP limit counts by: an IPv4 address as it is, an
     * IPv6 address by its /64 network (one home or phone usually has a whole
     * /64), and an IPv4-mapped IPv6 address as the IPv4 address.
     */
    private static function ipNetwork(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }
        return bin2hex(substr($packed, 0, 8)) . '/64';
    }

    /**
     * Today's salt. The first request of the day creates the salt file; mode
     * 'x' makes sure only one request can, so two requests arriving together
     * at midnight share one salt and count a visitor once. The winner also
     * deletes earlier days' salt and visitor lists. Everyone else reads the
     * winner's salt, waiting briefly if the file exists but is still empty.
     *
     * When the salt cannot be read at all (the folder may not be writable) a
     * one-off random salt is used, never an empty one, so the hash still cannot
     * be linked to anyone.
     */
    private function salt(): string
    {
        $today = $this->today();
        $file = $this->dir . '/salt-' . $today . '.txt';

        $fp = @fopen($file, 'x');
        if ($fp) {
            $salt = bin2hex(random_bytes(16));
            try {
                flock($fp, LOCK_EX);
                fwrite($fp, $salt);
                fflush($fp);
                flock($fp, LOCK_UN);
            } finally {
                fclose($fp);
            }
            $this->forgetEarlierDays($today);
            return $salt;
        }

        for ($attempt = 0; $attempt < 20; $attempt++) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                break;
            }
            $fp = @fopen($file, 'r');
            if ($fp) {
                try {
                    flock($fp, LOCK_SH);
                    $salt = trim((string) stream_get_contents($fp));
                    flock($fp, LOCK_UN);
                } finally {
                    fclose($fp);
                }
                if ($salt !== '') {
                    return $salt;
                }
            }
            usleep(5000);
        }

        return bin2hex(random_bytes(16));
    }

    /** A new day: delete earlier salts and visitor lists so nobody can be followed across days. */
    private function forgetEarlierDays(string $today): void
    {
        foreach (array_merge(glob($this->dir . '/salt-*.txt') ?: [], glob($this->dir . '/visitors-*.json') ?: []) as $old) {
            if (!str_contains($old, $today)) {
                @unlink($old);
            }
        }
    }
}
