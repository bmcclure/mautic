<?php

namespace MauticPlugin\MauticNetSuiteBundle\Api;

require_once('NetSuite/Exception/NetSuiteApiException.php');

use MauticPlugin\MauticCrmBundle\Api\CrmApi;
use MauticPlugin\MauticNetSuiteBundle\Integration\NetSuiteIntegration;
use NetSuite\Classes\AddListRequest;
use NetSuite\Classes\AddListResponse;
use NetSuite\Classes\BooleanCustomFieldRef;
use NetSuite\Classes\Contact;
use NetSuite\Classes\ContactSearchBasic;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\CustomFieldRef;
use NetSuite\Classes\CustomizationFieldType;
use NetSuite\Classes\CustomizationRefList;
use NetSuite\Classes\CustomizationType;
use NetSuite\Classes\DateCustomFieldRef;
use NetSuite\Classes\EntityCustomField;
use NetSuite\Classes\GetCustomizationIdRequest;
use NetSuite\Classes\GetCustomizationIdResult;
use NetSuite\Classes\GetCustomizationType;
use NetSuite\Classes\GetListRequest;
use NetSuite\Classes\LongCustomFieldRef;
use NetSuite\Classes\ReadResponse;
use NetSuite\Classes\ReadResponseList;
use NetSuite\Classes\RecordList;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\SearchDateField;
use NetSuite\Classes\SearchDateFieldOperator;
use NetSuite\Classes\SearchRecordBasic;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchResponse;
use NetSuite\Classes\SearchResult;
use NetSuite\Classes\SearchStringField;
use NetSuite\Classes\SearchStringFieldOperator;
use NetSuite\Classes\Status;
use NetSuite\Classes\StatusDetail;
use NetSuite\Classes\StatusDetailType;
use NetSuite\Classes\StringCustomFieldRef;
use NetSuite\Classes\UpdateListRequest;
use NetSuite\Classes\UpdateListResponse;
use NetSuite\Classes\WriteResponse;
use NetSuite\Classes\WriteResponseList;
use NetSuite\NetSuiteService;

/**
 * Class NetSuiteApi
 *
 * @package MauticPlugin\MauticNetSuiteBundle\Api
 */
class NetSuiteApi extends CrmApi {
    private $apiFields = [];

    /** @var NetSuiteService */
    private $netSuiteService;

    /**
     * @return array
     */
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

    /**
     * @return NetSuiteService
     */
    public function getNetSuiteService() {
        if (!isset($this->netSuiteService)) {
            $config = $this->getNetSuiteConfig();
            $this->netSuiteService = new NetSuiteService($config);
        }

        return $this->netSuiteService;
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
        $recordType = $this->getNetSuiteRecordType($object);

        if (empty($this->apiFields[$recordType])) {
            $service = $this->getNetSuiteService();

            $request = new GetListRequest();
            $request->baseRef = $this->getCustomFieldRefList()->customizationRef;

            $response = $service->getList($request);

            /** @var ReadResponseList $result */
            $result = $response->readResponseList;

            /** @var Status $status */
            $status = $result->status;

            if (!$status->isSuccess) {
                throw new NetSuiteApiException($this->getErrorMessage($status));
            }

            /** @var ReadResponse[] $items */
            $list = $result->readResponse;

            $fields = [];

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

                    $fields[$record->scriptId] = $this->fieldDefinition(
                        $record->scriptId,
                        $record->label,
                        $record->isMandatory,
                        $this->getFieldDataType($record->fieldType)
                    );
                }
            }

