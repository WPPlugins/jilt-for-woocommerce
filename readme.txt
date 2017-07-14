=== Jilt for WooCommerce ===
Contributors: jilt, skyverge
Tags: woocommerce, abandoned carts, cart abandonment, lost revenue, save abandoned carts
Requires at least: 4.4
Tested up to: 4.7.3
Requires WooCommerce at least: 2.6
Tested WooCommerce up to: 3.0.0
Stable Tag: 1.1.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Recover abandoned carts and lost revenue in your WooCommerce store by connecting it to Jilt, the abandoned cart recovery app.

== Description ==

> **Requires: WooCommerce 2.6** or newer

The [Jilt abandoned cart recovery app](http://jilt.com/) helps your eCommerce store **recover lost revenue** due to cart abandonment. This plugin connects Jilt to [WooCommerce](http://www.woocommerce.com), letting you track when carts are abandoned, then send recovery emails to encourage the customers who abandoned these carts to complete the purchase.

Jilt has already helped merchants recover **over $14,000,000** in lost revenue! You can set up as many campaigns and recovery emails as you'd like, and customize the text and design of every email sent.

= Track Abandoned Carts =

Jilt will track all abandoned carts in your WooCommerce store, capturing email addresses for customers where possible. This lets you see how many customers enter your purchasing flow, but then leave without completing the order.

You can then send recovery emails to these customers to encourage them to complete their purchases, recovering revenue that would otherwise be lost to your store.

= Recover Lost Revenue =

Once Jilt tracks an abandoned cart, you can use your campaigns and recovery emails to save this lost revenue. A **campaign** is a collection of recovery emails that can be sent after the cart is abandoned. You can set up as many emails within a campaign as you'd like (e.g., send 3 recovery emails per abandoned cart).

You can also set up as many campaigns as you want &ndash; create a dedicated series of recovery emails for holidays, sales, or other company events.

= Built for Site Performance =

This plugin sends details on all abandoned carts to the Jilt app rather than storing them in your site's database. This ensures that you can track data over time and get valuable insights into cart abandonment and recovery, while your site **stays speedy** and doesn't get bogged down with tons of abandoned cart data.

Jilt for WooCommerce is built by [SkyVerge](http://skyverge.com/), expert WooCommerce developers who have built over 60 official WooCommerce extensions. Jilt for WooCommerce is great for merchants small and large alike, and is built to scale as large as your store can.

= More Details =

 - Visit [Jilt.com](http://jilt.com/) for more details on Jilt, the abandoned cart recovery app
 - See the [full knowledge base and documentation](http://help.jilt.com/collection/176-jilt-for-woocommerce) for questions and set up help.
 - View more of SkyVerge's [WooCommerce extensions](http://profiles.wordpress.org/skyverge/) on WordPress.org
 - View all [WooCommerce extensions](http://www.skyverge.com/shop/) from SkyVerge

== Installation ==

1. Be sure you're running WooCommerce 2.6 or newer in your shop.

2. To install the plugin, you can do one of the following:

    - (Recommended) Search for "Jilt for WooCommerce" under Plugins &gt; Add New
    - Upload the entire `jilt-for-woocommerce` folder to the `/wp-content/plugins/` directory.
    - Upload the .zip file with the plugin under **Plugins &gt; Add New &gt; Upload**

3. Activate the plugin through the 'Plugins' menu in WordPress

4. Click the "Configure" plugin link or go to **WooCommerce &gt; Settings &gt; Integrations &gt; Jilt** to add your Jilt API keys, connecting your account to WooCommerce.

5. Save your settings!

== Frequently Asked Questions ==

= Do I need anything else to use this plugin? =

Yes, a Jilt account (paid) is required to recover abandoned carts for your WooCommerce store. You can [learn more about Jilt here](http://jilt.com/). You can try Jilt for 14 days for free! Your trial will start as soon as you recover your first abandoned cart.

= When is a cart "abandoned"? =

A cart is considered abandoned if a customer has added items to the cart, but has not checked out or shown any cart activity for at least 15 minutes (ie adding more items). At this point, Jilt starts the timers for your recovery emails.

= Which customers will be emailed? =

Any logged in customer who abandons a cart will receive recovery emails from Jilt. Any guest customer who has entered a full, valid email address in the checkout process will also be sent recovery emails.

== Screenshots ==

1. Get your Jilt Secret Token (click your email &gt; "Edit Account" in the Jilt app)
2. Enter the secret token in the plugin's settings
3. Set up your campaigns in the Jilt app to start recovering lost sales!

== Other Notes ==

**Translators:** the plugin text domain is: `jilt-for-woocommerce`

== Changelog ==

= 2017-04-04 - version 1.1.0 =
 * Feature - Support for the upcoming Jilt dynamic recovery discounts feature
 * Feature - Order fees are now supported
 * Feature - Order customer notes are saved and populated when a customer follows a recovery link
 * Feature - Allow recovery emails to be sent for held orders
 * Tweak - Attempt to recover orders that are abandoned during an off-site payment gateway and automatically cancelled by WooCommerce
 * Tweak - Additional logging level for easier troubleshooting
 * Tweak - x-jilt-shop-domain header is now included in all Jilt API requests
 * Tweak - Better handling of staging/dev migrations
 * Tweak - Removing the configured secret key or deactivating the plugin now signals the Jilt app to pause any active recovery campaigns
 * Tweak - Moved the customer email field to the top of the checkout form in WooCommerce 3.0
 * Misc - Added support for WooCommerce 3.0
 * Misc - Removed support for WooCommerce 2.5

= 2016-12-08 - version 1.0.7 =
 * Tweak - Add compatibility with certain on-site iframe payment gateways, like Amazon Payments Advanced
 * Tweak - Improve how placed orders are updated in Jilt
 * Fix - Fix some errant notices
 * Misc: WordPress 4.7 compatibility

= 2016-11-30 - version 1.0.6 =
 * Tweak - Greatly improve how billing/shipping info is handled, especially when a customer logs in at checkout
 * Fix - Fix an issue where a Jilt order was created with an incorrect total price when first adding an item to the cart
 * Tweak - Improve how API requests to Jilt are sent for improved stability and compatibility with different server environments
 * Misc - For developers: updated public JS API to support setting customer data prior to a visitor starting the checkout process

= 2016-11-07 - version 1.0.5 =
 * Fix - Tweak support links so they properly pre-fill WooCommerce as the platform
 * Misc - Update SkyVerge Plugin Framework to v4.5.0

= 2016-10-14 - version 1.0.4 =
 * Fix - Fix issues with duplicate Jilt orders when recreating carts for previously logged in customers

= 2016-10-13 - version 1.0.3 =
 * Tweak - Improve experience when linking/unlinking shop from Jilt
 * Tweak - Set saved shipping/payment method when recreating a guest's cart
 * Tweak - Set previously applied coupons when recreating a guest's cart

= 2016-07-27 - version 1.0.2 =
 * Misc: WordPress 4.6 compatibility

= 2016-07-12 - version 1.0.1 =
 * Fix - Include line item token in API requests
 * Tweak - Add order note when an order is recovered from a Jilt campaign

= 2016-06-24 - version 1.0.0 =
 * Initial release!
