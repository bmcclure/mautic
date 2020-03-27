<?php

namespace MauticPlugin\MauticNetSuiteBundle\Api\NetSuite;

use Mautic\CoreBundle\Helper\CacheStorageHelper;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuite\Exception\NetSuiteApiException;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuiteApi;
use MauticPlugin\MauticNetSuiteBundle\Integration\NetSuiteIntegration;
use NetSuite\Classes\Address;
use NetSuite\Classes\Contact;
use NetSuite\Classes\ContactAddressbook;
use NetSuite\Classes\ContactAddressbookList;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerAddressbook;
use NetSuite\Classes\CustomerAddressbookList;
use NetSuite\Classes\CustomizationFieldType;
use NetSuite\Classes\CustomizationRefList;
use NetSuite\Classes\CustomizationType;
use NetSuite\Classes\CustomRecordRef;
use NetSuite\Classes\CustomRecordType;
use NetSuite\Classes\EntityCustomField;
use NetSuite\Classes\GetCustomizationIdRequest;
use NetSuite\Classes\GetCustomizationIdResult;
use NetSuite\Classes\GetCustomizationType;
use NetSuite\Classes\GetListRequest;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\GetResponse;
use NetSuite\Classes\ListOrRecordRef;
use NetSuite\Classes\ReadResponse;
use NetSuite\Classes\ReadResponseList;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\Status;

class NetSuiteFields
{
    /** @var CacheStorageHelper $cache */
    private $cache;

    private $fields = [];

    /** @var NetSuiteApi $netSuiteApi */
    private $netSuiteApi;

    public function __construct(CacheStorageHelper $cache, NetSuiteApi $netSuiteApi)
    {
        $this->cache = $cache;
        $this->netSuiteApi = $netSuiteApi;
    }

    /**
     * @param string $object
     *
     * @return array
     *
     * @throws NetSuiteApiException
     */
    public function getFields($object = 'contacts')
    {
        $recordType = $this->netSuiteApi->getNetSuiteRecordType($object);

        if (empty($this->fields[$recordType])) {
            $service = $this->netSuiteApi->getNetSuiteService();

            $request = new GetListRequest();
            $request->baseRef = $this->getCustomFieldRefList()->customizationRef;

            $response = $service->getList($request);

            /** @var ReadResponseList $result */
            $result = $response->readResponseList;

            /** @var Status $status */
            $status = $result->status;

            if (!$status->isSuccess) {
                throw new NetSuiteApiException($this->netSuiteApi->getErrorMessage($status));
            }

            /** @var ReadResponse[] $items */
            $list = $result->readResponse;

            $this->fields[$object] = $this->getDefaultFields($recordType);

            /** @var ReadResponse $readResponse */
            foreach ($list as $readResponse) {
                $record = $readResponse->record;

                if ($record instanceof EntityCustomField) {
                    $applies = [
                        'contacts' => $record->appliesToContact,
                        'company' => $record->appliesToCustomer,
                    ];

                    if ($record->isFormula || !$applies[$object]) {
                        continue;
                    }

                    $referenceTypes = [
                        RecordRef::class,
                        ListOrRecordRef::class,
                        CustomRecordRef::class
                    ];

                    $selectRecordType = null;
                    foreach ($referenceTypes as $referenceType) {
                        if ($record->selectRecordType instanceof $referenceType) {
                            $selectRecordType = $this->getRecordType($record->selectRecordType->internalId);
                            break;
                        }
                    }

                    $this->fields[$object][$record->scriptId] = $this->fieldDefinition(
                        $record->scriptId,
                        $record->label,
                        $record->isMandatory,
                        $this->getFieldDataType($record->fieldType),
                        $selectRecordType
                    );
                }
            }
        }

        return $this->fields[$object];
    }

    /**
     * @return CustomizationRefList
     *
     * @throws NetSuiteApiException
     */
    private function getCustomFieldRefList() {
        $service = $this->netSuiteApi->getNetSuiteService();

        $customizationType = new CustomizationType();
        $customizationType->getCustomizationType = GetCustomizationType::entityCustomField;

        $request = new GetCustomizationIdRequest();
        $request->customizationType = $customizationType;
        $request->includeInactives = false;

        $response = $service->getCustomizationId($request);

        /** @var GetCustomizationIdResult $result */
        $result = $response->getCustomizationIdResult;

        /** @var Status $status */
        $status = $result->status;

        if (!$status->isSuccess) {
            throw new NetSuiteApiException($this->netSuiteApi->getErrorMessage($status));
        }

        return $result->customizationRefList;
    }

