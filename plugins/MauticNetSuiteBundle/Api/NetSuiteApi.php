<?php

namespace MauticPlugin\MauticNetSuiteBundle\Api;

use MauticPlugin\MauticCrmBundle\Api\CrmApi;

class NetSuiteApi extends CrmApi {
    protected function request($operation, $parameters = [], $method = 'GET', $object = 'contacts')
    {
        // @todo implement
    }

    /**
     * @return mixed
     */
    public function getLeadFields($object = 'contacts')
    {
        // @todo implement
    }

    /**
     * Creates Hubspot lead.
     *
     * @param array $data
     *
     * @return mixed
     */
    public function createLead(array $data, $lead, $updateLink = false)
    {
        // @todo implement
    }

    /**
     * gets Hubspot contact.
     *
     * @param array $data
     *
     * @return mixed
     */
    public function getContacts($params = [])
    {
        // @todo implement
    }

    /**
     * gets Hubspot company.
     *
     * @param array $data
     *
     * @return mixed
     */
    public function getCompanies($params, $id)
    {
        // @todo implement
    }

    /**
     * @param        $propertyName
     * @param string $object
     *
     * @return mixed|string
     */
    public function createProperty($propertyName, $object = 'properties')
    {
        // @todo implement
    }
}
