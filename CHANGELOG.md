# v1.0.1
## 10/21/2026

1. [](#improved)
    * The admin page's wording is translated through `languages.yaml`, like the dashboard panel's, and both follow the admin user's own language
    * README: how hits are written on busy sites, with measured timings, and how `data-insights-section` gives finer tap maps on themes that use one big section
    * README: how to use Grav's proxy settings for the visitor IP
    * Admin2 is now listed as a dependency, so updating installs it if it isn't already installed
2. [](#bugfix)
    * The Visitor insights permission (`api.insights.read`) now shows in Admin2, so it can be given to users and groups. Thanks @rhukster [#4364](https://github.com/getgrav/grav/issues/4364)
    * One IP address (one /64 network for IPv6) can add at most 20 new visitors a day, so changing the browser's user agent no longer creates new visitors. The IP is only kept as a salted hash in that day's file. Thanks @rhukster [#4364](https://github.com/getgrav/grav/issues/4364)
    * A day's visitor list holds at most 10,000 entries, so a flood can't make every hit rewrite a huge file
    * A day lists at most 50 tap names, with the rest counted as Other, and a page keeps at most 60 sections per device
    * The visitor IP follows Grav's forwarded-header settings (`system.http_x_forwarded`) behind a proxy. The Cloudflare setting still overrides it, and its help now says the header can be faked unless the server only accepts traffic from Cloudflare
    * The admin page shows an error, not blank panes, when a page's details can't be loaded
    * The admin page and dashboard panel keep working when left open past the hour the admin's token lasts (Admin2 2.1.35 or later)
    * The minimum Grav version is now 2.1.5, which the API plugin it depends on already needed

# v1.0.0
## 10/18/2026

1. [](#new)
    * Cookie-free visitor and page view counts with a daily salt
    * Sources, including AI assistants, Google Maps and `utm_source` campaigns
    * Phone, tablet and computer split
    * Taps on call, message, email, WhatsApp, directions, social and download links, plus `data-insights`
    * Scroll depth per section and a tap heatmap over a live page preview
    * Admin2 dashboard widget, sidebar item and page, behind the `api.insights.read` permission
    * Bot filtering: engagement check, user agent list, `navigator.webdriver`, Cloudflare bot score and a daily view cap
    * Only real, routable, published pages are counted; made-up paths sent to the hit route are dropped
    * A daily cap on taps and scroll reports per visitor (`daily_action_cap`), separate from the view cap
    * A day lists at most 50 sources and 500 pages; anything beyond that is counted as Other
    * One salt per day even when two requests arrive together at midnight, so nobody is counted twice
    * Grav 2.0 compatibility flag for the plugin listing
    * Honours Global Privacy Control (and optionally Do Not Track), with an owner opt-out
