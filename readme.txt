=== Bolt Checkout for WooCommerce ===
Contributors: boltpay
Tags: bolt, pay, payment, checkout, woocommerce, ecommerce, e-commerce, commerce, woothemes, wordpress ecommerce, store, sales, sell, shop, shopping, cart, configurable
Requires at least: 5.0
Tested up to: 6.4.2
Stable tag: 2.20.0
Requires PHP: 7.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Bring the world's fastest checkout to your WooCommerce site

== Description == 

Bolt is the ultimate checkout solution for WooCommerce sellers who want to boost their sales and provide the security & speed customers expect. With Bolt, deliver a better-than-Amazon checkout experience on your WooCommerce site, with zero fraud built in.

= WHY WOOCOMMERCE SELLERS LOVE BOLT: =

* Lift conversion – Bolt drives 10-20% more completed orders.
* Boost customer LTV – Single-click checkout for seamless repeat purchases.
* Zero fraud guarantee – Approve more good customers with Bolt’s precision fraud engine. Bolt’s fraud decisioning comes with complete chargeback coverage.
* Eliminate costs — No more chargebacks, third-party fraud tools, or manual review. Bolt gives you freedom to focus on your business.
* Made for Mobile – Bolt is built to convert, no matter the device. Bolt improves mobile conversion rates by 83%.\*						
												
= FEATURES = 
* Single-click checkout that plugs directly into your website for seamless desktop and mobile commerce.
* Hassle-free integration that ranges from a couple of days to a week.
* Dedicated account management
* 100% coverage of fraudulent chargebacks including full international risk coverage.
* Leading fraud detection, powered by machine learning and Bolt’s team of risk experts, which frees you to focus on your business
* Bank-level security. Bolt is PCI DSS Level I and GDPR compliant.

= WHAT YOU DON’T NEED IF YOU USE BOLT: =
* Bolt takes what has historically needed 3+ tools and solves them in 1 platform.
* Checkout UI / optimization tools: Bolt is world-class checkout for WooCommerce. We relentlessly A/B test across the Bolt Network, and our highest performing updates automatically deploy to your site.
* A payment processor: No need to install Apple Pay, PayPal, Stripe, Braintree, or other tools. Bolt handles all payment processing for credit and debit cards.
* Fraud scoring or fraud detection software: Bolt leads the industry with its precision fraud engine. Say goodbye to setting up order blocking rules or buying expensive third-party solutions — we have you covered.
* Manual fraud review: Don’t spend hours reviewing orders. Bolt’s team of risk experts reviews each flagged order, freeing up time for you to focus on your business.
* Chargeback representment tools: All representment, including for non-fraud chargebacks, is coordinated by Bolt.

_\* Percentage increase in mobile conversion rates compares Bolt checkout completion rates across online retail partners from Aug 6 – Sep 4, 2018 to compiled benchmarks from, Barilliance, Formisimo, and the Baymard Institute._

== Screenshots ==
1. Optimized for web and mobile
2. Enable instant checkout
3. Never worry about fraud
4. Ongoing conversion optimization
5. Secure checkout and payment processing

== Installation ==
A Bolt merchant account is required for setup. Please contact [sales@bolt.com](mailto:sales@bolt.com) or schedule a check-in with our team at [bolt.com](bolt.com) to check your eligibility.

= Automatic Installation =
Automatic installation is the easiest option as WordPress handles the file transfers. Log in to your WordPress dashboard, navigate to the Plugins menu and click Add New.

