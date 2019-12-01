<?php

namespace MauticPlugin\MauticNetSuiteBundle\Api;

use MauticPlugin\MauticCrmBundle\Api\CrmApi;
use NetSuite\Classes\CrmCustomField;
use NetSuite\Classes\GetAllRecord;
use NetSuite\Classes\GetAllRequest;
use NetSuite\Classes\GetAllResult;
use NetSuite\Classes\RecordList;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\Status;
use NetSuite\NetSuiteService;

class NetSuiteApi extends CrmApi {
    private $apiFields = [];

    /** @var NetSuiteService */
    private $netSuiteService;

    protected function getNetSuiteConfig() {
        $keys = $this->integration->getKeys();

        return [
            'endpoint' => '2019_1',
            'host' => $keys['netsuite_service_url'],
            'account' => $keys['netsuite_account'],
            'consumerKey' => $keys['netsuite_consumer_key'],
            'consumerSecret' => $keys['netsuite_consumer_secret'],
            'token' => $keys['netsuite_token_key'],
            'tokenSecret' => $keys['netsuite_token_secret'],
        ];
    }

    public function getNetSuiteService() {
        if (!isset($this->netSuiteService)) {
            $config = $this->getNetSuiteConfig();
            $this->netSuiteService = new NetSuiteService($config);
        }

        return $this->netSuiteService;
    }

    protected function getNetSuiteRecordType($object = null) {

        // @todo verify mapping
        $map = [
            'contacts' => 'contact',
            'company' => 'customer',
        ];

        return array_key_exists($object, $map) ? $map[$object] : $object;
    }

    /**
     * @param string|null $object
     *
     * @return CrmCustomField[]
     *
     * @throws NetSuiteApiException
     */
    public function getFields($object = null)
    {
        if (empty($this->apiFields[$object])) {
            $recordType = $this->getNetSuiteRecordType($object);
            $service = $this->getNetSuiteService();

            $request = new GetAllRequest();
            $request->record = new GetAllRecord();
            $request->record->recordType = 'crmCustomField';

            $response = $service->getAll($request);

            /** @var GetAllResult $result */
            $result = $response->getAllResult;

            /** @var Status $status */
            $status = $result->status;

            if (!$status->isSuccess) {
                throw new NetSuiteApiException('Unable to retrieve custom fields from NetSuite');
            }

            /** @var RecordList $list */
            $list = $result->recordList;

            $fields = [];

            /** @var CrmCustomField $record */
            foreach ($list->record as $record) {
                /** @var RecordRef $selectRecordType */
                $selectRecordType = $record->selectRecordType;

                if ($selectRecordType->type === $recordType) {
                    $fields[] = $record;
                }
            }

            $this->apiFields[$object] = $fields;
        }

        return $this->apiFields[$object];
    }
}
