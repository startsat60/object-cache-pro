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

namespace RedisCachePro\Metrics;

use RedisCachePro\Connections\RelayConnection;

class RelayMetrics
{
    /**
     * Number of successful key lookups.
     *
     * @var int
     */
    public $hits;

    /**
     * Number of failed key lookups.
     *
     * @var int
     */
    public $misses;

    /**
     * The amount of memory actually pointing to live objects.
     *
     * @var float
     */
    public $memoryActive;

    /**
     * The capped number of bytes we Relay has available to use.
     *
     * @var int
     */
    public $memoryLimit;

    /**
     * Creates a new instance from given connection.
     *
     * @param  \RedisCachePro\Connections\RelayConnection  $cache
     * @return void
     */
    public function __construct(RelayConnection $connection)
    {
        $stats = $connection->stats();

        $this->hits = $stats['stats']['hits'];
        $this->misses = $stats['stats']['misses'];
        $this->memoryActive = $stats['memory']['active'];
        $this->memoryLimit = $stats['memory']['limit'];
        $this->memoryRatio = round(($this->memoryActive / $this->memoryLimit) * 100, 2);
    }

    /**
     * Returns the Relay metrics as array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'memory-active' => $this->memoryActive,
            'memory-limit' => $this->memoryLimit,
            'memory-ratio' => $this->memoryRatio,
        ];
    }

    /**
     * Returns the Relay metrics in string format.
     *
     * @return string
     */
    public function __toString()
    {
        $metrics = $this->toArray();

        return implode(' ', array_map(function ($metric, $value) {
            return sprintf("sample#${metric}", $value);
        }, array_keys($metrics), $metrics));
    }
}