= Manual Installation =
The manual installation method involves downloading our plugin and uploading it to your server via SFTP. Follow instructions at [help.bolt.com](https://help.bolt.com/developers/guides/checkout-guides/managed-checkout/woocommerce-setup-guide).

== Frequently Asked Questions ==
[developer docs](https://help.bolt.com/developers/)
[support](https://support.bolt.com)
[plugin upgrade guide](https://help.bolt.com/developers/guides/checkout-guides/managed-checkout/woocommerce-setup-guide#update-bolt-plugin)
[platform operations](https://help.bolt.com/merchants/guides/platforms/woocommerce)

== Changelog ==
= 2.20.0 - 2024-01-18 =
* Added support for WooCommerce High-Performance Order Storage
* Fixed issue where a `null` or failed response from Bolt's server causes a page rendering error

= 2.19.0 - 2023-12-07 =
* Added support for WooCommerce FedEx Shipping
* Added support for WooCommerce ShipperHQ
* Fixed compatibility issues with multiple third-party plugins which we already support, including: WooCommerce Google Analytics Pro, RouteApp, WooCommerce Smart Coupons
* Fixed issue where subscription was showing as an undefined index
* Fixed issue with Load Bolt PaymentOnly key on non-checkout page
* Fixed issue where plugin attempts to get() session value before WC()->session is initiated, which returns WC()->session as null and causes errors for subsequent calls on WC()->session
* Orders no longer duplicate when payment fails once

= 2.18.0 - 2023-02-17 =
* added support for Wholesale for WooCommerce
* Fixes issue where order statuses were not properly updated when payment was completed
* Resolves issue where the plugin returned a `null` customer value when applying a coupon via the Bolt modal
* Prevents issue where adding an item to a cart with a cart fee results in a checkout error
* The plugin now correctly reflects Amazon Pay and Paypal order refunds as `In Progress`

= 2.17.0 - 2022-11-18 =
* added support for Free Gifts for WooCommerce
* added support for WooCommerce Dynamic Pricing
* Fixes issue where WordPress administrator index page threw an error related to the Bolt plugin
* Fixes undefined constant BoltCheckout\id
* Fixes issue where negative fees were treated as discounts
* Resolves issue where merchants could not sync order meta data between WooCommerce and Route

= 2.16.0 - 2022-07-25 =
* now supports Single Sign-On for shoppers. (Requires enablement in the Bolt Admin Dashboard)
* added support for WooCommerce Store Credit
* added support for WooCommerce CheckoutWC Sidecart
* added Advanced Shipment Tracking Plugin support

= 2.15.0 - 2022-03-16 =
* now uses WooCommerce native session instead of Bolt cart session (Requires enablement in the Bolt Admin Dashboard)
* separate shipping and tax endpoints (Requires enablement in the Bolt Admin Dashboard)
* added support for Bolt order management
* added support for WooCommerce's Shipment Tracking plugin
* added support for non-Bolt orders
* added support for Route app
* added support for WooCommerce Side cart
* added support for Discount Rules for WooCommerce v2
* added support for PW WooCommerce Gift Cards
* bug fix - the callback function in the Bolt plugin is only triggered for Bolt orders.
* bug fix - variation attributes of cart items are now consistent and complete.

= 2.14.1 - 2021-04-22 =
* bug fix - unable to add/remove YITH gift card by the update-cart api request
* bug fix - use of undefined constant id
* bug fix - call to undefined function of WooCommerce_Extra_Shipping_Options
* bug fix - call to a member function on uninitialized WC()->session 

= 2.14.0 - 2021-03-04 =
* add support for Custom Fields
* add support for WooCommerce Dynamic Pricing
* add support for WooCommerce Extra Shipping Options
* add support for WooCommerce AvaTax
* add support for WooCommerce Conditional Shipping and Payments
* add support for WooCommerce Discount Rules
* bug fix - unable to edit the items from Bolt modal if item quantity is greater than stock quantity
* bug fix - removing items from cart with threshold discount causes an error
* bug fix - using WooCommerce Store Credit fails in some cases
* bug fix - reduce the chance of creating duplicate orders
* bug fix - checkout gets stuck at delivery step if the Bolt cart session is missing for some reasons
* bug fix - UPS api does not recognize encoded region code from Bolt plugin
* code cleanup for compatibility with old extensions

= 2.13.1 - 2020-10-13 =
* Patch for 2.13.0. Resolves issue where errors would display incorrect messaging to end-users

= 2.13.0 - 2020-09-29 =
* updated discount description format
* fix compatibility issue with YITH WooCommerce Gift Cards Premium
* fixed an issue that can cause Bolt checkout button missing
* fixed cart amount mismatch issue when store credit of Smart Coupons is applied from WooC native checkout page
* fixed an issue can cause cart loading error when cart item property value is too long

= 2.12.0 - 2020-08-19 =
* adds support to add/remove variable product for the update cart endpoint
* bug fix - Unexpected HTML contents generated by filter/action breaks Bolt cart
* bug fix - Undefined variation data
* bug fix - Value of Bolt cart item properties is too long

= 2.11.0 - 2020-07-09 =
* bug fix - exceptions when API key is not set
* bug fix - order token should be fetched again if it is empty

= 2.10.0 - 2020-06-17 =
* tested up to woocommerce 4.1.1
* bug fix -  backoffice key does not load for guest order
* fix display of APM/PayPal transactions within WooCommerce dashboard

= 2.9.0 - 2020-05-27 =
* bug fix - attribute value is missing if variation has unspecified attribute in product setup
* some refactoring and test coverage improvements

= 2.8.0 - 2020-05-06 =
* Fix compatibility issue with WooCommerce Smart Coupon
* Fix bug - Shipping tax calculation bug with 3rd-party shipping carrier plugin
* Fix bug - The field `ship_to_different_address` of WC posted data is set to incorrect value

= 2.7.1 - 2020-04-22 =
* Fix bug - Shipping tax calculation doesn't work with 3rd-party shipping carrier plugin

= 2.7.0 - 2020-04-15 =
* add option to remove default checkout buttons on cart page and mini cart
* fix discount total mismatch issue for the latest WooCommerce Smart Coupon plugin

= 2.6.0 - 2020-03-19 =
* show error on pay for order page if order doesn't have billing/shipping address
* if variable product does not have an image use parent product's image
* bug fix - shipping tax mismatch when calculating tax when shipping is taxable
* bug fix - correctly process refund when cart contains free item
* bug fix - unexpected warning `build cart: found rounding issue` is triggered when applying YITH gift cards

= 2.5.0 - 2020-03-03 =
* don't send API request when merchant key is empty
* update session time if we have failed hook to prevent data deletion
* handle discount rounding issue
* bug fix - Bolt does not calculate gift card for shipping if it is applied via WC coupon input box
* bug fix - Pre-auth order creation does not return error in proper way
* bux fix - error response for failed hook
* bug fix - Bolt discount input box does not accept Gift Card
* bug fix - shipping total mismatch when the balance of yith gift card
* bug fix - Bolt PPC does not work with Autoptimize
* bug fix - cart tax mismatch when wc option `Round tax at subtotal level, instead of rounding per line` is enabled

= 2.4.0 - 2020-02-04 =
* disable checkout tracking if bolt is not available
* tested up to Woocommerce 3.9.1
* removed duplicated plugin setting - 'quick_checkout_button_class'
* bug fix - product page checkout doesn't work together with Bolt subscription
* bug fix - invalid address displayed in bolt modal
* performance improvements and code cleanup
* various other bug fixes

= 2.3.0 - 2020-01-09 =
* implemented a new endpoint - update-cart (Beta) which is used for supporting features like product addon
* tested up to Woocommerce 3.8.1
* various bug fixes
* performance improvements and code cleanup

= 2.1.0 - 2019-11-21 =
* add support for multi-currency shop
* performance improvements on cache handling of shipping and tax endpoint
* performance improvement - write cart session in merchant DB only when we create new bolt cart
* bug fix - 'force_approve' and 'confirm_rejection' order actions
* bug fix - output bolt-quick-pay-btn div on cart page and minicart
* bug fix - fee is missing if created via hook
* bug fix - order creation error during shipping and tax calculation when merchant uses some third party plugins

= 2.0.12 - 2019-10-28 =
* Add support for Bolt custom checkboxes
* Add support for configuring publishable key back-office in setting
* Various bug fixes

= 2.0.11 - 2019-09-30 =
* performance improvements for woocommerce webhooks
* performance improvements for local pickup shipping method
* deprecate support for Woocommerce below 3.0.0
* some minor bug fixes

= 2.0.10 - 2019-09-03 =
* performance improvements for tax calculation
* updated the display of bolt transaction id the same as merchant dashboard
* performance improvements for javascript
* added support to data clean up in nginx
* some minor bug fixes

= 2.0.9 - 2019-08-20 =
* Hotfix for 2.0.8 - fixed an issue that could cause order creation error when using an alternative shipping option

= 2.0.8 - 2019-08-08 =
* Remove auto-capture setting
* Fix the calculation of item unit price in backoffice order
* Fix an issue that causes order creation to hang when the merchant's server is slow
* Update error messages for refund and checkout page
* Some code base improvements

= 2.0.7 - 2019-07-22 =
* Hotfix for 2.0.6 - fixed an issue that could cause order creation error when using an alternative shipping option

= 2.0.6 - 2019-07-17 =
* Add support for Woocommerce 3.6.5
* Use order_number instead order_id as display_id
* Add order notes when capture request is failed
* Update setting page to include pre-auth URL
* Various bug fixes
* Code refactoring and cleanup

= 2.0.5 - 2019-06-27 =
* Clean up unexpected js/html for Bolt API response
* Do not cancel unpaid order if it was created via backend
* Fix bug related to disappeared Bolt button in some cases
* Fix bug related to error message not showing in some cases
* Fix bug related to refund when auto capture is not enabled

= 2.0.4 - 2019-06-12 =
* Hotfix for 2.0.3

= 2.0.3 - 2019-06-05 =
* Add support for Woocommerce 3.6.4
* Fix bug related to order notes when enabled pre-auth order creation
* Fix bug related to "confirm email address" field

= 2.0.2 - 2019-05-23 =
* Improve reaction time to changes on the cart page
* Add support for Woocommerce 3.6.3
* Improve support for Klaviyo abondoned carts
* Add support for WooCommerce Smart Coupons

= 2.0.1 - 2019-04-24 =
* Support for WooCommerce 3.6
* Support for WooCommerce Smart Coupons

= 2.0.0 - 2019-04-18 =
* Pre-Authorization WooCommerce Order Creation
* Enhanced Product Page Checkout Support
* FedEx Shipping Calcuation Correction

= 1.3.7 - 2019-04-10 =
* Hotfix for 1.3.6

= 1.3.6 - 2019-03-27 =
* Support for WooCommerce TM Extra Product Options
* Support for TaxJar
* Fix bug related to local pickup delivery method taxes
* Improve Apple Pay experience for Canada/UK

= 1.3.5 - 2019-02-11 =
* Skip postcode validation for Apple Pay transactions
* Support French Canadian province names
* Updated cart validation before order creation
* Bug fixes

= 1.3.4 - 2019-01-28 =
* Disable the mini-cart checkout button from cart pages
* Bug fixes

= 1.3.3 - 2019-01-15 =
* Support for Apple Pay
* Bolt Product Page Checkout Beta
* Bolt Subscriptions Beta
* Support for YITH WooCommerce Gift Cards Premium plugin
* Bug fixes

= 1.3.2 - 2018-12-18 =
* Add item properties to cart information
* Set user context in cart
* Decode HTML symbols in shipping options
* Set correct value for billing name field

= 1.3.1 - 2018-12-13 =
* Fix bug validating one-time use coupon
* Fix checkout button display styling

= 1.3.0 - 2018-12-10 =
* Add support for Apple Pay
* Restructure plugin options panel
* Fix bug related to shipping options with `&` sign

= 1.2.8 - 2018-10-30 =
* Merchant can now confirm rejection or force approve transaction from wp-admin
* Fix post-purchase redirection for $0 order
* bugfixes

= 1.2.7 - 2018-10-30 =
* Bugfixes

= 1.2.6 - 2018-10-24 =
* Option to bypass state validation
* Better error message for shipping error

= 1.2.5 - 2018-10-16 =
* Add better support for international regions
* Klaviyo support
* Update handling of stocks

= 1.2.4 - 2018-10-09 =
* Add better support for chinese regions
* Add support for Bolt theme customization

= 1.2.3 - 2018-10-04 =
* No hook when capture is triggered from WooCommerce
* Hotfix for 2.4.3 support

= 1.2.2 - 2018-10-04 =
* Fix order status transitions when transaction is captured after completed

= 1.2.1 - 2018-09-23 =
* Better treatment of US terittory
* Validate order during shipping&tax API call
* Add option to change button color
* Make the plugin compatible with 2.4.3

= 1.2.0 - 2018-09-23 =
* Add logic to cleanup outdated session data
* Add hooks to modify shipping options
* Bugfixes

= 1.1.5 - 2018-09-05 =
* Option to sync abandonded cart to woocommerce
* Minor bugfixes

= 1.1.4 - 2018-08-23 =
* Performance improvement

= 1.1.3 - 2018-08-14 =
* Bugfix around refund
* Minor bugfix

= 1.1.2 - 2018-07-26 =
* Adding support for mini cart
* Do not cancel when hook fails

= 1.1.1 - 2018-07-17 =
* Fix around order validation
* Fix handling of Quevec
* Bugfix around quick checkout

= 1.1.0 - 2018-07-03 =
* invoice for order email
* add support for cart fees
* move bolt txn to private note
* add option to disallow PO box

= 1.0.11 - 2018-06-12 =
* Fix related to US country code
* Fix related to session

= 1.0.10 - 2018-06-06 =
* Properly update inventory
* Add validation before checkout
* Enable to pass back woocommerce's order ID via hook response

= 1.0.9 - 2018-04-26 =
* Refine hook logic
* Fix handling of refund hook

= 1.0.8 - 2018-04-24 =
* Fix handling of order in hooks
* Fix error with bad email

= 1.0.7 - 2018-04-19 =
* Fix conflict with other payment methods
* Fix rounding issue
* Fix escape of JavaScript config

= 1.0.6 - 2018-04-16 =
* Fix error related to using floating point when calling Bolt API
* Minor bugfixes

= 1.0.5 - 2018-04-10 =
* Adds consistent labeling of Bolt Transaction Reference
* Improvements in bugsnag reporting
* Add support for WITH gift cards
* Update response format for hooks
* Bugfixes
