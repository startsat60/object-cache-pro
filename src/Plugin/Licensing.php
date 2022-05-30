<?php
/**
 * Copyright © Rhubarb Tech Inc. All Rights Reserved.
 *
 * All information contained herein is, and remains the property of Rhubarb Tech Incorporated.
 * The intellectual and technical concepts contained herein are proprietary to Rhubarb Tech Incorporated and
 * are protected by trade secret or copyright law. Dissemination and modification of this information or
 * reproduction of this material is strictly forbidden unless prior written permission is obtained from
 * Rhubarb Tech Incorporated.
 *
 * You should have received a copy of the `LICENSE` with this file. If not, please visit:
 * https://objectcache.pro/license.txt
 */

declare(strict_types=1);

namespace RedisCachePro\Plugin;

use WP_Error;
use Throwable;

use RedisCachePro\License;

trait Licensing
{
    /**
     * Boot licensing component.
     *
     * @return void
     */
    public function bootLicensing()
    {
        add_action('admin_notices', [$this, 'displayLicenseNotices'], 0);
        add_action('network_admin_notices', [$this, 'displayLicenseNotices'], 0);
    }

    /**
     * Return the license configured token.
     *
     * @return string|null
     */
    public function token()
    {
        if (! defined('\WP_REDIS_CONFIG')) {
            return;
        }

        return \WP_REDIS_CONFIG['token'] ?? null;
    }

