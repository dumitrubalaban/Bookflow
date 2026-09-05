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

### Credits, resource pins, flags, notes, documents

These generic mechanisms let an integrator model "package with a remaining balance", "assigned provider", "eligibility gate", "session notes", and "verified documents" without any driving-school (or other vertical) specifics living in Bookflow itself — see `Bookflow_Credits`, `Bookflow_Resource_Pins`, `Bookflow_Customer_Flags`, `Bookflow_Booking_Notes`, `Bookflow_Customer_Documents`, and `Bookflow_Booking_Resources`.

| Hook | Purpose | Arguments |
|---|---|---|
| `bookflow_credit_consumed` | A credit unit was consumed from a pool | `$credit_id`, `$booking_id`, `$amount` |
| `bookflow_credit_refunded` | Credit(s) for a booking were refunded | `$booking_id` |
| `bookflow_credit_should_refund_on_cancel` (filter) | Decide whether a cancelled booking's consumed credit is refunded or forfeited | `$should_refund` (bool, default true), `$booking_id`, `$booking` |
| `bookflow_credit_granted_from_order` | A `_bookflow_grants_credit_type` product's order completed and credits were granted | `$credit_id`, `$customer_id`, `$order_id`, `$product_id` |
| `bookflow_bookable_resources_for_product` (filter) | Narrow the resource list a customer is offered for a product — this is where `Bookflow_Resource_Pins` restricts the list to a customer's pinned resource(s) | `$resources`, `$product_id` |
| `bookflow_is_product_bookable_for_customer` (filter) | Generic eligibility gate checked in `Bookflow_Booking::create()` before any booking is created | `$bookable` (bool), `$product_id`, `$customer_id` |
| `bookflow_customer_flag_set` | A customer flag was set | `$customer_id`, `$flag_key`, `$flag_value` |
| `bookflow_booking_note_added` | A note (freeform or structured) was attached to a booking | `$note_id`, `$booking_id`, `$args` |
| `bookflow_customer_document_added` / `bookflow_customer_document_status_changed` | A customer document was uploaded / its verification status changed | `$doc_id`, `$customer_id`, `$doc_type` / `$doc_id`, `$status` |
| `bookflow_rest_admin_permission` (filter) | Grant REST API staff-endpoint access to a narrower role than `manage_woocommerce` (e.g. an "instructor" role) | `$allowed` (bool), `$request` |

Two product-level meta keys change core `Bookflow_Booking::create()` / status behavior directly (no hook needed):

| Meta key | Effect |
|---|---|
| `_bookflow_credit_type` | Booking confirmation auto-consumes one credit of this type from the customer's balance; cancellation auto-refunds it (unless `bookflow_credit_should_refund_on_cancel` returns false). A product set up this way can be redeemed directly via `POST /bookings/redeem` (logged-in customer, no WooCommerce order) instead of the normal checkout-based `/reservations` flow |
| `_bookflow_grants_credit_type` + `_bookflow_grants_credit_amount` (+ optional `_bookflow_grants_credit_expires_days`) | On ANY regular (non-booking) product: completing an order containing it grants that many credits of that type to the buyer — this is how a "package"/"course" purchase funds the balance later redeemed against a `_bookflow_credit_type` booking product |
| `_bookflow_max_active_bookings_per_customer` | Caps how many pending/confirmed bookings one customer may hold for this product at once |
| `_bookflow_requires_flag` / `_bookflow_requires_flag_value` | Gates booking behind a customer flag (optionally a specific value) via `Bookflow_Customer_Flags` |
| `_bookflow_requires_manual_approval` | Booking stays `pending` on order completion instead of auto-confirming; a staff role must call the `/bookings/{id}/status` REST endpoint (or `Bookflow_Cart::approve_booking()` / `reject_booking()`) |

### Cancellation attribution, reschedule, per-resource reporting

| Hook | Purpose | Arguments |
|---|---|---|
| `bookflow_booking_rescheduled` | A booking was moved to a new slot via `Bookflow_Booking::reschedule()` | `$old_booking_id`, `$new_booking_id` |

`Bookflow_Booking::transition_status($id, 'cancelled', $note, ['cancelled_by' => 'customer'\|'staff'\|'system'\|'reschedule'])` records who initiated a cancellation on `$booking->cancelled_by`. Use it inside `bookflow_credit_should_refund_on_cancel` (or your own reporting) to key a refund/forfeit policy on who cancelled and how late — e.g. always refund a staff-initiated cancel, only refund a customer cancel outside the cutoff window.

`Bookflow_Booking::reschedule($booking_id, $new_data)` moves a booking to a new date/time/resource atomically: it re-keys any already-consumed credit ledger entry to the new booking id (so reschedule never double-consumes or wrongly refunds a credit), then cancels the original with `cancelled_by = 'reschedule'`.

`Bookflow_Booking::get_revenue_by_resource($args)` (also exposed as `by_resource` in the `/stats` REST response) gives a per-resource revenue/booking-count breakdown — e.g. per-instructor or per-room utilization reporting — the same shape as the existing per-product breakdown.

## Building a custom front-end

Bookflow also exposes a full REST API under the `bookflow/v1` namespace (bookings, availability, schedules, resources, reservations). Combined with `bookflow_render_default_widget`, you can disable the built-in widget UI entirely and build your own booking calendar/interface in any framework, while Bookflow's PHP backend continues to own all booking logic, validation, and state.
