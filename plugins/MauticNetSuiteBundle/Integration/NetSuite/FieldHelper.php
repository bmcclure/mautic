<?php

namespace MauticPlugin\MauticNetSuiteBundle\Integration\NetSuite;

use Mautic\CoreBundle\Helper\CacheStorageHelper;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuiteApi;
use NetSuite\Classes\ListOrRecordRef;
use NetSuite\Classes\RecordRef;

class FieldHelper {
    /** @var CacheStorageHelper $cache */
    private $cache;

    /** @var NetSuiteApi $netSuiteApi */
    private $netSuiteApi;

    public function __construct(CacheStorageHelper $cache, NetSuiteApi $netSuiteApi)
    {
        $this->cache = $cache;
        $this->netSuiteApi = $netSuiteApi;
    }

    public function prepareReferenceFieldForMautic($value, $field = [], $object = 'contact') {
        if ($value instanceof RecordRef || $value instanceof  ListOrRecordRef) {
            $key = $value->internalId;
            $value = $value->name;
            $this->setCacheValue($field['dv'], $key, $value);
        }

        return $value;
    }

    public function prepareReferenceFieldForNetSuite($value, $field = [], $object = 'contact') {

        if ($value && !$value instanceof RecordRef && !$value instanceof ListOrRecordRef && !empty($field['extra']['selectRecordType'])) {
            $valueMap = $this->getValueMap($field['dv']);
            $index = array_search($value, $valueMap, true);

            $id = null;
            $recordType = $field['extra']['selectRecordType'];

            if ($index !== false) {
                $id = $index;
            } else {
                $id = $this->netSuiteApi->getRecordId($recordType, $value);
                $valueMap[$id] = $value;
                $this->setCacheValue($field['dv'], $id, $value);
            }

            if (!is_null($id)) {
                $value = new ListOrRecordRef();
                $value->typeId = $recordType;
                $value->internalId = $id;
            }
        }
        return $value;
    }

    private function getValueMap($fieldId) {
        $id = 'netsuite.field.' . $fieldId;

        $valueMap = [];
        if ($this->cache->has($id)) {
            $valueMap = $this->cache->get($id);
        }

        return $valueMap;
    }

    private function setCacheValue($fieldId, $key, $value) {
        $id = 'netsuite.field.' . $fieldId;
        $valueMap = $this->getValueMap($fieldId);
        $valueMap[$key] = $value;
        $this->cache->set($id, $valueMap);
    }
}
