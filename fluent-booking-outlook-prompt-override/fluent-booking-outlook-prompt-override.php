<?php
/**
 * Plugin Name: Fluent Booking Outlook Prompt Override
 * Description: Routes Fluent Booking Outlook OAuth starts through a local proxy that changes Microsoft's prompt parameter from consent to select_account.
 * Version: 1.0.0
 * Author: Aspen Behavioral
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

const FBOPO_FLUENT_OUTLOOK_REDIRECT_URL = 'https://fluentbooking.com/wp-json/fluent-api/outlook/';
const FBOPO_PROXY_ACTION = 'fbopo_outlook_prompt_proxy';
const FBOPO_PENDING_STATE_TRANSIENT_PREFIX = 'fbopo_pending_outlook_state_';

/**
 * Replace Fluent Booking's initial Outlook OAuth bootstrap URL with a local proxy.
 *
 * The same Fluent Booking filter is also used while exchanging the Microsoft
 * authorization code for tokens. During that callback, keep Fluent's original
 * redirect URI so the token request still matches the authorization request.
 */
add_filter('fluent_booking/outlook_app_redirect_url', function ($url) {
    if (fbopo_is_outlook_callback()) {
        return FBOPO_FLUENT_OUTLOOK_REDIRECT_URL;
    }

    return admin_url('admin-post.php?action=' . FBOPO_PROXY_ACTION);
});

add_action('admin_post_' . FBOPO_PROXY_ACTION, 'fbopo_handle_outlook_prompt_proxy');
add_action('wp_ajax_fluent_booking_outlook_auth', 'fbopo_restore_missing_outlook_state', 1);

/**
 * Determine whether Fluent Booking is handling its Outlook OAuth callback.
 */
function fbopo_is_outlook_callback()
{
    if (!is_admin()) {
        return false;
    }

    $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    return $action === 'fluent_booking_outlook_auth';
}

/**
 * Proxy the initial Fluent Booking Outlook OAuth request and rewrite prompt.
 */
function fbopo_handle_outlook_prompt_proxy()
{
    if (!is_user_logged_in()) {
        auth_redirect();
    }

    if (!fbopo_current_user_can_connect_outlook()) {
        wp_die(esc_html__('You are not allowed to connect Outlook calendars.', 'fluent-booking-outlook-prompt-override'), 403);
    }

    $clientId = isset($_GET['client_id']) ? sanitize_text_field(wp_unslash($_GET['client_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $redirectUri = isset($_GET['redirect_uri']) ? esc_url_raw(wp_unslash($_GET['redirect_uri'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if (!$clientId || !$redirectUri) {
        wp_die(esc_html__('Missing Outlook authorization parameters.', 'fluent-booking-outlook-prompt-override'), 400);
    }

    if (!fbopo_is_allowed_redirect_uri($redirectUri)) {
        wp_die(esc_html__('Invalid Outlook callback URL.', 'fluent-booking-outlook-prompt-override'), 400);
    }

    fbopo_remember_outlook_state($redirectUri);

    $fluentUrl = add_query_arg([
        'client_id'    => $clientId,
        'redirect_uri' => $redirectUri,
    ], FBOPO_FLUENT_OUTLOOK_REDIRECT_URL);

    $response = wp_remote_get($fluentUrl, [
        'redirection' => 0,
        'timeout'     => 15,
    ]);

    if (is_wp_error($response)) {
        wp_die(esc_html($response->get_error_message()), 500);
    }

    $location = wp_remote_retrieve_header($response, 'location');

    if (!$location) {
        wp_die(esc_html__('Fluent Booking did not return an Outlook authorization redirect.', 'fluent-booking-outlook-prompt-override'), 500);
    }

    if (!fbopo_is_microsoft_authorize_url($location)) {
        wp_die(esc_html__('Fluent Booking returned an unexpected Outlook authorization URL.', 'fluent-booking-outlook-prompt-override'), 500);
    }

    $location = remove_query_arg('prompt', $location);
    $location = add_query_arg('prompt', 'select_account', $location);

    wp_redirect($location);
    exit;
}


/**
 * Store Fluent Booking's calendar/user state before the browser leaves WordPress.
 *
 * Fluent's shared OAuth proxy can return to admin-ajax.php without the original
 * nested state query argument. Saving it locally lets the early callback hook
 * restore the value before Fluent Booking's callback handler runs.
 */
function fbopo_remember_outlook_state($redirectUri)
{
    $parts = wp_parse_url($redirectUri);

    if (empty($parts['query'])) {
        return;
    }

    parse_str($parts['query'], $queryArgs);

    if (empty($queryArgs['state'])) {
        return;
    }

    set_transient(
        FBOPO_PENDING_STATE_TRANSIENT_PREFIX . get_current_user_id(),
        sanitize_text_field(wp_unslash($queryArgs['state'])),
        15 * MINUTE_IN_SECONDS
    );
}

/**
 * Restore the missing Fluent Booking state before its Outlook callback executes.
 */
function fbopo_restore_missing_outlook_state()
{
    if (!is_user_logged_in() || empty($_GET['code']) || !empty($_GET['state'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    $transientKey = FBOPO_PENDING_STATE_TRANSIENT_PREFIX . get_current_user_id();
    $state = get_transient($transientKey);

    if (!$state) {
        return;
    }

    delete_transient($transientKey);

    $_GET['state'] = $state;
    $_REQUEST['state'] = $state;
}

/**
 * Check whether the current user should be allowed to start this OAuth flow.
 *
 * Fluent Booking performs the calendar-level permission check during the OAuth
 * callback. Keep this proxy permissive enough for non-editor hosts while still
 * requiring an authenticated WordPress user by default.
 */
function fbopo_current_user_can_connect_outlook()
{
    $canConnect = current_user_can('read');

    return (bool) apply_filters('fluent_booking_outlook_prompt_override_user_can_connect', $canConnect);
}

/**
 * Restrict the callback URL to this WordPress site's Outlook callback endpoint.
 */
function fbopo_is_allowed_redirect_uri($redirectUri)
{
    $expected = admin_url('admin-ajax.php');
    $redirectParts = wp_parse_url($redirectUri);
    $expectedParts = wp_parse_url($expected);

    if (!$redirectParts || !$expectedParts) {
        return false;
    }

    $sameScheme = isset($redirectParts['scheme'], $expectedParts['scheme']) && strtolower($redirectParts['scheme']) === strtolower($expectedParts['scheme']);
    $sameHost = isset($redirectParts['host'], $expectedParts['host']) && strtolower($redirectParts['host']) === strtolower($expectedParts['host']);
    $samePath = isset($redirectParts['path'], $expectedParts['path']) && $redirectParts['path'] === $expectedParts['path'];

    if (!$sameScheme || !$sameHost || !$samePath) {
        return false;
    }

    parse_str($redirectParts['query'] ?? '', $queryArgs);

    return isset($queryArgs['action']) && $queryArgs['action'] === 'fluent_booking_outlook_auth';
}

/**
 * Allow only Microsoft's OAuth authorize endpoint as the final browser redirect.
 */
function fbopo_is_microsoft_authorize_url($url)
{
    $parts = wp_parse_url($url);

    if (!$parts || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
        return false;
    }

    return strtolower($parts['scheme']) === 'https'
        && strtolower($parts['host']) === 'login.microsoftonline.com'
        && strpos($parts['path'], '/oauth2/v2.0/authorize') !== false;
}
