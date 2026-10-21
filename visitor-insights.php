<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\VisitorInsights\ApiController;
use Grav\Plugin\VisitorInsights\ClientIp;
use Grav\Plugin\VisitorInsights\Store;
use RocketTheme\Toolbox\Event\Event;

/**
 * Visitor Insights: cookie-free visitor counts, taps, scroll depth and a tap
 * heatmap for Grav, shown on the Admin2 dashboard.
 */
class VisitorInsightsPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [['autoload', 100000], ['onPluginsInitialized', 0]],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
        ];
    }

    /** Grav doesn't read a plugin's permissions.yaml by itself; this puts api.insights.read in Admin2's permission lists. */
    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
        $event->permissions->addActions($actions);
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\VisitorInsights\\';
            if (str_starts_with($class, $prefix)) {
                $file = __DIR__ . '/classes/' . substr($class, strlen($prefix)) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
    }

    public function onPluginsInitialized(): void
    {
        $this->enable([
            'onApiRegisterRoutes'   => ['onApiRegisterRoutes', 0],
            'onApiDashboardWidgets' => ['onApiDashboardWidgets', 0],
            'onApiSidebarItems'     => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo'   => ['onApiPluginPageInfo', 0],
        ]);

        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onPagesInitialized'   => ['onPagesInitialized', 1000],
            'onTwigSiteVariables'  => ['onTwigSiteVariables', 0],
        ]);
    }

    // ------------------------------------------------------------------ front end

    /** Receives hits from the browser script at the configured route. */
    public function onPagesInitialized(): void
    {
        $route = '/' . trim((string) $this->config->get('plugins.visitor-insights.route', '/visitor-insights/hit'), '/');
        if (rtrim($this->grav['uri']->path(), '/') !== $route) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $body = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true);
            if (is_array($body)) {
                $this->record($body);
            }
        }

        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        http_response_code(204);
        exit;
    }

    private function record(array $hit): void
    {
        // Uri::ip() follows Grav's system.http_x_forwarded settings behind a proxy.
        $ip = ClientIp::resolve((string) Uri::ip(), $_SERVER, (bool) $this->config->get('plugins.visitor-insights.trust_cloudflare_ip', false));
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        // Cloudflare Bot Management, when the site has it: drop likely bots.
        $botScore = $_SERVER['HTTP_CF_BOT_SCORE'] ?? null;
        if ($botScore !== null && (int) $botScore < 30) {
            return;
        }

        $kind = (string) ($hit['t'] ?? '');
        // The browser reports its own path, so only count views and sessions for pages that really exist.
        if (in_array($kind, ['view', 'session'], true) && !$this->isRealPage((string) ($hit['p'] ?? '/'))) {
            return;
        }

        $store = $this->store();
        switch ($kind) {
            case 'view':
                $store->recordView(
                    (string) ($hit['p'] ?? '/'),
                    (string) ($hit['r'] ?? ''),
                    (string) ($hit['u'] ?? ''),
                    (int) ($hit['w'] ?? 0),
                    $ip, $ua,
                    (string) $this->grav['uri']->host()
                );
                break;
            case 'event':
                $store->recordEvent((string) ($hit['e'] ?? ''), $ip, $ua);
                break;
            case 'session':
                $store->recordSession(
                    (string) ($hit['p'] ?? '/'),
                    (string) ($hit['d'] ?? 'computer'),
                    (int) ($hit['m'] ?? 0),
                    is_array($hit['s'] ?? null) ? $hit['s'] : [],
                    is_array($hit['v'] ?? null) ? $hit['v'] : [],
                    is_array($hit['x'] ?? null) ? $hit['x'] : [],
                    $ip, $ua
                );
                break;
        }
    }

    /**
     * Does this path lead to a real, routable, published page of the site?
     *
     * Uses Pages::find(), not dispatch(): dispatch() can send a redirect from
     * site.redirects, which must never happen on a background hit. find() still
     * resolves the home page at / and site-wide routes.
     */
    private function isRealPage(string $path): bool
    {
        try {
            $route = (string) (parse_url($path, PHP_URL_PATH) ?: '/');

            // The site may live in a folder, for example /blog/about on example.com/blog.
            $root = rtrim((string) $this->grav['uri']->rootUrl(false), '/');
            if ($root !== '') {
                if ($route === $root) {
                    $route = '/';
                } elseif (str_starts_with($route, $root . '/')) {
                    $route = substr($route, strlen($root));
                }
            }
            $route = '/' . trim($route, '/');

            // A language prefix is not part of the page route: /en/about is the page /about.
            $languages = (array) $this->config->get('system.languages.supported', []);
            if ($languages !== [] && $route !== '/') {
                $segments = explode('/', substr($route, 1), 2);
                if (in_array($segments[0], $languages, true)) {
                    $route = '/' . trim($segments[1] ?? '', '/');
                }
            }

            $page = $this->grav['pages']->find($route);
            return $page !== null && $page->routable() && $page->published();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Adds the small browser script to every front-end page. */
    public function onTwigSiteVariables(): void
    {
        $page = $this->grav['page'] ?? null;
        $route = $page ? (string) $page->route() : '';
        foreach ((array) $this->config->get('plugins.visitor-insights.exclude_routes', []) as $pattern) {
            if ($pattern !== '' && fnmatch((string) $pattern, $route)) {
                return;
            }
        }

        $c = $this->config->get('plugins.visitor-insights');
        $settings = [
            'hit'      => rtrim($this->grav['uri']->rootUrl(false), '/') . '/' . trim((string) ($c['route'] ?? '/visitor-insights/hit'), '/'),
            'engage'   => (int) ($c['count_after_seconds'] ?? 2),
            'gpc'      => (bool) ($c['respect_gpc'] ?? true),
            'dnt'      => (bool) ($c['respect_dnt'] ?? false),
            'heatmaps' => (bool) ($c['heatmaps'] ?? true),
            'sections' => (string) ($c['sections'] ?? 'header, main > section, section, footer, [data-insights-section]'),
            'ignore'   => (string) ($c['ignore_param'] ?? 'insights-ignore'),
        ];

        $assets = $this->grav['assets'];
        $assets->addInlineJs('window.VisitorInsights=' . json_encode($settings, JSON_UNESCAPED_SLASHES) . ';', ['priority' => 100, 'group' => 'head']);
        $assets->addJs('plugin://visitor-insights/assets/insights.js', ['loading' => 'defer', 'priority' => 100, 'group' => 'head']);
    }

    // ------------------------------------------------------------------ admin

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $routes->get('/insights/summary', [ApiController::class, 'summary']);
        $routes->get('/insights/pages', [ApiController::class, 'pages']);
        $routes->get('/insights/page', [ApiController::class, 'page']);
        $routes->get('/insights/widget.js', [ApiController::class, 'widgetScript']);
    }

    public function onApiDashboardWidgets(Event $event): void
    {
        $widgets = $event['widgets'];
        $widgets[] = [
            'id'           => 'visitor-insights.panel',
            'plugin'       => 'visitor-insights',
            'label'        => $this->grav['language']->translate('PLUGIN_VISITOR_INSIGHTS.PANEL.WIDGET_LABEL'),
            'icon'         => 'BarChart3',
            'sizes'        => ['md', 'lg', 'xl'],
            'defaultSize'  => 'xl',
            'priority'     => 200,
            'authorize'    => ApiController::PERMISSION,
            'scriptUrl'    => '/insights/widget.js',
            'dataEndpoint' => '/insights/summary',
        ];
        $event['widgets'] = $widgets;
    }

    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'];
        $items[] = [
            'id'        => 'visitor-insights',
            'plugin'    => 'visitor-insights',
            'label'     => 'PLUGIN_VISITOR_INSIGHTS.SIDEBAR',
            'icon'      => 'fa-chart-simple',
            'route'     => '/plugin/visitor-insights',
            'priority'  => 5,
            'authorize' => ApiController::PERMISSION,
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'visitor-insights') {
            return;
        }
        $event['definition'] = [
            'id'        => 'visitor-insights',
            'plugin'    => 'visitor-insights',
            'title'     => $this->grav['language']->translate('PLUGIN_VISITOR_INSIGHTS.SIDEBAR'),
            'icon'      => 'fa-chart-simple',
            'page_type' => 'component',
        ];
    }

    private function store(): Store
    {
        return new Store(
            (string) $this->grav['locator']->findResource('user-data://visitor-insights', true, true),
            (int) $this->config->get('plugins.visitor-insights.daily_view_cap', 50),
            (int) $this->config->get('plugins.visitor-insights.retention_months', 13),
            null,
            (int) $this->config->get('plugins.visitor-insights.daily_action_cap', 100)
        );
    }
}