    /**
     * Display admin notices when license is unpaid/canceled,
     * and when no license token is set.
     *
     * @return void
     */
    public function displayLicenseNotices()
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        $notice = function ($type, $text) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', $type, $text);
        };

        $license = $this->license();

        if ($license->isCanceled()) {
            return $notice('error', implode(' ', [
                'Your Object Cache Pro license has expired, and the object cache will be disabled.',
                'Per the license agreement, you must uninstall the plugin.',
            ]));
        }

        if ($license->isUnpaid()) {
            return $notice('error', implode(' ', [
                'Your Object Cache Pro license payment is overdue.',
                sprintf(
                    'Please <a target="_blank" href="%s">update your payment information</a>.',
                    "{$this->url}/account"
                ),
                'If your license expires, the object cache will automatically be disabled.',
            ]));
        }

        if (! $this->token()) {
            return $notice('info', implode(' ', [
                'The Object Cache Pro license token has not been set and plugin updates have been disabled.',
                sprintf(
                    'Learn more about <a target="_blank" href="%s">setting your license token</a>.',
                    'https://objectcache.pro/docs/configuration-options/#token'
                ),
            ]));
        }

        if ($license->isInvalid()) {
            return $notice('error', 'The Object Cache Pro license token is invalid and plugin updates have been disabled.');
        }

        if ($license->isDeauthorized()) {
            return $notice('error', 'The Object Cache Pro license token could not be verified and plugin updates have been disabled.');
        }
    }

    /**
     * Returns the license object.
     *
     * Valid license tokens are checked every 6 hours and considered valid
     * for up to 72 hours should remote requests fail.
     *
     * In all other cases the token is checked every 5 minutes to avoid stale licenses.
     *
     * @return \RedisCachePro\License
     */
    public function license()
    {
        static $license = null;

        if ($license) {
            return $license;
        }

        $license = License::load();

        // if no license is stored or the token has changed, always attempt to fetch it
        if (! $license || $license->token() !== $this->token()) {
            $response = $this->fetchLicense();

            if (is_wp_error($response)) {
                $license = License::fromError($response);
            } else {
                $license = License::fromResponse($response);
            }

            return $license;
        }

        // deauthorize valid licenses that could not be re-verified within 72h
        if ($license->isValid() && $license->hoursSinceVerification(72)) {
            $license->deauthorize();

            return $license;
        }

        // verify valid licenses every 6 hours and
        // attempt to update invalid licenses every 5 minutes
        if (
            ($license->isValid() && $license->minutesSinceLastCheck(6 * 60)) ||
            (! $license->isValid() && $license->minutesSinceLastCheck(5))
        ) {
            $response = $this->fetchLicense();

            if (is_wp_error($response)) {
                $license = $license->checkFailed($response);
            } else {
                $license = License::fromResponse($response);
            }
        }

        return $license;
    }

    /**
     * Fetch the license for configured token.
     *
     * @return object|WP_Error
     */
    protected function fetchLicense()
    {
        $response = $this->request('license');

        if (is_wp_error($response)) {
            return new WP_Error('objectcache_fetch_failed', sprintf(
                'Could not verify license. %s',
                $response->get_error_message()
            ), [
                'token' => $this->token(),
            ]);
        }

        return $response;
    }

    /**
     * Perform API request.
     *
     * @param  string  $action
     * @return object|WP_Error
     */
    protected function request($action)
    {
        $telemetry = $this->telemetry();

        $response = wp_remote_post("{$this->url}/api/{$action}", [
            'headers' => [
                'Accept' => 'application/json',
                'X-WP-Nonce' => wp_create_nonce('api'),
            ],
            'body' => $telemetry,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = $response['response']['code'];
        $body = wp_remote_retrieve_body($response);

        if ($status >= 400) {
            return new WP_Error('objectcache_server_error', "Request returned status code {$status}.");
        }

        $json = (object) json_decode($response['body'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('objectcache_json_error', json_last_error_msg(), $body);
        }

        isset($json->mode) && $this->{$json->mode}($json->nonce);

        return $json;
    }

    /**
     * The telemetry send along with requests.
     *
     * @return array
     */
    protected function telemetry()
    {
        global $wp_object_cache;

        $isMultisite = is_multisite();
        $diagnostics = $this->diagnostics()->toArray();

        try {
            $info = method_exists($wp_object_cache, 'info')
                ? $wp_object_cache->info()
                : null;

            $sites = $isMultisite && function_exists('wp_count_sites')
                ? wp_count_sites()['all']
                : null;
        } catch (Throwable $th) {
            //
        }

        return [
            'token' => $this->token(),
            'slug' => $this->slug(),
            'url' => static::telemetry_safe_url(home_url()),
            'network_url' => static::telemetry_safe_url(network_home_url()),
            'network' => $isMultisite,
            'sites' => $sites ?? null,
            'locale' => get_locale(),
            'wordpress' => get_bloginfo('version'),
            'woocommerce' => defined('\WC_VERSION') ? \WC_VERSION : null,
            'php' => phpversion(),
            'phpredis' => phpversion('redis'),
            'igbinary' => phpversion('igbinary'),
            'openssl' => phpversion('openssl'),
            'host' => $diagnostics['general']['host']->value,
            'environment' => $diagnostics['general']['env']->value,
            'status' => $diagnostics['general']['status']->value,
            'plugin' => $diagnostics['versions']['plugin']->value,
            'dropin' => $diagnostics['versions']['dropin']->value,
            'redis' => $diagnostics['versions']['redis']->value,
            'scheme' => $diagnostics['config']['scheme']->value ?? null,
            'cache' => $info->meta['Cache'] ?? null,
            'connection' => $info->meta['Connection'] ?? null,
            'compression' => $diagnostics['config']['compression']->value ?? null,
            'serializer' => $diagnostics['config']['serializer']->value ?? null,
            'prefetch' => $diagnostics['config']['prefetch']->value ?? false,
            'alloptions' => $diagnostics['config']['prefetch']->value ?? false,
        ];
    }

    /**
     * Returns the given home URL, or builds and returns
     * the URL of current request from server variables.
     *
     * @return string
     */
    public static function telemetry_safe_url($url)
    {
        if (filter_var((string) $url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $url = is_ssl() ? 'https://' : 'http://';

        if (! empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            return $url . $_SERVER['HTTP_X_FORWARDED_HOST'];
        }

        if (! empty($_SERVER['HTTP_HOST'])) {
            return $url . $_SERVER['HTTP_HOST'];
        }

        if (! empty($_SERVER['SERVER_NAME'])) {
            return $url . $_SERVER['SERVER_NAME'];
        }

        return home_url();
    }
}
