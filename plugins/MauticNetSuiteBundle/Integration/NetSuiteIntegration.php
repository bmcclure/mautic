<?php

namespace MauticPlugin\MauticNetSuiteBundle\Integration;

use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;

class NetSuiteIntegration extends CrmAbstractIntegration {

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'NetSuite';
    }

    public function getRequiredKeyFields()
    {
        return [
            'netsuite_consumer_key' => 'mautic.netsuite.form.consumer_key',
            'netsuite_consumer_secret' => 'mautic.netsuite.form.consumer_secret',
            'netsuite_token_key' => 'mautic.netsuite.form.token_key',
            'netsuite_token_secret' => 'mautic.netsuite.form.token_secret',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthenticationType()
    {
        return 'none';
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthenticationUrl()
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessTokenUrl()
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedFeatures()
    {
        return ['push_lead', 'get_leads', 'push_leads', 'sync_leads'];
    }

    /**
     * {@inheritDoc}
     */
    public function getFormSettings()
    {
        $enableDataPriority = $this->getDataPriority();

        return [
            'requires_callback' => false,
            'requires_authorization' => false,
            'default_features' => [],
            'enable_data_priority' => $enableDataPriority,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getDataPriority()
    {
        return true;
    }
}
