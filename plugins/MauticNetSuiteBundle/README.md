# Installation

1. Extract this plugin into plugins/MauticNetSuiteBundle
2. Run `composer require ryanwinchester/netsuite-php`.

# Cronjob

Example:

`php app/console mautic:integration:fetchleads --integration=NetSuite --fetch-all --limit=100`
