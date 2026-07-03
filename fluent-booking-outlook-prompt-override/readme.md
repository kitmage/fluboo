# Fluent Booking Outlook Prompt Override

A small WordPress drop-in plugin that works around Entra consent-policy conflicts in Fluent Booking Pro's shared Outlook OAuth flow.

## Problem

Fluent Booking Pro starts Outlook calendar authorization by sending the browser to Fluent's shared OAuth bootstrap endpoint. That endpoint redirects to Microsoft with:

```text
prompt=consent
```

Some Microsoft Entra configurations treat that forced consent prompt as an admin-consent request path. If admin approval is blocked or not completing correctly, the Outlook connection can fail even when the same authorization URL works after manually changing the parameter to:

```text
prompt=select_account
```

## What this plugin does

The plugin uses Fluent Booking Pro's `fluent_booking/outlook_app_redirect_url` filter to route only the initial Outlook authorization request through a local WordPress proxy.

The proxy:

1. Receives Fluent Booking's `client_id` and WordPress callback `redirect_uri` parameters.
2. Requests Fluent's original Outlook bootstrap URL without following redirects.
3. Reads the Microsoft authorization URL from Fluent's `Location` response header.
4. Replaces `prompt=consent` with `prompt=select_account`.
5. Redirects the browser to Microsoft.

During Fluent Booking's callback/token exchange, the plugin returns Fluent's original redirect URL so the OAuth `redirect_uri` remains consistent with the authorization code that Microsoft issued.

## Installation

### MU-plugin installation

Copy this directory to:

```text
wp-content/mu-plugins/fluent-booking-outlook-prompt-override
```

Then load the plugin file from a small MU-plugin bootstrap file:

```php
<?php
require_once WPMU_PLUGIN_DIR . '/fluent-booking-outlook-prompt-override/fluent-booking-outlook-prompt-override.php';
```

For example, create:

```text
wp-content/mu-plugins/fluent-booking-outlook-prompt-override.php
```

with the `require_once` statement above.

### Standard plugin installation

Alternatively, copy this directory to:

```text
wp-content/plugins/fluent-booking-outlook-prompt-override
```

Then activate **Fluent Booking Outlook Prompt Override** from **Plugins** in WordPress admin.

## Requirements

- WordPress with Fluent Booking Pro installed and active.
- Fluent Booking Pro's Outlook calendar integration.
- PHP 7.4 or newer.
- Outbound HTTPS requests from WordPress to `https://fluentbooking.com`.

## Security notes

The proxy performs several checks before redirecting the browser:

- Requires a logged-in user.
- Requires either `manage_options` or `edit_posts` capability.
- Allows only the current site's `admin-ajax.php?action=fluent_booking_outlook_auth` callback URL.
- Allows only `https://login.microsoftonline.com/.../oauth2/v2.0/authorize` as the final redirect target.

If your Fluent Booking hosts use a different capability model, adjust `fbopo_current_user_can_connect_outlook()` in the plugin file.

## Troubleshooting

### The button still goes to Fluent's server directly

Confirm the plugin is loaded. If installed as an MU-plugin directory, WordPress will not automatically load nested plugin files. You need the bootstrap file shown in the installation instructions.

### The connection fails during callback

Confirm no other snippet globally changes `fluent_booking/outlook_app_redirect_url`. During the `fluent_booking_outlook_auth` callback, this plugin intentionally returns Fluent's original redirect URL so the token exchange can succeed.

### WordPress shows an invalid callback URL error

Check whether the Fluent Booking callback URL is on the same scheme, host, and `admin-ajax.php` path as the current WordPress site. This plugin intentionally rejects callback URLs for other hosts.

## Removal

Deactivate the plugin or remove the MU-plugin bootstrap file. No database tables or options are created.
