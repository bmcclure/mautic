<?php

namespace MauticPlugin\MauticNetSuiteBundle\Api;

use MauticPlugin\MauticCrmBundle\Api\CrmApi;
use MauticPlugin\MauticNetSuiteBundle\Integration\NetSuiteIntegration;
use NetSuite\Classes\CrmCustomField;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomizationFieldType;
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

    /**
     * @param string|null $object
     *
     * @return array
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
                    $fields[$record->internalId] = [
                        'type' => $this->getFieldDataType($record->fieldType),
                        'label' => $record->label,
                        'required' => $record->isMandatory,
                    ];
                }
            }

            $this->apiFields[$object] = array_merge($this->getDefaultFields($recordType), $fields);
        }

        return $this->apiFields[$object];
    }

    protected function getFieldDataType($fieldType) {
        $map = [
            CustomizationFieldType::_checkBox => NetSuiteIntegration::FIELD_TYPE_BOOL,
            CustomizationFieldType::_currency => NetSuiteIntegration::FIELD_TYPE_NUMBER,
            CustomizationFieldType::_date => NetSuiteIntegration::FIELD_TYPE_DATE,
            CustomizationFieldType::_datetime => NetSuiteIntegration::FIELD_TYPE_DATETIME,
            CustomizationFieldType::_decimalNumber => NetSuiteIntegration::FIELD_TYPE_NUMBER,
            CustomizationFieldType::_integerNumber => NetSuiteIntegration::FIELD_TYPE_NUMBER,
        ];

        return array_key_exists($fieldType, $map) ? $map[$fieldType] : NetSuiteIntegration::FIELD_TYPE_STRING;
    }

    protected function getNetSuiteRecordType($object = null) {

        $map = [
            'company' => 'customer',
            'contacts' => 'contact',
        ];

        return array_key_exists($object, $map) ? $map[$object] : $object;
    }

    protected function getDefaultFields($recordType) {
        $fields = [];

        if ($recordType === 'customer') {
            $fields = [
                'accountNumber' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Account',
                    'required' => false,
                ],
                'category' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Category',
                    'required' => false,
                ],
                'comments' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Comments',
                    'required' => false,
                ],
                'companyName' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Company Name',
                    'required' => true,
                ],
                'currency' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Currency',
                    'required' => false,
                ],
                'email' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Email',
                    'required' => false,
                ],
                'emailPreference' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Email Preference',
                    'required' => false,
                ],
                'fax' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Fax',
                    'required' => false,
                ],
                'firstName' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'First Name',
                    'required' => false,
                ],
                'firstVisit' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'First Visit',
                    'required' => false,
                ],
                'homePhone' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Home Phone',
                    'required' => false,
                ],
                'keywords' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Keywords',
                    'required' => false,
                ],
                'language' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Language',
                    'required' => false,
                ],
                'lastName' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Last Name',
                    'required' => false,
                ],
                'leadSource' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Lead Source',
                    'required' => false,
                ],
                'middleName' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Middle Name',
                    'required' => false,
                ],
                'mobilePhone' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Mobile Phone',
                    'required' => false,
                ],
                'phone' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Phone',
                    'required' => false,
                ],
                'salutation' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Salutation',
                    'required' => false,
                ],
                'startDate' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_DATE,
                    'label' => 'Start Date',
                    'required' => false,
                ],
                'territory' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Territory',
                    'required' => false,
                ],
                'title' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Title',
                    'required' => false,
                ],
                'url' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'URL',
                    'required' => false,
                ],
                'visits' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_NUMBER,
                    'label' => 'Number of Visits',
                    'required' => false,
                ],
            ];
        } elseif ($recordType === 'contact') {
            $fields = [
                'comments' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Comments',
                    'required' => false,
                ],
                'company' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Company',
                    'required' => true,
                ],
                'contactSource' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Contact Source',
                    'required' => false,
                ],
                'email' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Email',
                    'required' => true,
                ],
                'fax' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Fax',
                    'required' => false,
                ],
                'firstName' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'First Name',
                    'required' => true,
                ],
                'homePhone' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Home Phone',
                    'required' => false,
                ],
                'lastName' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Last Name',
                    'required' => true,
                ],
                'middleName' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Middle Name',
                    'required' => false,
                ],
                'mobilePhone' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Mobile Phone',
                    'required' => false,
                ],
                'officePhone' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Office Phone',
                    'required' => false,
                ],
                'phone' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Phone',
                    'required' => false,
                ],
                'salutation' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Salutation',
                    'required' => false,
                ],
                'title' => [
                    'type' => NetSuiteIntegration::FIELD_TYPE_STRING,
                    'label' => 'Title',
                    'required' => false,
                ],
            ];
        }

        return $fields;
    }
}
