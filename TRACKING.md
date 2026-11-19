# Website tracking (GTM / GA4)

The site pushes these events to `window.dataLayer` (Google Tag Manager). Code:
`resources/js/lib/analytics.ts` → `trackEvent()`. `dataLayer` is created in
`resources/views/app.blade.php` before any tag loads, so early events are not lost.

Set `GTM_ID` in `.env` to load the GTM container. Without it, `GA_ID` loads GA4 directly
(gtag.js) and the same events are sent with `gtag('event', …)`. If `META_PIXEL_ID` is set,
lead events are also sent to the Meta Pixel as `Lead`.

## Events

| Event | When it fires | Parameters | GA4 conversion? |
|---|---|---|---|
| `demo_form_submit` | Short demo form saved (book-demo, contact, homepage) | `product` (product name or `general`) | **Yes** |
| `whatsapp_click` | Any WhatsApp button / link is clicked | `location` (`floating`, `header`, `footer`, `mobile_menu`, `book_demo`, `contact`, `home_final_cta`, `quiz_result`, `product_<slug>`, `products_index`, `pricing`, `about`), `product` (floating button only) | **Yes** |
| `chat_lead` | Name + phone saved from the AI chat widget | `product` (product page the chat was on, or `general`) | **Yes** |
| `quiz_complete` | "Find your solution" quiz finished and lead saved | `product` (recommended), `business_type`, `size` | **Yes** |
| `try_demo_click` | A "Try live demo" / "Visit site" / Google Play button | `product` (name), `destination` (URL, `demo_login_request`, `google_play`, `product_grid`) | Optional (micro-conversion) |
| `exit_offer_submit` | Phone number saved from the exit-intent popup | `product` (page product or `general`) | **Yes** |
| `book_demo_click` | A "Book a Free Demo" / "Book Demo" button | `product` (slug or `general`) | No (funnel step) |
| `notify_me_submit` | Notify-me form on a coming-soon product | `product` | Optional |

Floating WhatsApp clicks are also stored on the server (`lead_events` table) and counted on
the admin Leads page.

## GTM setup (once)

1. **Variables** → New → *Data Layer Variable* for each parameter you want in GA4:
   `product`, `location`, `destination`, `business_type`, `size`
   (name them e.g. `DLV - product`).
2. **Triggers** → New → *Custom Event* for each event name above
   (e.g. trigger `CE - demo_form_submit`, event name `demo_form_submit`).
   One regex trigger also works: event name
   `demo_form_submit|whatsapp_click|chat_lead|quiz_complete|try_demo_click|exit_offer_submit|book_demo_click|notify_me_submit`,
   "Use regex matching" ticked.
3. **Tags** → New → *Google Analytics: GA4 Event*:
   - Measurement ID: your GA4 ID (`G-…`)
   - Event name: `{{Event}}` (built-in variable) when using the regex trigger, or the fixed name
   - Event parameters: `product` = `{{DLV - product}}`, `location` = `{{DLV - location}}`, …
   - Trigger: the custom event trigger(s) from step 2
4. Make sure a *Google tag* (GA4 configuration) fires on *All Pages*.
5. **Preview** in GTM, submit a test form on the site, check the events in GA4 *DebugView*,
   then **Submit / Publish** the container.

## GA4 conversions (key events)

In GA4 → Admin → Events (after the events have been received once), mark as key events:

- `demo_form_submit`
- `whatsapp_click`
- `chat_lead`
- `quiz_complete`
- `exit_offer_submit`

Optional: `try_demo_click` (intent to try a product). Use the `product` parameter (register it
as a custom dimension in GA4 → Admin → Custom definitions) to report conversions per product.

## Google Ads / Meta

- Google Ads: import the GA4 key events above, or add *Google Ads Conversion Tracking* tags in
  GTM on the same custom-event triggers.
- Meta Pixel: lead events are sent as standard `Lead` events automatically when
  `META_PIXEL_ID` is set; other events are sent as custom events.
