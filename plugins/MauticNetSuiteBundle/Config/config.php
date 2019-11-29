<?php

/*
 * @copyright   2019 Brilliant Metrics. All rights reserved
 * @author      Ben McClure
 *
 * @link        http://www.brilliantmetrics.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

return [
    'name'        => 'NetSuite',
    'description' => 'Enables integration with the NetSuite CRM.',
    'version'     => '1.0',
    'author'      => 'Brilliant Metrics',
    'services' => [
        'integrations' => [
            'mautic.integration.netsuite' => [
                'class'     => \MauticPlugin\MauticNetSuiteBundle\Integration\NetSuiteIntegration::class,
                'arguments' => [],
            ],
        ],
    ],
];