            $this->apiFields[$object] = array_merge($this->getDefaultFields($recordType), $fields);
        }

        return $this->apiFields[$object];
    }

    /**
     * @return CustomizationRefList
     *
     * @throws NetSuiteApiException
     */
    private function getCustomFieldRefList() {
        $service = $this->getNetSuiteService();

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
            throw new NetSuiteApiException($this->getErrorMessage($status));
        }

        return $result->customizationRefList;
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
     * @param string $object
     *
     * @return string
     */
    public function getNetSuiteRecordType($object = 'contacts') {
        $map = [
            'company' => 'customer',
            'contacts' => 'contact',
        ];

        return array_key_exists(strtolower($object), $map) ? $map[$object] : $object;
    }

    /**
     * @param string $recordType
     *
     * @return array
     */
    protected function getDefaultFields($recordType) {
        $fields = [];

        if ($recordType === 'customer') {
            $fields = [
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
            ];
        } elseif ($recordType === 'contact') {
            $fields = [
                'comments' => $this->fieldDefinition('comments', 'Comments'),
                'company' => $this->fieldDefinition('company', 'Company', true),
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
            ];
        }

        return $fields;
    }

    /**
     * @param string $id
     * @param string $label
     * @param bool $required
     * @param string $type
     *
     * @return array
     */
    private function fieldDefinition($id, $label, $required = false, $type = NetSuiteIntegration::FIELD_TYPE_STRING) {
        return [
            'type' => $type,
            'label' => $label,
            'required' => $required,
            'dv' => $id,
        ];
    }

    /**
     * @param array $query
     *
     * @return array
     *
     * @throws NetSuiteApiException
     */
    public function getContacts($query) {
        $service = $this->getNetSuiteService();
        $service->setSearchPreferences(false, $query['limit']);
        $search = new ContactSearchBasic();
        $response = $service->search($this->setupSearchRequest($search, $query));
        return $this->handleSearchResponse($response, 'contacts');
    }

    /**
     * @param array $query
     *
     * @return array
     *
     * @throws NetSuiteApiException
     */
    public function getCompanies($query) {
        $service = $this->getNetSuiteService();
        $service->setSearchPreferences(false, 200);
        $search = new CustomerSearchBasic();
        $response = $service->search($this->setupSearchRequest($search, $query));
        return $this->handleSearchResponse($response, 'company');
    }

    /**
     * @param SearchRecordBasic $search
     * @param array $query
     *
     * @return SearchRequest
     *
     * @throws \Exception
     */
    public function setupSearchRequest(SearchRecordBasic $search, array $query) {
        if (empty($query['fetchAll']) && (!empty($query['start']) || !empty($query['end']))) {
            $search->lastModifiedDate = $this->getSearchDateField($query);
        }

        $request = new SearchRequest();
        $request->searchRecord = $search;
        return $request;
    }

    /**
     * @param array $query
     *
     * @return SearchDateField
     *
     * @throws \Exception
     */
    private function getSearchDateField($query) {
        $searchDate = new SearchDateField();

        if (!empty($query['start']) && !empty($query['end'])) {
            $start = new \DateTime($query['start']);
            $end = new \DateTime($query['end']);
            $searchDate->operator = SearchDateFieldOperator::within;
            $searchDate->searchValue = $start->format('Y-m-d\TH:i:s.000-07:00');
            $searchDate->searchValue2 = $end->format('Y-m-d\TH:i:s.000-07:00');
        } elseif (!empty($query['start'])) {
            $start = new \DateTime($query['start']);
            $searchDate->operator = SearchDateFieldOperator::onOrAfter;
            $searchDate->searchValue = $start->format('Y-m-d\TH:i:s.000-07:00');
        } elseif (!empty($query['end'])) {
            $end = new \DateTime($query['end']);
            $searchDate->operator = SearchDateFieldOperator::onOrBefore;
            $searchDate->searchValue = $end->format('Y-m-d\TH:i:s.000-07:00');
        }

        return $searchDate;
    }

    /**
     * @param SearchResponse $response
     * @param string $object
     *
     * @return array
     *
     * @throws NetSuiteApiException
     */
    private function handleSearchResponse(SearchResponse $response, $object) {
        /** @var SearchResult $result */
        $result = $response->searchResult;

        /** @var Status $status */
        $status = $result->status;

        if (!$status->isSuccess) {
            throw new NetSuiteApiException($this->getErrorMessage($status));
        }

        /** @var RecordList $recordList */
        $recordList = $result->recordList;
        $records = !empty($recordList->record) ? $recordList->record : [];

        return $this->mapRecordValues($records, $object);
    }

    private function getErrorMessage(Status $status) {
        /** @var StatusDetail[] $details */
        $details = $status->statusDetail;

        $message = 'NetSuite API error';
        foreach ($details as $detail) {
            if ($detail->type === StatusDetailType::ERROR) {
                $message .= ': ' . $detail->code . ' - ' . $detail->message;
                break;
            }
        }

        return $message;
    }

    /**
     * @param Customer[]|Contact[] $records
     * @param string $object
     *
     * @return array
     */
    private function mapRecordValues(array $records, $object) {
        $fields = $this->integration->getAvailableLeadFields()[$object];

        $items = [];

        /** @var Customer|Contact $record */
        foreach ($records as $record) {
            /** @var CustomFieldList $customFieldList */
            $customFieldList = $record->customFieldList;

            $contact = [
                'netsuite_id' => $record->internalId,
            ];

            foreach (array_keys($fields) as $field) {
                if (property_exists($record, $field)) {
                    $contact[$field] = $record->{$field};
                } else {
                    /** @var CustomFieldRef $customField */
                    foreach ($customFieldList->customField as $customField) {
                        if ($field === $customField->scriptId) {
                            $contact[$field] = property_exists($customField, 'value')
                                ? $customField->value
                                : '';
                            break;
                        }
                    }
                }

            }
            if (!empty($contact)) {
                $items[] = $contact;
            }
        }

        return $items;
    }

    /**
     * @param string $field
     * @param string $value
     *
     * @return array
     */
    public function getCompanyBy($field, $value) {
        return $this->getObjectBy($field, $value, 'company');
    }

    /**
     * @param string $field
     * @param string $value
     *
     * @return array
     */
    public function getContactBy($field, $value) {
        return $this->getObjectBy($field, $value, 'contacts');
    }

    /**
     * @param string $field
     * @param string $value
     * @param string $object
     *
     * @return array
     */
    public function getObjectBy($field, $value, $object) {
        $service = $this->getNetSuiteService();
        $service->setSearchPreferences(false, 1);

        $searchField = new SearchStringField();
        $searchField->operator = SearchStringFieldOperator::is;
        $searchField->searchValue = $value;

        $search = $object === 'company'
            ? new CustomerSearchBasic()
            : new ContactSearchBasic();
        $search->{$field} = $searchField;

        $request = new SearchRequest();
        $request->searchRecord = $search;

        $response = $service->search($request);

        $record = [];

        if ($response->searchResult->status->isSuccess) {
            /** @var SearchResult $result */
            $result = $response->searchResult;

            /** @var RecordList $recordList */
            $recordList = $result->recordList;

            /** @var Customer[]|Contact[] $records */
            $records = $recordList->record;
            $records = [reset($records)];

            $items = $this->mapRecordValues($records, 'company');
            $record = reset($items);
        }

        return $record;
    }

    /**
     * @param array[] $leads
     * @param string $object
     * @param bool $update
     *
     * @return array
     *
     * @throws NetSuiteApiException
     */
    public function createLeads($leads, $object, $update = false) {
        $service = $this->getNetSuiteService();

        $records = [];

        $returnIds = [];

        foreach ($leads as $id => $lead) {
            $record = $object === 'company'
                ? new Customer()
                : new Contact();
            $updateId = $update ? $id : null;
            $mauticId = $update ? null : $id;
            $this->populateRecord($record, $lead, $object, $updateId, $mauticId);
            $records[] = $record;
            $returnIds[$id] = true;
        }

        $request = $update
            ? new UpdateListRequest()
            : new AddListRequest();
        $request->record = $records;

        /** @var UpdateListResponse|AddListResponse $response */
        $response = $update
            ? $service->updateList($request)
            : $service->addList($request);

        /** @var WriteResponseList $responseList */
        $responseList = $response->writeResponseList;
        /** @var Status $status */
        $status = $responseList->status;

        if (!$status->isSuccess) {
            throw new NetSuiteApiException($this->getErrorMessage($status));
        }

        if (!$update) {
            /** @var WriteResponse[] $writeResponses */
            $writeResponses = $responseList->writeResponse;

            $returnIds = [];

            foreach ($writeResponses as $writeResponse) {
                /** @var RecordRef $baseRef */
                $baseRef = $writeResponse->baseRef;
                $returnIds[$baseRef->internalId] = $baseRef->externalId;
            }
        }

        return $returnIds;
    }

    /**
     * @param array[] $leads
     * @param string $object
     *
     * @return array
     *
     * @throws NetSuiteApiException
     */
    public function updateLeads($leads, $object) {
        return $this->createLeads($leads, $object, true);
    }

    /**
     * @param Customer|Contact $record
     * @param array $values
     * @param string $object
     * @param string|null $updateId
     * @param string|null $mauticId
     *
     * @throws NetSuiteApiException
     */
    public function populateRecord($record, $values, $object, $updateId = null, $mauticId = null) {
        if ($updateId) {
            $record->internalId = $updateId;
        }

        if ($mauticId) {
            $record->externalId = $mauticId;
        }

        $fields = $this->getFields($object);
        $recordType = $this->getNetSuiteRecordType($object);
        $defaultFields = $this->getDefaultFields($recordType);

        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $field = $fields[$key];
            $value = $this->preprocessValueForNetSuite($value, $field);

            if (array_key_exists($key, $defaultFields)) {
                $record->{$key} = $value;
            } else {
                $this->addCustomFieldRef($record, $key, $value, $field);
            }
        }
    }

    /**
     * @param mixed $value
     * @param array $field
     *
     * @return mixed
     */
    private function preprocessValueForNetSuite($value, $field) {
        if ($field['type'] === NetSuiteIntegration::FIELD_TYPE_DATETIME) {
            $value = $this->formatNetSuiteDate($value);
        } elseif ($field['type'] === NetSuiteIntegration::FIELD_TYPE_DATE) {
            $value = $this->formatNetSuiteDate($value, false);
        }

        return $value;
    }

    /**
     * @param Customer|Contact$record
     * @param string $key
     * @param mixed $value
     * @param array $field
     */
    private function addCustomFieldRef($record, $key, $value, $field) {
        if ($record->customFieldList instanceof CustomFieldList) {
            $customFieldList = $record->customFieldList;
        } else {
            $customFieldList = new CustomFieldList();
            $customFieldList->customField = [];
        }

        $type = $field['type'];
        $ref = null;

        if ($type === NetSuiteIntegration::FIELD_TYPE_BOOL) {
            $ref = new BooleanCustomFieldRef();
        } elseif ($type === NetSuiteIntegration::FIELD_TYPE_DATE || $type === NetSuiteIntegration::FIELD_TYPE_DATETIME) {
            $ref = new DateCustomFieldRef();
        } elseif ($type === NetSuiteIntegration::FIELD_TYPE_NUMBER) {
            $ref = new LongCustomFieldRef();
        } elseif ($type === NetSuiteIntegration::FIELD_TYPE_STRING) {
            $ref = new StringCustomFieldRef();
        }

        if ($ref !== null) {
            $ref->scriptId = $key;
            $ref->value = $value;
            $customFieldList->customField[] = $ref;
        }

        $record->customFieldList = $customFieldList;
    }

    public function formatNetSuiteDate($date, $includeTime = true) {
        $date = new \DateTime($date);
        $format = 'Y-m-d';
        if ($includeTime) {
            $format .= '\TH:i:s.000-00:00';
        }

        return $date->format($format);
    }
}