    /**
     * @param string $recordType
     *
     * @return array
     */
    public function getDefaultFields($recordType) {
        $recordType = $this->netSuiteApi->getNetSuiteRecordType($recordType);

        $defaultFields = [];

        if ($recordType === 'customer') {
            $defaultFields = [
                'accountNumber' => $this->fieldDefinition('accountNumber', 'Account'),
                'category' => $this->fieldDefinition('category', 'Category'),
                'comments' => $this->fieldDefinition('comments', 'Comments'),
                'companyName' => $this->fieldDefinition('companyName', 'Company Name', true),
                'currency' => $this->fieldDefinition('currency', 'Currency'),
                'email' => $this->fieldDefinition('email', 'Email'),
                'emailPreference' => $this->fieldDefinition('emailPreference', 'Email Preference'),
                'fax' => $this->fieldDefinition('fax', 'Fax'),
                'firstName' => $this->fieldDefinition('firstName', 'First Name'),
                'firstVisit' => $this->fieldDefinition('firstVisit', 'First Visit', false, NetSuiteIntegration::FIELD_TYPE_DATETIME),
                'homePhone' => $this->fieldDefinition('homePhone', 'Home Phone'),
                'keywords' => $this->fieldDefinition('keywords', 'Keywords'),
                'language' => $this->fieldDefinition('language', 'Language'),
                'lastName' => $this->fieldDefinition('lastName', 'Last Name'),
                'leadSource' => $this->fieldDefinition('leadSource', 'Lead Source'),
                'middleName' => $this->fieldDefinition('middleName', 'Middle Name'),
                'mobilePhone' => $this->fieldDefinition('mobilePhone', 'Mobile Phone'),
                'phone' => $this->fieldDefinition('phone', 'Phone'),
                'salutation' => $this->fieldDefinition('salutation', 'Salutation'),
                'startDate' => $this->fieldDefinition('startDate', 'Start Date', false, NetSuiteIntegration::FIELD_TYPE_DATE),
                'territory' => $this->fieldDefinition('territory', 'Territory'),
                'title' => $this->fieldDefinition('title', 'Title'),
                'url' => $this->fieldDefinition('url', 'URL'),
                'visits' => $this->fieldDefinition('visits', 'Visits', false, NetSuiteIntegration::FIELD_TYPE_NUMBER),
                'entityStatus' => $this->fieldDefinition('entityStatus', 'Status', false, NetSuiteIntegration::FIELD_TYPE_STRING),
            ];
        } elseif ($recordType === 'contact') {
            $defaultFields = [
                'comments' => $this->fieldDefinition('comments', 'Comments'),
                //'company' => $this->fieldDefinition('company', 'Company', true),
                'contactSource' => $this->fieldDefinition('contactSource', 'Contact Source'),
                'dateCreated' => $this->fieldDefinition('dateCreated', 'Date Created', false, NetSuiteIntegration::FIELD_TYPE_DATETIME),
                'email' => $this->fieldDefinition('email', 'Email', true),
                'fax' => $this->fieldDefinition('fax', 'Fax'),
                'firstName' => $this->fieldDefinition('firstName', 'First Name', true),
                'homePhone' => $this->fieldDefinition('homePhone', 'Home Phone'),
                'lastName' => $this->fieldDefinition('lastName', 'Last Name', true),
                'middleName' => $this->fieldDefinition('middleName', 'Middle Name'),
                'mobilePhone' => $this->fieldDefinition('mobilePhone', 'Mobile Phone'),
                'officePhone' => $this->fieldDefinition('officePhone', 'Office Phone'),
                'phone' => $this->fieldDefinition('phone', 'Phone'),
                'salutation' => $this->fieldDefinition('salutation', 'Salutation'),
                'title' => $this->fieldDefinition('title', 'Title'),
                'address_country' => $this->addressFieldDefinition('country', 'Country', false, 'country'),
                'address_attention' => $this->addressFieldDefinition('attention', 'Attention'),
                'address_addressee' => $this->addressFieldDefinition('addressee', 'Addressee'),
                'address_addrPhone' => $this->addressFieldDefinition('addressee', 'Address Phone'),
                'address_addr1' => $this->addressFieldDefinition('addr1', 'Street Address 1'),
                'address_addr2' => $this->addressFieldDefinition('addr2', 'Street Address 2'),
                'address_addr3' => $this->addressFieldDefinition('addr3', 'Street Address 3'),
                'address_city' => $this->addressFieldDefinition('city', 'City'),
                'address_state' => $this->addressFieldDefinition('state', 'State'),
                'address_zip' => $this->addressFieldDefinition('zip', 'Zip'),
            ];
        }

        return $defaultFields;
    }

