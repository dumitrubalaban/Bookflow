<p align="center">
  <img src="https://raw.githubusercontent.com/dumitrubalaban/Bookflow/main/assets/banner.jpg" alt="Bookflow" width="720">
</p>

<h1 align="center">Bookflow</h1>

<p align="center">
  Enterprise-grade appointment & time-slot booking, built on top of WooCommerce.
</p>

<p align="center">
  <a href="https://github.com/dumitrubalaban/Bookflow/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-GPLv2%2B-blue.svg" alt="License"></a>
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg" alt="WordPress">
  <img src="https://img.shields.io/badge/WooCommerce-8.0%2B-96588a.svg" alt="WooCommerce">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg" alt="PHP">
  <img src="https://img.shields.io/badge/version-1.0.0-brightgreen.svg" alt="Version">
</p>

---

Bookflow turns any WordPress + WooCommerce store into a full booking platform — for spas, clinics, tour operators, equipment rentals, and consultancies. It adds a bookable product type with calendars, time slots, resources, per-person pricing, and a complete booking lifecycle, all driven through WooCommerce's existing cart and checkout.

## Features

- **Bookable product type** — calendar-based scheduling with configurable time slots, layered on top of standard WooCommerce products
- **Resources & capacity** — assign resources (rooms, staff, vehicles, equipment) to products, with automatic capacity/availability enforcement
- **Person types & pricing rules** — adult/child/group pricing, and flexible per-date, per-day-of-week, and seasonal pricing rules
- **Full booking state machine** — `pending → confirmed → paid → in-progress → completed`, plus `cancelled`, `refunded`, and `no-show`, with enforced valid transitions
- **Google Calendar integration** — real OAuth 2.0 connection; bookings are created, updated, and removed on your Google Calendar automatically, with configurable reminder minutes
- **REST API** — a full server-side REST namespace for building custom front-ends or a floating storefront widget on top of Bookflow
- **iCal export** — a read-only calendar feed as a fallback/alternative to the Google Calendar integration
- **Push notifications** — Telegram-based booking alerts via the built-in PingMe integration
- **Multi-language** — English, Romanian, and Russian out of the box
- **Admin tools** — a bookings list table, calendar view, and CSV export from wp-admin

## Requirements

| | |
|---|---|
| WordPress | 6.0+ |
| WooCommerce | 8.0+ (tested up to 10.6) |
| PHP | 8.0+ |

## Installation

1. Download the latest release (or clone this repo) into `wp-content/plugins/bookflow`
2. Activate **Bookflow** from the Plugins screen
3. Create a new product and set its type to **Bookable**
4. Configure availability, resources, and pricing under the product's Bookflow tab
5. *(Optional)* Connect Google Calendar under **Bookflow → Google Calendar** for automatic calendar sync with reminders

## Google Calendar setup

Bookflow connects to your own Google account via OAuth — no third-party server ever sees your credentials or booking data. Step-by-step instructions (enabling the Calendar API, creating OAuth credentials, and connecting) are shown directly on the **Bookflow → Google Calendar** settings page.

## REST API

Bookflow exposes its booking, availability, and resource data under the `bookflow/v1` REST namespace, so you can build a custom front-end (or use the companion [Bookflow Widget](https://github.com/dumitrubalaban/dbb-widget) for a ready-made floating booking panel) without touching PHP.

## Extending Bookflow

Bookflow fires actions and filters at every stage of the booking lifecycle (created, status changed, price calculated, etc.), so you can hook into it from your own plugin or theme without editing Bookflow's code. See [HOOKS.md](HOOKS.md) for the full reference.

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Bookflow is licensed under the [GPLv2 (or later)](LICENSE), the same license WordPress itself uses.
