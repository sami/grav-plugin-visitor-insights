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
