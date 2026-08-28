# Hooks Reference

Bookflow fires actions and filters at every stage of the booking lifecycle, so you can extend or customize behavior from your theme or a companion plugin without editing Bookflow's code.

## Actions

### Booking lifecycle

| Hook | Fires when | Arguments |
|---|---|---|
| `bookflow_booking_created` | A new booking is inserted | `$data` (array of booking fields), `$booking_id` |
| `bookflow_booking_updated` | An existing booking's fields are updated | `$id`, `$update` (changed fields), `$old` (previous values) |
| `bookflow_booking_status_changed` | A booking transitions to any new status | `$id`, `$old_status`, `$new_status` |
| `bookflow_booking_{$status}` | A booking transitions to a *specific* status (e.g. `bookflow_booking_confirmed`, `bookflow_booking_cancelled`) | `$id`, `$old_status` |
| `bookflow_booking_deleted` | A booking is permanently deleted | `$id`, `$booking` (object, pre-deletion) |
| `bookflow_booking_trashed` | A booking is moved to trash | `$id`, `$booking` |
| `bookflow_booking_restored` | A trashed booking is restored | `$id`, `$booking` |
| `bookflow_bookings_bulk_deleted` | A bulk-delete admin action runs | `$ids`, `$args` |
| `bookflow_bookings_bulk_trashed` | A bulk-trash admin action runs | `$ids`, `$args` |
| `bookflow_booking_creation_failed` | Booking creation from a WooCommerce order item fails | `$order_id`, `$item`, `$booking_id` (`WP_Error`) |
| `bookflow_booking_cancelled_by_customer` | A customer cancels their own booking from My Account | `$booking_id`, `$booking` |
| `bookflow_send_reminder_email` | The cron job decides a reminder email should go out for a booking | `$booking` |

### Google Calendar

| Hook | Fires when | Arguments |
|---|---|---|
| `bookflow_booking_created` / `bookflow_booking_status_changed` / `bookflow_booking_updated` / `bookflow_booking_deleted` / `bookflow_booking_trashed` | Also consumed internally by the Google Calendar integration to create/update/delete calendar events | see above |

## Filters

| Filter | Purpose | Arguments |
|---|---|---|
| `bookflow_is_date_available` | Override whether a given date is bookable for a product | `$available` (bool), `$product_id`, `$date`, `$schedule_id` |
| `bookflow_is_slot_available` | Override whether a specific time slot is bookable | `$available` (bool), `$product_id`, `$date`, `$start_time`, `$resource_id`, `$schedule_id` |
| `bookflow_price_for_slot` | Override the calculated price for a single slot/person type | `$price`, `$product_id`, `$date`, `$start_time`, `$person_type_id` |
| `bookflow_booking_total` | Override the final calculated total for a booking | `$total`, `$product_id`, `$date`, `$start_time`, `$persons_data`, `$resource_id` |
| `bookflow_rate_limit` | Adjust the request rate limit for a given action | `$max`, `$action` |
| `bookflow_locale` | Override the locale Bookflow uses for translations | `$locale` |
| `bookflow_location_map_url` | Override the map URL generated for a location | `$url`, `$lat`, `$lng` |
| `bookflow_email_attachments` | Add attachments to booking emails | `$attachments`, `$booking` |
| `bookflow_email_accent_color` | Change the accent color used in email templates | `$color` |
| `bookflow_email_wrap` | Override the HTML wrapper used around email content | `$content`, `$title` |
| `bookflow_render_default_widget` | Disable Bookflow's built-in front-end widget (e.g. to render your own custom UI instead) | `$render` (bool), `$product` |
| `bookflow_language_selector_id` | Change the DOM id Bookflow looks for as the language selector | `$id` |
| `bookflow_lang_dropdown_id` | Change the DOM id Bookflow looks for as the language dropdown | `$id` |
| `bookflow_spots_element_id` | Change the DOM id Bookflow uses to render "spots left" | `$id` |

## Building a custom front-end

Bookflow also exposes a full REST API under the `bookflow/v1` namespace (bookings, availability, schedules, resources, reservations). Combined with `bookflow_render_default_widget`, you can disable the built-in widget UI entirely and build your own booking calendar/interface in any framework, while Bookflow's PHP backend continues to own all booking logic, validation, and state.
