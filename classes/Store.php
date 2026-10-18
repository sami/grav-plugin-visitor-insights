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

    private string $dir;
    private int $dailyCap;
    private int $retentionMonths;
    /** @var \Closure():\DateTimeImmutable */
    private \Closure $clock;

    public function __construct(string $dataDir, int $dailyCap = 50, int $retentionMonths = 13, ?\Closure $clock = null)
    {
        $this->dir = rtrim($dataDir, '/');
        $this->dailyCap = max(1, $dailyCap);
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
        [$isNew, $views] = $this->touchVisitor($ip, $ua, true);
        if ($views > $this->dailyCap) {
            return false;
        }
        $path = Classifier::cleanPath($path);
        $device = Classifier::device($ua, $width);
        $source = $isNew ? Classifier::source($referrer, $utm, $siteHost) : null;

        $this->updateMonth(function (array $data) use ($path, $device, $source, $isNew): array {
            $day = &$data['days'][$this->today()];
            $day ??= self::emptyDay();
            $day['views']++;
            $day['pages'][$path] = ($day['pages'][$path] ?? 0) + 1;
            if ($isNew) {
                $day['visitors']++;
                $day['devices'][$device] = ($day['devices'][$device] ?? 0) + 1;
                if ($source !== null) {
                    $day['sources'][$source] = ($day['sources'][$source] ?? 0) + 1;
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
        if ($event === '' || Classifier::isBot($ua) || !$this->isKnownVisitor($ip, $ua)) {
            return false;
        }
        $this->updateMonth(function (array $data) use ($event): array {
            $day = &$data['days'][$this->today()];
            $day ??= self::emptyDay();
            $day['events'][$event] = ($day['events'][$event] ?? 0) + 1;
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
        if (Classifier::isBot($ua) || !$this->isKnownVisitor($ip, $ua)) {
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

        $this->updateMonth(function (array $data) use ($path, $device, $bucket, $order, $reached, $cells): array {
            if ($order) {
                $data['sections'][$path] = $order;
            }
            $p = &$data['pages'][$path][$device];
            $p ??= ['sessions' => 0, 'depth' => [], 'reached' => [], 'taps' => [], 'taps_total' => 0];
            $p['sessions']++;
            $p['depth'][$bucket] = ($p['depth'][$bucket] ?? 0) + 1;
            foreach ($reached as $key) {
                $p['reached'][$key] = ($p['reached'][$key] ?? 0) + 1;
            }
            foreach ($cells as [$key, $cell]) {
                $p['taps'][$key][$cell] = ($p['taps'][$key][$cell] ?? 0) + 1;
                $p['taps_total']++;
            }
            return $data;
        });
        return true;
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

    private function updateMonth(callable $mutate): void
    {
        $month = ($this->clock)()->format('Y-m');
        $file = $this->monthFile($month);
        $isNew = !is_file($file);
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $data = $raw ? (json_decode($raw, true) ?: []) : [];
            $data = $mutate($data);
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
     * Note one more page view for this visitor today.
     *
     * @return array{0:bool,1:int} [first view today, views today including this one]
     */
    private function touchVisitor(string $ip, string $ua, bool $count): array
    {
        $hash = $this->visitorHash($ip, $ua);
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
            if ($count) {
                $seen[$hash] = ($seen[$hash] ?? 0) + 1;
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, (string) json_encode($seen));
                fflush($fp);
            }
            flock($fp, LOCK_UN);
            return [$isNew, (int) ($seen[$hash] ?? 0)];
        } finally {
            fclose($fp);
        }
    }

    private function isKnownVisitor(string $ip, string $ua): bool
    {
        $file = $this->dir . '/visitors-' . $this->today() . '.json';
        if (!is_file($file)) {
            return false;
        }
        $seen = json_decode((string) file_get_contents($file), true);
        return is_array($seen) && isset($seen[$this->visitorHash($ip, $ua)]);
    }

    private function visitorHash(string $ip, string $ua): string
    {
        $today = $this->today();
        $saltFile = $this->dir . '/salt-' . $today . '.txt';
        if (!is_file($saltFile)) {
            // A new day: delete yesterday's salt and visitor list so nobody can be followed across days.
            foreach (array_merge(glob($this->dir . '/salt-*.txt') ?: [], glob($this->dir . '/visitors-*.json') ?: []) as $old) {
                if (!str_contains($old, $today)) {
                    @unlink($old);
                }
            }
            @file_put_contents($saltFile, bin2hex(random_bytes(16)), LOCK_EX);
        }
        $salt = (string) @file_get_contents($saltFile);
        return substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 20);
    }
}
