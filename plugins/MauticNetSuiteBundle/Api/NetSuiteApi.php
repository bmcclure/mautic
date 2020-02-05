<?php

namespace MauticPlugin\MauticNetSuiteBundle\Api;

require_once('NetSuite/Exception/NetSuiteApiException.php');

use Mautic\LeadBundle\Entity\Lead;
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
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\GetResponse;
use NetSuite\Classes\LongCustomFieldRef;
use NetSuite\Classes\ReadResponse;
use NetSuite\Classes\ReadResponseList;
use NetSuite\Classes\RecordList;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\SearchDateField;
use NetSuite\Classes\SearchDateFieldOperator;
use NetSuite\Classes\SearchMoreWithIdRequest;
use NetSuite\Classes\SearchMoreWithIdResponse;
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
    public static $TIMEZONE = '-0700';

    private $apiFields = [];

    /** @var NetSuiteService */
    private $netSuiteService;

    private $queryCache = [];

    private $recordCache = [];

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
                'entityStatus' => $this->fieldDefinition('entityStatus', 'Status', false, NetSuiteIntegration::FIELD_TYPE_STRING),
            ];
        } elseif ($recordType === 'contact') {
            $fields = [
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
     * @param int $page
     * @param bool $more
     * @param null $searchId
     * @param int $total
     *
     * @return array
     *
     * @throws NetSuiteApiException
     */
    public function getContacts($query, $page = 1, &$more = false, &$searchId = null, &$total = 0) {
        $search = new ContactSearchBasic();
        $response = $this->handleSearchRequest($search, $query, $page, $searchId);
        $data = $this->handleSearchResponse($response, 'contacts', $more, $searchId, $total);
        if (count($data) > $query['limit']) {
            $data = array_slice($data, 0, $query['limit']);
        }
        return $data;
    }

    /**
     * @param array $query
     * @param int $page
     * @param bool $more
     * @param null $searchId
     * @param int $total
     *
     * @return array
     *
     * @throws NetSuiteApiException
     */
    public function getCompanies($query, $page = 1, &$more = false, &$searchId = null, &$total = 0) {
        $search = new CustomerSearchBasic();
        $response = $this->handleSearchRequest($search, $query, $page, $searchId);
        $data = $this->handleSearchResponse($response, 'company', $more, $searchId, $total);
        if (count($data) > $query['limit']) {
            $data = array_slice($data, 0, $query['limit']);
        }
        return $data;
    }

    /**
     * @param SearchRecordBasic $search
     * @param array $query
     * @param int $page
     * @param null $searchId
     *
     * @return SearchMoreWithIdResponse|SearchResponse
     *
     * @throws \Exception
     */
    public function handleSearchRequest(SearchRecordBasic $search, array $query, $page = 1, $searchId = null) {
        $service = $this->getNetSuiteService();

        $limit = $query['limit'];
        $pageSize = 100;
        if ($limit < 5) {
            $pageSize = 5;
        } elseif ($limit > 1000) {
            $pageSize = 1000;
        }

        $service->setSearchPreferences(false, $pageSize);

        if ($page === 1) {
            if (empty($query['fetchAll']) && (!empty($query['start']) || !empty($query['end']))) {
                $search->lastModifiedDate = $this->getSearchDateField($query);
            }

            $request = new SearchRequest();
            $request->searchRecord = $search;
        } else {
            $request = new SearchMoreWithIdRequest();
            $request->searchId = $searchId;
            $request->pageIndex = $page;
        }

        return $page === 1
            ? $service->search($request)
            : $service->searchMoreWithId($request);
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

        $format = 'Y-m-d\TH:i:sP';
        $start = null;
        $end = null;
        if (!empty($query['start'])) {
            $start = new \DateTime($query['start']);
            $start->setTimezone(new \DateTimeZone(self::$TIMEZONE));
            $start = $start->format($format);
        }
        if (!empty($query['end'])) {
            $end = new \DateTime($query['end']);
            $end->setTimezone(new \DateTimeZone(self::$TIMEZONE));
            $end = $end->format($format);
        }

        if (!empty($start) && !empty($end)) {
            $searchDate->operator = SearchDateFieldOperator::within;
            $searchDate->searchValue = $start;
            $searchDate->searchValue2 = $end;
        } elseif (!empty($start)) {
            $searchDate->operator = SearchDateFieldOperator::onOrAfter;
            $searchDate->searchValue = $start;
        } elseif (!empty($end)) {
            $searchDate->operator = SearchDateFieldOperator::onOrBefore;
            $searchDate->searchValue = $end;
        }

        return $searchDate;
    }

    /**
     * @param SearchResponse|SearchMoreWithIdResponse $response
     * @param string $object
     * @param bool $more
     * @param null|string $searchId
     * @param null $total
     *
     * @return array
     *
     * @throws NetSuiteApiException
     */
    private function handleSearchResponse($response, $object, &$more = false, &$searchId = null, &$total = null) {
        /** @var SearchResult $result */
        $result = $response->searchResult;

        $more = ($result->pageIndex < $result->totalPages);
        $searchId = $result->searchId;
        $total = $result->totalRecords;

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
        $settings = [];
        $settings['feature_settings']['objects'] = [$object];
        $fields = $this->integration->getAvailableLeadFields($settings)[$object];

        $items = [];

        /** @var Customer|Contact $record */
        foreach ($records as $record) {
            /** @var CustomFieldList $customFieldList */
            $customFieldList = $record->customFieldList;

            $values = [
                'netsuite_id' => $record->internalId,
            ];

            foreach (array_keys($fields) as $field) {
                if (property_exists($record, $field)) {
                    $value = $record->{$field};

                    if ($value instanceof RecordRef) {
                        $values[$field] = $value->internalId;
                    } else {
                        $values[$field] = $value;
                    }

                } else {
                    /** @var CustomFieldRef $customField */
                    foreach ($customFieldList->customField as $customField) {
                        if ($field === $customField->scriptId) {
                            $values[$field] = property_exists($customField, 'value')
                                ? $customField->value
                                : '';
                            break;
                        }
                    }
                }

            }

            if ($object === 'contacts' && !empty($record->company)) {
                /** @var RecordRef $companyRef */
                $companyRef = $record->company;
                $company = $this->getCompany($companyRef->internalId);

                if ($company) {
                    $values['companyId'] = $company->internalId;
                    $values['company'] = $company->companyName;
                }
            }

            if (!empty($values)) {
                $items[] = $values;
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
     * @param $internalId
     * @return Customer|null
     */
    public function getCompany($internalId) {
        if (!isset($this->recordCache['customer'][$internalId])) {
            $service = $this->getNetSuiteService();

            $ref = new RecordRef();
            $ref->type = 'customer';
            $ref->internalId = $internalId;

            $request = new GetRequest();
            $request->baseRef = $ref;

            /** @var GetResponse $response */
            $response = $service->get($request);

            /** @var ReadResponse $readResponse */
            $readResponse = $response->readResponse;

            /** @var Status $status */
            $status = $readResponse->status;

            $company = null;

            if ($status->isSuccess) {
                $company = $readResponse->record;
            }

            $this->recordCache['customer'][$internalId] = $company;
        }


        return $this->recordCache['customer'][$internalId];
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
        if (!isset($this->queryCache[$object][$field][$value])) {
            $service = $this->getNetSuiteService();
            $service->setSearchPreferences(false, 5);

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
                $records = !empty($records) ? [reset($records)] : [];

                $items = $this->mapRecordValues($records, 'company');
                $record = reset($items);
            }

            $this->queryCache[$object][$field][$value] = $record;
        }

        return $this->queryCache[$object][$field][$value];
    }

    /**
     * @param array $mappedData
     * @param Lead|array $lead
     */
    public function createLead($mappedData, $lead)
    {
        // @todo Implement function
    }

    public function updateLead($mappedData, $integrationEntityId)
    {
        // @todo Implement function
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

        $createRecords = [];
        $updateRecords = [];
        $returnIds = [];

        foreach ($leads as $id => $lead) {
            $record = $object === 'company'
                ? new Customer()
                : new Contact();
            $updateId = $update ? $id : null;
            $mauticId = $update ? null : $id;

            if (!$update) {
                /** @var array $existingRecord */
                $existingRecord = $object === 'company'
                    ? $this->getCompanyBy('companyName', $lead['companyName'])
                    : $this->getContactBy('email', $lead['email']);

                if (!empty($existingRecord)) {
                    $updateId = $existingRecord['netsuite_id'];
                    $mauticId = null;
                }
            }

            $updatedFields = $this->populateRecord($record, $lead, $object, $updateId, $mauticId);

            if (!empty($updatedFields)) {
                if ($updateId) {
                    $updateRecords[] = $record;
                } else {
                    $createRecords[] = $record;
                }

                $returnIds[$id] = true;
            }
        }

        if (!empty($updateRecords)) {
            $request = new UpdateListRequest();
            $request->record = $updateRecords;

            /** @var UpdateListResponse $response */
            $response = $service->updateList($request);

            /** @var WriteResponseList $responseList */
            $responseList = $response->writeResponseList;
            /** @var Status $status */
            $status = $responseList->status;

            if (!$status->isSuccess) {
                throw new NetSuiteApiException($this->getErrorMessage($status));
            }
        }

        if (!empty($createRecords)) {
            $request = new AddListRequest();
            $request->record = $createRecords;

            /** @var AddListResponse $response */
            $response = $service->addList($request);

            /** @var WriteResponseList $responseList */
            $responseList = $response->writeResponseList;
            /** @var Status $status */
            $status = $responseList->status;

            if (!$status->isSuccess) {
                throw new NetSuiteApiException($this->getErrorMessage($status));
            }

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
     * @return int
     * @throws NetSuiteApiException
     */
    public function populateRecord($record, $values, $object, $updateId = null, $mauticId = null) {
        $modifiedFields = 0;

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
            $value = $this->preprocessValueForNetSuite($value, $field, $object);

            if (array_key_exists($key, $defaultFields)) {
                $record->{$key} = $value;
            } else {
                $this->addCustomFieldRef($record, $key, $value, $field);
            }

            ++$modifiedFields;
        }

        return $modifiedFields;
    }

    /**
     * @param mixed $value
     * @param array $field
     * @param string $object
     *
     * @return mixed
     */
    private function preprocessValueForNetSuite($value, $field, $object) {
        if ($field['type'] === NetSuiteIntegration::FIELD_TYPE_DATETIME) {
            $value = $this->formatNetSuiteDate($value);
        } elseif ($field['type'] === NetSuiteIntegration::FIELD_TYPE_DATE) {
            $value = $this->formatNetSuiteDate($value, false);
        }

        $recordRefs = [
            'company' => [
                'entityStatus' => 'customerStatus',
            ]
        ];

        if (isset($recordRefs[$object][$field['dv']])) {
            $recordRef = new RecordRef();
            $recordRef->type = $recordRefs[$object][$field['dv']];
            $recordRef->internalId = $value;
        }

        return $value;
    }

    /**
     * @param Customer|Contact $record
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
        $date->setTimezone(new \DateTimeZone(self::$TIMEZONE));

        $format = 'Y-m-d';
        if ($includeTime) {
            $format .= '\TH:i:sP';
        }

        return $date->format($format);
    }
}
