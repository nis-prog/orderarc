=== OrderArc – Order Archive for WooCommerce ===
Contributors: nicolassalinas
Tags: woocommerce, orders, archive, performance, hpos
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move old WooCommerce orders out of the way so the orders screen and order search are fast again. Exact, verified and reversible.

== Description ==

On stores with tens or hundreds of thousands of orders, the WooCommerce orders screen gets slow and order search can stop working altogether (the server cuts the request before it finishes). Most of those orders are years old and nobody opens them, but every search still has to go through them.

OrderArc moves old orders into separate archive tables inside your own database. The orders screen and search only see recent orders, so they are fast again. Archived orders are still there: you can search them, open them and restore them with one click.

**How it moves orders**

* Every row of an order is moved: the order, addresses, items, item meta, notes, order meta, refunds, the legacy `wp_posts` / `wp_postmeta` copy when there is one, and rows that other plugins keep per order (detected automatically, for example invoices or tracking numbers).
* Each batch runs inside one database transaction. Before anything is deleted, OrderArc checks that the copy has the same number of rows and the same checksum of every column as the original. If anything differs, the whole batch is rolled back and those orders are not touched.
* It does not use the WooCommerce order API to move orders, so no emails are sent, stock is not changed and no "order deleted" hooks run.
* The order number stays reserved, so a new order never reuses an archived number.
* WooCommerce Analytics and reports keep counting archived orders (their tables are not moved).
* Download links that customers already received keep working; archived orders do not appear under Downloads in My Account.

**What you get**

* Overview: how your orders are stored (HPOS, legacy, with or without sync), how many orders are ready to archive, and a button that measures how long a real WooCommerce order search takes on your site.
* Preview before archiving, then "Archive now" or a daily automatic run.
* Runs in the background with Action Scheduler (included with WooCommerce), inside a time window you choose (01:00–06:00 by default), and slows down by itself if the server gets slow.
* If the site had no traffic during the night and the run did not start, it can continue slowly during the day or wait for the next night (your choice).
* "Archived orders" tab: search by order number, email, name or phone, open any archived order (read-only) and restore one, several or all.
* An "Archived (N)" link in the WooCommerce orders list, and old links to an archived order open its archived view.
* Customers see their archived orders under "Older orders" in My Account (only orders placed with their own account).
* Works with HPOS (with or without sync) and with legacy order storage.
* Orders that have child orders other than refunds (for example subscriptions), and renewal orders of a subscription, are never archived.
* Personal data requests (Tools → Export Personal Data and Erase Personal Data) also cover archived orders, following the WooCommerce setting for removing personal data from orders.

**What it does not do**

* It does not delete orders. Archived orders stay in your database until you restore them.
* It does not send your data anywhere. Everything happens on your own server.

== Installation ==

1. Install and activate WooCommerce and OrderArc.
2. Go to WooCommerce → OrderArc.
3. Make a backup of your database.
4. In the Archive tab, choose which statuses and how old, click Preview, then Archive now.

== Frequently Asked Questions ==

= Is it safe? =

Each batch is copied, verified (row count and checksum of every column) and only then removed from the live tables, all inside one transaction. If the check fails, nothing changes for those orders. Even so, OrderArc asks you to confirm that you have a recent database backup before the first run, and you should have one.

= Do my reports change? =

No. WooCommerce Analytics tables are not moved, so totals and history stay the same. One exception: if you re-import historical data in Analytics with "Delete previously imported data", WooCommerce rebuilds the reports from live orders only, so archived orders drop out until you restore them.

= Can I get an order back? =

Yes. Open the Archived orders tab, find the order and click Restore. It goes back exactly as it was. Orders you restore by hand are not archived again by the automatic run.

= Which orders can be archived? =

Orders that are no longer in progress: Completed, Cancelled, Refunded, Failed or a final status added by another plugin (Completed, Cancelled and Refunded by default), older than the number of days you choose (minimum 30, 365 by default). Renewal orders of a subscription are never archived.

= What happens if I uninstall the plugin? =

If there are archived orders, their tables are kept, so nothing is lost. Install OrderArc again to see and restore them. If the archive is empty, uninstalling removes everything the plugin created.

= What if I switch between HPOS and legacy storage? =

Restore all archived orders before switching. If you switch anyway, OrderArc shows a notice in its screen.

= Does it work on shared hosting? =

Yes. Work is done in short slices (about 20 seconds each) that fit common PHP time limits, and the batch size adapts to how fast your server answers.

== Screenshots ==

1. Overview: how orders are stored, how many are ready to archive, and how long a real WooCommerce order search takes.
2. Archive tab: choose statuses and age, preview exactly what would move, then archive now or every night.
3. Archived orders: search by number, email, name or phone, and restore one, several or all.
4. An archived order, opened read-only, with a Restore button.
5. The WooCommerce orders list with the "Archived" link.
6. My Account: customers still see their own archived orders under "Older orders".

== Changelog ==

= 0.1.2 =
* Database queries reworked so that only placeholders are added to the SQL. No change in behaviour.
* Translations are now delivered by translate.wordpress.org.

= 0.1.1 =
* Personal data export and erasure requests now include archived orders.
* Renewal orders of a subscription are never archived.
* Stricter detection of tables that other plugins use to store data per order.

= 0.1.0 =
* First release.
