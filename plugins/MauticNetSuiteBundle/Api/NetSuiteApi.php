<?php

namespace MauticPlugin\MauticNetSuiteBundle\Api;

require_once('NetSuite/Exception/NetSuiteApiException.php');
require_once('NetSuite/NetSuiteFields.php');
require_once('NetSuite/NetSuiteCountries.php');
require_once('NetSuite/NetSuiteStates.php');

use Exception;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticCrmBundle\Api\CrmApi;
use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuite\Exception\NetSuiteApiException;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuite\NetSuiteCountries;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuite\NetSuiteFields;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuite\NetSuiteStates;
use MauticPlugin\MauticNetSuiteBundle\Integration\NetSuite\FieldHelper;
use MauticPlugin\MauticNetSuiteBundle\Integration\NetSuiteIntegration;
use NetSuite\Classes\AddListRequest;
use NetSuite\Classes\AddListResponse;
use NetSuite\Classes\Address;
use NetSuite\Classes\BooleanCustomFieldRef;
use NetSuite\Classes\Contact;
use NetSuite\Classes\ContactSearchBasic;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\CustomFieldRef;
use NetSuite\Classes\CustomizationFieldType;
use NetSuite\Classes\CustomRecord;
use NetSuite\Classes\CustomRecordRef;
use NetSuite\Classes\CustomRecordSearchBasic;
use NetSuite\Classes\DateCustomFieldRef;
use NetSuite\Classes\GetListRequest;
use NetSuite\Classes\GetListResponse;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\GetResponse;
use NetSuite\Classes\ListOrRecordRef;
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
use NetSuite\Classes\SelectCustomFieldRef;
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
    public static $EVENT_TYPE = 'customrecord_jc_mautic_event';
    public static $EVENT_FIELD_MAP = [
        'custrecord_jc_mautic_event_date' => [
            'mauticId' => 'dateAdded',
            'internalId' => 1256,
            'type' => CustomizationFieldType::_datetime,
        ],
        'custrecord_jc_mautic_event_contact' => [
            'mauticId' => 'contactId',
            'internalId' => 1258,
            'type' => CustomizationFieldType::_listRecord,
        ],
        'custrecord_jc_mautic_event_desc' => [
            'mauticId' => 'description',
            'internalId' => 1259,
            'type' => CustomizationFieldType::_longText,
        ],
        'custrecord_jc_mautic_event_url' => [
            'mauticId' => 'leadUrl',
            'internalId' => 1260,
            'type' => CustomizationFieldType::_hyperlink,
        ],
        'custrecord_jc_mautic_event_id' => [
            'mauticId' => 'id',
            'internalId' => 1261,
            'type' => CustomizationFieldType::_freeFormText,
        ],
        'name' => 'name',
    ];

    private $fields;
    private $fieldHelper;

    /** @var NetSuiteService */
    private $netSuiteService;

    private $queryCache = [];

    private $recordCache = [];

    public function __construct(CrmAbstractIntegration $integration)
    {
        parent::__construct($integration);
        $this->fields = new NetSuiteFields($integration->getCache(), $this);
        $this->fieldHelper = new FieldHelper($this->integration->getCache(), $this);
    }

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
        return $this->fields->getFields($object);
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
     * @throws Exception
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
     * @throws Exception
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

    public function getErrorMessage(Status $status) {
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
        $leadFields = $this->integration->getAvailableLeadFields($settings)[$object];

        $items = [];

        /** @var Customer|Contact $record */
        foreach ($records as $record) {
            /** @var CustomFieldList $customFieldList */
            $customFieldList = $record->customFieldList;

            $values = [
                'netsuite_id' => $record->internalId,
            ];

            $address = $this->fields->getAddressRecord($record, $object);

            foreach ($leadFields as $field => $fieldConfig) {
                if (property_exists($record, $field)) {
                    $values[$field] = $record->{$field};
                } elseif (!empty($fieldConfig['extra']['address_property'])) {
                    $prop = $fieldConfig['extra']['address_property'];
                    $values[$field] = $address instanceof Address ? $address->{$prop} : '';
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

                if (isset($values[$field])) {
                    $values[$field] = $this->preprocessValueForMautic($values[$field], $fieldConfig, $object);
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
     */
    public function populateRecord($record, $values, $object, $updateId = null, $mauticId = null) {
        $modifiedFields = 0;

        if ($updateId) {
            $record->internalId = $updateId;
        }

        if ($mauticId) {
            $record->externalId = $mauticId;
        }

        $settings = [];
        $settings['feature_settings']['objects'] = [$object];
        $leadFields = $this->integration->getAvailableLeadFields($settings);
        $recordType = $this->getNetSuiteRecordType($object);
        $defaultFields = $this->fields->getDefaultFields($recordType);
        $address = $this->fields->getAddressRecord($record, $object, true);

        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $leadFields)) {
                continue;
            }

            $field = $leadFields[$key];

            $value = $this->preprocessValueForNetSuite($value, $field, $object);

            if (array_key_exists($key, $defaultFields)) {
                if (!empty($field['extra']['address_component'])) {
                    $component = $field['extra']['address_component'];
                    if ($address instanceof Address) {
                        $address->{$component} = $value;
                    }
                } else {
                    $record->{$key} = $value;
                }
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
        } elseif ($field['type'] === 'country') {
            $value = NetSuiteCountries::convertToNetSuite($value);
        } elseif ($field['type'] === 'state') {
            $value = NetSuiteStates::convertToNetSuite($value);
        } elseif ($value instanceof ListOrRecordRef) {
            $value = $this->fieldHelper->prepareReferenceFieldForNetSuite($value, $field, $object);
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

    private function preprocessValueForMautic($value, $field, $object) {
        if ($field['type'] === 'country') {
            $value = NetSuiteCountries::convertToMautic($value);
        }

        if ($field['type'] === 'state') {
            $value = NetSuiteStates::convertToMautic($value);
        }

        if ($value instanceof RecordRef || $value instanceof ListOrRecordRef || $value instanceof CustomRecordRef) {
            $value = $this->fieldHelper->prepareReferenceFieldForMautic($value, $field, $object);
        }

        return $value;
    }

    /**
     * @param Customer|Contact|CustomRecord $record
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

        if ($type === NetSuiteIntegration::FIELD_TYPE_BOOL || $type === CustomizationFieldType::_checkBox) {
            $ref = new BooleanCustomFieldRef();
        } elseif ($type === NetSuiteIntegration::FIELD_TYPE_DATE || $type === NetSuiteIntegration::FIELD_TYPE_DATETIME || $type === CustomizationFieldType::_date || $type === CustomizationFieldType::_datetime) {
            $ref = new DateCustomFieldRef();
        } elseif ($type === NetSuiteIntegration::FIELD_TYPE_NUMBER || $type === CustomizationFieldType::_decimalNumber) {
            $ref = new LongCustomFieldRef();
        } elseif ($type === NetSuiteIntegration::FIELD_TYPE_STRING || $type === CustomizationFieldType::_freeFormText) {
            $ref = new StringCustomFieldRef();
        } elseif ($type === CustomizationFieldType::_listRecord) {
            $ref = new SelectCustomFieldRef();
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

    public function getRecordId($recordType, $name) {
        $service = $this->getNetSuiteService();

        $searchRecord = new CustomRecordSearchBasic();
        $searchRecord->name = $name;
        $searchRecord->recType = $recordType;

        $request = new SearchRequest();
        $request->searchRecord = $searchRecord;

        /** @var SearchResponse $response */
        $response = $service->search($request);

        /** @var SearchResult $searchResult */
        $searchResult = $response->searchResult;

        /** @var Status $status */
        $status = $searchResult->status;

        $id = null;

        if ($status->isSuccess && $searchResult->totalRecords > 0) {
            /** @var RecordList $recordList */
            $recordList = $searchResult->recordList;
            $records = $recordList->record;
            /** @var CustomRecord $record */
            $record = reset($records);
            $id = $record->internalId;
        }

        return $id;
    }

    public function createLeadActivity(array $activity, $object) {
        if (!empty($activity)) {
            $nsRecords = $this->getNsActivityRecords($activity);
            $ids = array_keys($nsRecords);
            $exists = $this->recordsExist(self::$EVENT_TYPE, 'externalId', $ids);
            $idsToCreate = array_filter($exists);
            $nsRecords = array_intersect_key($nsRecords, $idsToCreate);
            $this->sendActivityToNetSuite($nsRecords);
        }
    }

    private function getNsActivityRecords(array $activity) {
        $nsRecords = [];

        foreach ($activity as $contactId => $records) {
            foreach ($records as $record) {
                $nsRecord = [];

                foreach (self::$EVENT_FIELD_MAP as $nsField => $mauticField) {
                    $nsRecord[$nsField] = $this->getActivityValue($contactId, $record, $mauticField['mauticId']);
                }

                $nsRecords[$record['id']] = $nsRecord;
            }
        }

        return $nsRecords;
    }

    private function getActivityValue($contactId, $record, $field) {
        if ($field === 'contactId') {
            $value = $this->getInternalId($contactId, 'contacts');
        } else {
            $value = $record[$field];
        }

        return $value;
    }

    public function recordsExist($recordType, $idField, $ids) {
        $service = $this->getNetSuiteService();

        $references = [];

        $exists = [];

        foreach ($ids as $id) {
            $baseRef = new RecordRef();
            $baseRef->type = $recordType;
            $baseRef->{$idField} = $id;
            $references[] = $baseRef;
            $exists[$id] = false;
        }

        $request = new GetListRequest();
        $request->baseRef = $references;

        /** @var GetListResponse $response */
        $response = $service->getList($request);

        /** @var ReadResponseList $responseList */
        $responseList = $response->readResponseList;

        /** @var Status $responseStatus */
        $responseStatus = $responseList->status;

        if (!$responseStatus->isSuccess) {
            /** @var StatusDetail $statusDetail */
            $statusDetail = reset($responseStatus->statusDetail);
            throw new NetSuiteApiException($statusDetail->message, (int) $statusDetail->code);
        }

        /** @var ReadResponse $readResponse */
        foreach ($responseList->readResponse as $readResponse) {
            /** @var Status $recordStatus */
            $recordStatus = $readResponse->status;
            if ($recordStatus->isSuccess) {
                /** @var CustomRecord $record */
                $record = $readResponse->record;
                if (!empty($record->externalId)) {
                    $exists[$record->externalId] = true;
                }
            }
        }

        return $exists;
    }

    private function sendActivityToNetSuite(array $records) {
        $service = $this->getNetSuiteService();

        $requestRecords = [];

        foreach ($records as $id => $values) {
            $record = new CustomRecord();
            $record->externalId = $id;
            $record->name = $values['name'];

            foreach ($values as $key => $value) {
                if ($key === 'name') {
                    continue;
                }

                $field = self::$EVENT_FIELD_MAP[$key];
                $value = $this->getCustomFieldValue($field, $value);
                if ($value) {
                    $this->addCustomFieldRef($record, $key, $value, $field);
                }
            }

            $requestRecords[] = $record;
        }

        $request = new AddListRequest();
        $request->record = $requestRecords;

        $service->addList($request);
    }

    private function getCustomFieldValue(array $field, $value) {
        if ($field['type'] === CustomizationFieldType::_listRecord) {
            $internalId = $value;
            $value = new ListOrRecordRef();
            $value->typeId = -6; // contact
            $value->internalId = $internalId;
        }

        return $value;
    }

    private function getInternalId($mauticId, $object = 'contacts') {
        $internalId = null;

        if ($object === 'contacts') {
            $repo = $this->integration->getIntegrationEntityRepository();
            $integrationId = $repo->getIntegrationsEntityId($this->integration->getName(), $object, 'lead', $mauticId);

            if (!empty($integrationId)) {
                $internalId = $integrationId[0]['integration_entity_id'];
            }
        }

        return $internalId;
    }
}
