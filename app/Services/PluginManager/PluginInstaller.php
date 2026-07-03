<?php

namespace FluentBookingPro\App\Services\PluginManager;

class PluginInstaller
{
    const CORE_BASENAME = 'fluent-booking/fluent-booking.php';
    const CORE_SLUG = 'fluent-booking';

    /**
     * Register the core update pusher (see maybePushCoreUpdate).
     *
     * @return void
     */
    public function registerCoreUpdater()
    {
        add_filter('site_transient_update_plugins', [$this, 'maybePushCoreUpdate'], 20);
    }

    /**
     * Push the required FluentBooking core update directly from wordpress.org during
     * the WP.org "cool-down" window.
     *
     * After a release, wp.org delays the update-check API (~24h) so the new
     * version is not offered on the Plugins screen, even though the build zip is
     * already published at downloads.wordpress.org. When the installed core is
     * older than FLUENT_BOOKING_MIN_CORE_VERSION we inject an update entry pointing
     * straight at that zip so users can update (and auto-update) without waiting
     * out the window.
     *
     * @param object $transient The update_plugins site transient.
     * @return object
     */
    public function maybePushCoreUpdate($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $minVersion = FLUENT_BOOKING_MIN_CORE_VERSION;

        // Core must be installed and below the required minimum.
        if (!defined('FLUENT_BOOKING_VERSION') || version_compare(FLUENT_BOOKING_VERSION, $minVersion, '>=')) {
            return $transient;
        }

        $existing = null;
        if (isset($transient->response[self::CORE_BASENAME]) && is_object($transient->response[self::CORE_BASENAME])) {
            $existing = $transient->response[self::CORE_BASENAME];
        } elseif (isset($transient->no_update[self::CORE_BASENAME]) && is_object($transient->no_update[self::CORE_BASENAME])) {
            $existing = $transient->no_update[self::CORE_BASENAME];
        }

        // If WP already surfaced an update at or above the minimum, let it handle it.
        if ($existing && isset($existing->new_version) && version_compare($existing->new_version, $minVersion, '>=')) {
            return $transient;
        }

        $package = 'https://downloads.wordpress.org/plugin/' . self::CORE_SLUG . '.' . $minVersion . '.zip';

        // Reuse the entry WP already built (it carries id/slug/icons/banners/
        // tested/requires metadata the update UI renders); during the cool-down
        // core sits in no_update. Only fall back to a fresh object if WP hasn't
        // populated the transient yet.
        if ($existing) {
            $update = clone $existing;
        } else {
            $update = (object) [
                'id'     => 'w.org/plugins/' . self::CORE_SLUG,
                'slug'   => self::CORE_SLUG,
                'plugin' => self::CORE_BASENAME,
                'url'    => 'https://wordpress.org/plugins/' . self::CORE_SLUG . '/',
            ];
        }

        $update->new_version = $minVersion;
        $update->package     = $package;

        $transient->response[self::CORE_BASENAME] = $update;
        unset($transient->no_update[self::CORE_BASENAME]);

        return $transient;
    }
}
