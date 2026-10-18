# Visitor Insights for Grav

Cookie-free visitor numbers for Grav 2, shown on the Admin2 dashboard. Visitor Insights counts visitors and page views, shows where they came from, records taps on the links that matter (call, message, email, WhatsApp, directions, social, downloads), and shows how far down each page people scroll, with a tap heatmap drawn over a live preview of the page.

It sets no cookies, stores nothing on the visitor's device, sends nothing to a third party and never writes an IP address to disk. You don't need a cookie banner for it.

![Dashboard panel](docs/dashboard.png)

![Scroll depth and tap map](docs/insights-page.png)

## Features

- **Visitors and page views** for this month and last month, plus a chart of the last 30 days.
- **Where visitors came from**: search engines, social sites, AI assistants (ChatGPT, Claude, Perplexity, Gemini, Copilot), Google Maps, `utm_source` campaigns (QR codes, flyers, newsletters) and direct visits.
- **Phone, tablet or computer.**
- **Taps that matter**: `tel:`, `sms:`, `mailto:`, WhatsApp, map and directions links, social profiles and file downloads are recognised automatically. Add `data-insights="booking"` to any link or button to track your own.
- **Scroll depth per section**: the share of visits that reached each `header`, `section` and `footer` of a page.
- **Tap heatmap**: taps are stored relative to the section they landed in, so the map lines up with the page even when the layout changes between screen sizes. The admin page shows phone, tablet and computer separately.
- **A dashboard widget and an admin page**, both behind their own permission (`api.insights.read`). You can give clients the numbers without giving them anything else.

## How visitors are counted without cookies

Each visit is identified by a one-way hash of the IP address, the browser's user agent and a random salt. The salt is replaced every day and the old one is deleted, so the same person can't be linked from one day to the next and the hashes can't be reversed. Plausible and Fathom use the same method. A day's list of hashes is deleted when the day ends. Only the totals are kept, in one small JSON file per month.

## Telling people from bots

A view is only counted once the visitor engages with the page: it has been visible for 2 seconds (you can change this), or the visitor scrolls, taps or presses a key. Most bots never do any of that. On top of that:

- known crawlers, monitoring tools, HTTP libraries and headless browsers are filtered out by user agent;
- browsers driven by automation (`navigator.webdriver`) are ignored;
- behind Cloudflare, requests with a low bot score are dropped;
- one visitor can add at most 50 views a day (you can change this);
- taps and scroll data are only accepted from visitors who were already counted.

## Respecting visitors

- Global Privacy Control is honoured by default. Do Not Track can be honoured too.
- Visit any page with `?insights-ignore=1` to stop your own browser being counted, and with `?insights-ignore=0` to undo it.
- Data older than 13 months is deleted automatically (you can change this).

## Speed

The browser script is under 2 KB gzipped, loads with `defer` and sends one small request after engagement and one when the visitor leaves. On the server, recording a hit takes a fraction of a millisecond and doesn't load the page. Page rendering isn't affected.

## Requirements

- Grav 2.0 or later
- The [API plugin](https://github.com/getgrav/grav-plugin-api) 1.0.40 or later, with Admin2
- PHP 8.3 or later

## Installation

With GPM, once the plugin is listed on getgrav.org:

```
bin/gpm install visitor-insights
```

Or by hand: download this repository, unzip it into `user/plugins/visitor-insights`, and clear the cache.

Your theme has to output Grav's assets, which almost every theme does: `{{ assets.js()|raw }}` in the page template, or `{{ assets.js('bottom')|raw }}` if the theme loads scripts at the end of the page.

## Who can see the numbers

Super users always can. To let anyone else see them, give their account or group the **Visitor insights** permission (`api.insights.read`), alongside `api.access`. They then see the dashboard panel and a **Visitor insights** item in the sidebar.

## Configuration

Settings are in Admin2 under Plugins → Visitor Insights, or in `user/config/plugins/visitor-insights.yaml`:

```yaml
enabled: true
route: /visitor-insights/hit        # where the script sends its hits
count_after_seconds: 2              # 0 counts every view straight away
respect_gpc: true
respect_dnt: false
daily_view_cap: 50
heatmaps: true                      # scroll depth and the tap map
sections: 'header, main > section, section, footer, [data-insights-section]'
retention_months: 13
exclude_routes: []                  # for example ['/private/*']
ignore_param: insights-ignore
trust_cloudflare_ip: false          # only when the site is behind Cloudflare
```

## Marking up your pages

None of this is required. The defaults work with most themes.

- `data-insights="name"` on a link or button counts taps on it as `name`.
- `data-insights-section="key"` turns any element into a section for scroll depth and the tap map, with a stable key.
- `data-insights-label="Our menu"` gives a section a readable name. Otherwise its first heading is used.

## Data

Everything is stored in `user/data/visitor-insights/`:

- `insights-YYYY-MM.json` holds a month's totals;
- `visitors-YYYY-MM-DD.json` and `salt-YYYY-MM-DD.txt` exist for the current day only.

Delete the folder to start again.

## Development

```
composer install
vendor/bin/phpunit
```

## Licence

MIT. See [LICENSE](LICENSE).