    private function getRecordType($recordTypeId) {
        $recordTypes = $this->cache->get('netsuite_record_types');
        $updateCache = false;

        if (empty($recordTypes)) {
            $recordTypes = [
                -112 => 'account',
                -105 => 'accountingperiod',
                -242 => 'bin',
                -22 => 'phonecall',
                -24 => 'campaign',
                -23 => 'supportcase',
                -101 => 'classification',
                -108 => 'competitor',
                -6 => 'contact',
                -2 => 'customer',
                -109 => 'customercategory',
                -102 => 'department',
                -120 => 'emailtemplate',
                -4 => 'employee',
                -111 => 'employeetype',
                -104 => 'customerstatus',
                -20 => 'calendarevent',
                -26 => 'issue',
                -10 => 'item',
                -7 => 'job',
                -103 => 'location',
                -31 => 'opportunity',
                -5 => 'partner',
                -115 => 'issueproduct',
                -113 => 'issueproductversion',
                -118 => 'role',
                -117 => 'subsidiary',
                -21 => 'task',
                -30 => 'transaction',
                -3 => 'vendor',
                -110 => 'vendorcategory',
            ];
        }

        if (!array_key_exists($recordTypeId, $recordTypes)) {
            if ($recordTypeId <= -1) {
                throw new NetSuiteApiException('Unknown record type ' . $recordTypeId);
            }

            $service = $this->netSuiteApi->getNetSuiteService();
            $ref = new RecordRef();
            $ref->internalId = $recordTypeId;
            $ref->type = 'customRecordType';
            $request = new GetRequest();
            $request->baseRef = $ref;
            /** @var GetResponse $response */
            $response = $service->get($request);

            /** @var ReadResponse $readResponse */
            $readResponse = $response->readResponse;

            /** @var Status $status */
            $status = $readResponse->status;

            if ($status->isSuccess) {
                /** @var CustomRecordType $record */
                $record = $readResponse->record;
                $recordTypes[$recordTypeId] = $record->scriptId;
                $updateCache = true;
            }
        }

        if ($updateCache) {
            $this->cache->set('netsuite_record_types', $recordTypes);
        }

        return $recordTypes[$recordTypeId];
    }

    /**
     * @param string $fieldType
     *
     * @return string
     */
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

    /**
     * @param string $id
     * @param string $label
     * @param bool $required
     * @param string $type
     * @param string|null $selectRecordType
     *
     * @return array
     */
    private function fieldDefinition($id, $label, $required = false, $type = NetSuiteIntegration::FIELD_TYPE_STRING, $selectRecordType = null) {
        return [
            'type' => $type,
            'label' => $label,
            'required' => $required,
            'dv' => $id,
            'extra' => [
                'selectRecordType' => $selectRecordType,
            ]
        ];
    }

    private function addressFieldDefinition($addressProperty, $label, $required = false, $type = NetSuiteIntegration::FIELD_TYPE_STRING) {
        return [
            'type' => $type,
            'label' => $label,
            'required' => $required,
            'dv' => 'defaultAddress',
            'extra' => [
                'address_property' => $addressProperty,
                'selectRecordType' => 'address',
            ]
        ];
    }

    /**
     * @param Contact|Customer $record
     * @param $object
     * @param bool $create
     *
     * @return Address|null
     */
    public function getAddressRecord($record, $object, $create = false) {
        /** @var ContactAddressbookList|CustomerAddressbookList $addressBookList */
        $addressBookList = $record->addressbookList;

        $addressBooks = $addressBookList->addressbook;

        $address = null;

        if (!isset($addressBooks) && $create) {
            $addressBook = ($object === 'contacts')
                ? new ContactAddressbook()
                : new CustomerAddressbook();
            $address = new Address();
            $addressBook->addressbookAddress = $address;
            $addressBooks[] = $addressBook;
        }

        if (empty($address) && !empty($addressBooks)) {
            /** @var ContactAddressbook|CustomerAddressbook $addressBook */
            $addressBook = reset($addressBooks);

            /** @var Address $address */
            $address = $addressBook->addressbookAddress;
        }

        return $address;
    }
}
