=== Bookflow ===
Contributors: dumitrubalaban
Tags: booking, appointments, woocommerce, calendar, scheduling
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
WC requires at least: 8.0
WC tested up to: 10.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise-grade appointment & time-slot booking, built on top of WooCommerce.

== Description ==

Bookflow turns any WordPress + WooCommerce store into a full booking platform — for spas, clinics, tour operators, equipment rentals, and consultancies. It adds a bookable product type with calendars, time slots, resources, per-person pricing, and a complete booking lifecycle, all driven through WooCommerce's existing cart and checkout.

= Features =

* **Bookable product type** — calendar-based scheduling with configurable time slots, layered on top of standard WooCommerce products
* **Resources & capacity** — assign resources (rooms, staff, vehicles, equipment) to products, with automatic capacity/availability enforcement
* **Person types & pricing rules** — adult/child/group pricing, and flexible per-date, per-day-of-week, and seasonal pricing rules
* **Full booking state machine** — pending → confirmed → paid → in-progress → completed, plus cancelled, refunded, and no-show, with enforced valid transitions
* **Google Calendar integration** — real OAuth 2.0 connection; bookings are created, updated, and removed on your Google Calendar automatically, with configurable reminder minutes
* **REST API** — a full server-side REST namespace for building custom front-ends or a floating storefront widget on top of Bookflow
* **iCal export** — a read-only calendar feed as a fallback/alternative to the Google Calendar integration
* **Push notifications** — Telegram-based booking alerts via the built-in PingMe integration
* **Multi-language** — English, Romanian, and Russian out of the box, and translatable to any language via standard WordPress .po/.mo files
* **Developer hooks** — actions and filters at every stage of the booking lifecycle for safe extensibility (see HOOKS.md)
* **Admin tools** — a bookings list table, calendar view, and CSV export from wp-admin

= Build your own booking UI =

Bookflow exposes its booking, availability, and resource data under the `bookflow/v1` REST namespace. Developers can build a fully custom front-end calendar/UI in any framework while Bookflow's backend continues to own all booking logic and validation.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/bookflow`, or install directly through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Create a new product and set its type to **Bookable**.
4. Configure availability, resources, and pricing under the product's Bookflow tab.
5. *(Optional)* Connect Google Calendar under **Bookflow → Google Calendar** for automatic calendar sync with reminders.

== Frequently Asked Questions ==

= Does this require WooCommerce? =

Yes. Bookflow adds a bookable product type on top of WooCommerce's existing product, cart, and checkout system rather than replacing it.

= Can I add my own language? =

Yes. Bookflow uses WordPress's standard translation system (text domain `bookflow`), the same as any WordPress plugin — add a translation with a tool like Loco Translate or Poedit and WordPress will load it automatically for that locale.

= Can I use my own booking calendar UI instead of the built-in widget? =

Yes. Disable the built-in widget with the `bookflow_render_default_widget` filter and build your own interface against the `bookflow/v1` REST API — Bookflow's backend logic stays the single source of truth either way.

= Does Google Calendar sync require a third-party server? =

No. Bookflow connects directly to your own Google account via OAuth; no booking data passes through any third-party service.

= What happens to my bookings if I deactivate the plugin? =

Deactivating keeps all data intact. Uninstalling (deleting) the plugin removes its data — back up first if you plan to reinstall later.

== External services ==

Bookflow connects to the following third-party services, and only ever when you explicitly enable them:

* **Google Calendar API** — if you connect Google Calendar under Bookflow → Google Calendar, booking data (customer name, date/time, and notes) is sent to Google's Calendar API under your own Google account, using OAuth credentials you create and control, so it can create, update, and delete calendar events with reminders on your behalf. This only happens if you complete the connection flow yourself. No data is sent to Bookflow's developer or any server other than Google's. See [Google's Privacy Policy](https://policies.google.com/privacy) and [Terms of Service](https://policies.google.com/terms).
* **Telegram (via the separate PingMe Connect plugin)** — if you also install and configure the unrelated PingMe Connect plugin, Bookflow's booking events become available as a notification channel it can send to your own Telegram account/bot. Bookflow itself never contacts Telegram directly and is fully functional without PingMe Connect installed.

No booking, customer, or site data is ever sent to Bookflow's own developer or any analytics/tracking service.

== Screenshots ==

1. Booking calendar and time-slot selection on the product page.
2. Admin bookings list with status filters and CSV export.
3. Google Calendar settings — connect, choose a calendar, and set a reminder time.

== Changelog ==

= 1.0.0 =
* Initial public release as Bookflow (formerly Daily Booking Box).
* Real Google Calendar OAuth integration with configurable reminders.
* Full rename of code, database tables, and REST namespace to Bookflow branding.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
