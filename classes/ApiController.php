<?php

declare(strict_types=1);

namespace Grav\Plugin\VisitorInsights;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Read-only endpoints behind the dashboard panel and the Visitor Insights page.
 * Every endpoint needs the `api.insights.read` permission (super admins pass).
 */
final class ApiController extends AbstractApiController
{
    public const PERMISSION = 'api.insights.read';

    private function store(): Store
    {
        $config = $this->grav['config'];
        return new Store(
            (string) $this->grav['locator']->findResource('user-data://visitor-insights', true, true),
            (int) $config->get('plugins.visitor-insights.daily_view_cap', 50),
            (int) $config->get('plugins.visitor-insights.retention_months', 13)
        );
    }

    /** GET /insights/summary */
    public function summary(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        return ApiResponse::create($this->store()->summary() + ['labels' => $this->labels()]);
    }

    /** GET /insights/pages?month=YYYY-MM */
    public function pages(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        $store = $this->store();
        $months = $store->months();
        $month = (string) ($request->getQueryParams()['month'] ?? ($months[0] ?? gmdate('Y-m')));
        return ApiResponse::create(['months' => $months, 'month' => $month, 'pages' => $store->pages($month), 'labels' => $this->pageLabels()]);
    }

    /** GET /insights/page?month=YYYY-MM&path=/&device=phone */
    public function page(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        $q = $request->getQueryParams();
        $device = in_array($q['device'] ?? '', ['phone', 'tablet', 'computer'], true) ? $q['device'] : 'phone';
        return ApiResponse::create($this->store()->pageDetail((string) ($q['month'] ?? gmdate('Y-m')), (string) ($q['path'] ?? '/'), $device));
    }

    /** GET /insights/widget.js — the dashboard panel */
    public function widgetScript(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        return $this->script(dirname(__DIR__) . '/admin-next/widgets/insights-panel.js');
    }

    private function script(string $file): ResponseInterface
    {
        return new \Grav\Framework\Psr7\Response(200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'private, no-cache',
        ], (string) file_get_contents($file));
    }

    /** Panel wording, translated through languages.yaml. */
    private function labels(): array
    {
        return $this->translate('PANEL', ['TITLE', 'NOTE', 'VISITORS', 'VIEWS', 'TAPS_CALL', 'TAPS_MESSAGE', 'TAPS_DIRECTIONS', 'LAST_MONTH',
            'DAILY', 'SOURCES', 'DEVICES', 'PHONE', 'TABLET', 'COMPUTER', 'EMPTY', 'WAITING', 'TODAY', 'OPEN_PAGE', 'ERROR']);
    }

    /** Admin page wording. Device names are shared with the panel. */
    private function pageLabels(): array
    {
        return $this->translate('PAGE', ['ERROR', 'MONTH', 'PAGE', 'DEVICE', 'SCROLL_TITLE', 'NO_VISITS', 'VISITS_ONE', 'VISITS_MANY',
            'BOTTOM', 'NO_SECTIONS', 'TAP_TITLE', 'COLLECTING_ONE', 'COLLECTING_MANY', 'TAPS_MONTH', 'PREVIEW', 'FEWER', 'MORE'])
            + $this->translate('PANEL', ['PHONE', 'TABLET', 'COMPUTER']);
    }

    private function translate(string $group, array $keys): array
    {
        $language = $this->grav['language'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $language->translate('PLUGIN_VISITOR_INSIGHTS.' . $group . '.' . $k);
        }
        return $out;
    }
}
