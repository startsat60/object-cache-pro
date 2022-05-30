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

namespace RedisCachePro\Plugin\Extensions;

use QM_Collectors;

use RedisCachePro\Extensions\QueryMonitor\CommandsCollector;
use RedisCachePro\Extensions\QueryMonitor\CommandsHtmlOutput;
use RedisCachePro\Extensions\QueryMonitor\ObjectCacheCollector;
use RedisCachePro\Extensions\QueryMonitor\ObjectCacheHtmlOutput;

trait QueryMonitor
{
    /**
     * Boot Query Monitor component and register panels.
     *
     * @return void
     */
    public function bootQueryMonitor()
    {
        if (! class_exists('QM')) {
            return;
        }

        add_filter('init', [$this, 'registerQmCollectors']);
        add_filter('qm/outputter/html', [$this, 'registerQmOutputters'], 10, 2);
    }

    /**
     * Registers all object cache related Query Monitor collectors.
     *
     * @return void
     */
    public function registerQmCollectors()
    {
        if (! class_exists('QM_Collector')) {
            return;
        }

        require_once dirname(__FILE__) . '/../../Extensions/QueryMonitor/ObjectCacheCollector.php';
        require_once dirname(__FILE__) . '/../../Extensions/QueryMonitor/CommandsCollector.php';

        QM_Collectors::add(new ObjectCacheCollector);
        QM_Collectors::add(new CommandsCollector);
    }

    /**
     * Registers all object cache related Query Monitor HTML outputters.
     *
     * @param  array  $output
     * @param  QM_Collectors  $collectors
     * @return array
     */
    public function registerQmOutputters(array $output, QM_Collectors $collectors)
    {
        if (! class_exists('QM_Output_Html')) {
            return;
        }

        // Added in Query Monitor 3.1.0
        if (! method_exists('QM_Output_Html', 'before_non_tabular_output')) {
            return;
        }

        require_once dirname(__FILE__) . '/../../Extensions/QueryMonitor/ObjectCacheHtmlOutput.php';
        require_once dirname(__FILE__) . '/../../Extensions/QueryMonitor/CommandsHtmlOutput.php';

        $output['cache'] = new ObjectCacheHtmlOutput(
            QM_Collectors::get('cache')
        );

        $output['cache_log'] = new CommandsHtmlOutput(
            QM_Collectors::get('cache-commands')
        );

        return $output;
    }
}
