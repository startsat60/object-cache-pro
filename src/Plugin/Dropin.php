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

use Throwable;

trait Dropin
{
    /**
     * Boot dropin component.
     *
     * @return void
     */
    public function bootDropin()
    {
        add_action('upgrader_process_complete', [$this, 'maybeUpdateDropin'], 10, 2);
    }

    /**
     * Attempt to enable the object cache drop-in.
     *
     * @return bool
     */
    public function enableDropin()
    {
        global $wp_filesystem;

        if (! \WP_Filesystem()) {
            return false;
        }

        $dropin = \WP_CONTENT_DIR . '/object-cache.php';
        $stub = realpath(__DIR__ . '/../../stubs/object-cache.php');

        $result = $wp_filesystem->copy($stub, $dropin, true, FS_CHMOD_FILE);

        if (function_exists('wp_opcache_invalidate')) {
            wp_opcache_invalidate($dropin, true);
        }

        if (apply_filters('objectcache_autoflush', true)) {
            $this->flush();
        }

        return $result;
    }

    /**
     * Attempt to disable the object cache drop-in.
     *
     * @return bool
     */
    public function disableDropin()
    {
        global $wp_filesystem;

        if (! \WP_Filesystem()) {
            return false;
        }

        $dropin = \WP_CONTENT_DIR . '/object-cache.php';

        if (! $wp_filesystem->exists($dropin)) {
            return false;
        }

        $result = $wp_filesystem->delete($dropin);

        if (function_exists('wp_opcache_invalidate')) {
            wp_opcache_invalidate($dropin, true);
        }

        if (apply_filters('objectcache_autoflush', true)) {
            $this->flush();
        }

        return $result;
    }

    /**
     * Update the object cache drop-in, if it's outdated.
     *
     * @param  WP_Upgrader  $upgrader
     * @param  array  $options
     * @return bool
     */
    public function maybeUpdateDropin($upgrader, $options)
    {
        $this->verifyDropin();

        if (! wp_is_file_mod_allowed('object_cache_dropin')) {
            return;
        }

        if ($options['action'] !== 'update' || $options['type'] !== 'plugin') {
            return;
        }

        if (! in_array($this->basename, $options['plugins'] ?? [])) {
            return;
        }

        $diagnostics = $this->diagnostics();

        if (! $diagnostics->dropinExists() || ! $diagnostics->dropinIsValid()) {
            return;
        }

        if ($diagnostics->dropinIsUpToDate()) {
            return;
        }

        return $this->enableDropin();
    }

    /**
     * Verifies the object cache drop-in.
     *
     * @return void
     */
    public function verifyDropin()
    {
        if (! $this->license()->isValid()) {
            $this->disableDropin();
        }
    }

    /**
     * Initializes and connects the WordPress Filesystem Abstraction classes.
     *
     * @return void
     */
    protected function wpFilesystem()
    {
        global $wp_filesystem;

        try {
            require_once \ABSPATH . 'wp-admin/includes/plugin.php';
        } catch (Throwable $th) {
            //
        }

        if (! \WP_Filesystem()) {
            try {
                require_once \ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                require_once \ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            } catch (Throwable $th) {
                //
            }

            return new \WP_Filesystem_Direct(null);
        }

        return $wp_filesystem;
    }
}
