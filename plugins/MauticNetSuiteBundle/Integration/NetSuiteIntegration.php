<?php

namespace MauticPlugin\MauticNetSuiteBundle\Integration;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'NetSuite' . DIRECTORY_SEPARATOR . 'ProgressUpdater.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'NetSuite' . DIRECTORY_SEPARATOR . 'FieldHelper.php';

use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\IdentifyCompanyHelper;
use Mautic\PluginBundle\Entity\IntegrationEntity;
use Mautic\PluginBundle\Entity\IntegrationEntityRepository;
use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuiteApi;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuite\Exception\NetSuiteApiException;
use MauticPlugin\MauticNetSuiteBundle\Integration\NetSuite\ProgressUpdater;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class NetSuiteIntegration extends CrmAbstractIntegration {

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'NetSuite';
    }

    /**
     * {@inheritDoc}
     */
    public function getApiHelper()
    {
        if (empty($this->helper)) {
            $this->helper = new NetSuiteApi($this);
        }

        return $this->helper;
    }

    public function getRequiredKeyFields()
    {
        return [
            'netsuite_service_url' => 'mautic.netsuite.form.service_url',
            'netsuite_account' => 'mautic.netsuite.form.account',
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
    public function getSupportedFeatures()
    {
        return ['get_leads', 'push_leads']; // @todo add 'push_lead'
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
    public function appendToForm(&$builder, $data, $formArea)
    {
        if ($formArea === 'features') {
            $builder->add(
                'updateBlanks',
                'choice',
                [
                    'choices' => [
                        'updateBlanks' => 'mautic.integrations.blanks',
                    ],
                    'expanded'    => true,
                    'multiple'    => true,
                    'label'       => 'mautic.integrations.form.blanks',
                    'label_attr'  => ['class' => 'control-label'],
                    'empty_value' => false,
                    'required'    => false,
                ]
            );
            $builder->add(
                'objects',
                'choice',
                [
                    'choices' => [
                        'contacts'  => 'mautic.netsuite.object.contact',
                        'company'  => 'mautic.netsuite.object.company',
                    ],
                    'expanded'    => true,
                    'multiple'    => true,
                    'label'       => 'mautic.netsuite.form.objects_to_pull_from',
                    'label_attr'  => ['class' => ''],
                    'empty_value' => false,
                    'required'    => false,
                ]
            );

            $builder->add(
                'activityEvents',
                ChoiceType::class,
                [
                    'choices'    => $this->leadModel->getEngagementTypes(),
                    'label'      => 'mautic.netsuite.form.activity_included_events',
                    'label_attr' => [
                        'class'       => 'control-label',
                        'data-toggle' => 'tooltip',
                        'title'       => $this->translator->trans('mautic.netsuite.form.activity.events.tooltip'),
                    ],
                    'multiple'   => true,
                    'empty_data' => ['point.gained', 'form.submitted', 'email.read'],
                    'required'   => false,
                ]
            );
        }
    }

    /**
    * {@inheritDoc}
     */
    public function sortFieldsAlphabetically()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getDataPriority()
    {
        return true;
    }

    public function getFormCompanyFields($settings = [])
    {
        return $this->getFormFieldsByObject('company', $settings);
    }

    public function getFormLeadFields($settings = [])
    {
        return $this->getFormFieldsByObject('contacts', $settings);
    }

    /**
     * {@inheritDoc}
     *
     * @param array $settings
     * @return array|bool
     * @throws NetSuiteApiException
     */
    public function getAvailableLeadFields($settings = [], $ignoreCache = false)
    {
        $silenceExceptions = (isset($settings['silence_exceptions'])) ? $settings['silence_exceptions'] : true;
        $fields = [];

        if (isset($settings['feature_settings']['objects'])) {
            $objects = $settings['feature_settings']['objects'];
        } else {
            $settings = $this->settings->getFeatureSettings();
            $objects = isset($settings['objects']) ? $settings['objects'] : ['contacts', 'company'];
        }

        if (!empty($objects) && $this->isAuthorized()) {
            foreach ($objects as $object) {
                $settings['cache_suffix'] = '.' . $object;
                //$settings['ignore_field_cache'] = $ignoreCache; // @todo add back later
                $fields[$object] = parent::getAvailableLeadFields($settings);

                if (empty($fields[$object])) {
                    /** @var NetSuiteApi $apiHelper */
                    $apiHelper = $this->getApiHelper();

                    try {
                        $fields[$object] = $apiHelper->getFields($object);
                        $this->cache->set('leadFields' . $settings['cache_suffix'], $fields[$object]);
                    } catch (NetSuiteApiException $exception) {
                        $this->logIntegrationError($exception);
                        if (!$silenceExceptions) {
                            throw $exception;
                        }

                        return false;
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * {@inheritDoc}
     */
    public function populateLeadData($lead, $config = [], $object = 'contacts')
    {
        /** @var NetSuiteApi $apiHelper */
        $apiHelper = $this->getApiHelper();
        $config['object'] = $apiHelper->getNetSuiteRecordType($object);

        return parent::populateLeadData($lead, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function getLeads($params = [], $query = null, &$executed = null, $result = [], $object = 'contacts')
    {
        return $this->getRecords($params, $query, $executed, $result, $object);
    }

    /**
     * {@inheritDoc}
     */
    public function getCompanies($params = [], $query = null, $executed = null)
    {
        return $this->getRecords($params, $query, $executed, [], 'company');
    }

    /**
     * @param array $params
     *
     * @return mixed
     */
    public function pushLeads($params = [])
    {
        return $this->pushRecords($params, 'contacts');
    }

    /**
     * @param array $params
     *
     * @return mixed
     */
    public function pushCompanies($params = [])
    {
        return $this->pushRecords($params, 'company');
    }

    public function getFetchQuery($config)
    {
        return $config;
    }

    /**
     * @param array $params
     * @param null|array $query
     * @param null|array $executed
     * @param array $result
     * @param string $object
     * @return array|null
     */
    private function getRecords($params = [], $query = null, &$executed = null, $result = [], $object = 'contacts') {
        $object = strtolower($object);

        if ($object === 'contact') {
            $object = 'contacts';
        }

        $this->normalizeParams($params);

        if (!$query) {
            $query = $this->getFetchQuery($params);
        }

        if (!is_array($executed)) {
            $executed = [0 => 0, 1 => 0];
        }

        try {
            if ($this->isAuthorized()) {
                $progress = false;
                $more = true;
                $searchId = null;
                $page = 1;
                $limit = $params['limit'] ? (int) $params['limit'] : 0;
                $total = $limit;
                $processed = 0;
                while ($more) {
                    $query['limit'] = ($limit && $processed + 100 > $limit) ? $limit - $processed : 100;

                    $data = $object === 'company'
                        ? $this->getApiHelper()->getCompanies($query, $page, $more, $searchId, $total)
                        : $this->getApiHelper()->getContacts($query, $page, $more, $searchId, $total);

                    if (!$progress) {
                        $progressTotal = ($limit && $total > $limit) ? $limit : $total;
                        $progress = new ProgressBar($params['output'], $progressTotal);
                        $progress->start();
                        $params['progress'] = $progress;
                    }

                    if (empty($data)) {
                        break;
                    }

                    list($updated, $created) = $this->amendLeadDataBeforeMauticPopulate($data, $object, $params);
                    $executed[0] += $updated;
                    $executed[1] += $created;

                    ++$page;
                    $processed  += count($data);

                    if ($limit > 0 && $processed >= $limit) {
                        break;
                    }
                }

                if (isset($params['output']) && $params['output']->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) {
                    $progress->finish();
                }
            }
        } catch (\Exception $exception) {
            $this->logIntegrationError($exception);
        }

        return $executed;
    }

    public function amendLeadDataBeforeMauticPopulate($data, $object, $params = [])
    {
        $updated = 0;
        $created = 0;
        $entity = null;
        $mauticObjectReference = $object === 'company' ? 'company' : 'lead';

        $config = $this->mergeConfigToFeatureSettings();

        if (!in_array($object, ['company', 'contacts'])) {
            throw new NetSuiteApiException('Unsupported object type.');
        }

        if (!empty($data)) {
            $entity = null;
            /** @var IntegrationEntityRepository $integrationEntityRepo */
            $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
            $integrationEntities = [];

            foreach ($data as $entityData) {
                if (isset($params['progress'])) {
                    $params['progress']->advance();
                }

                if (!empty($entityData['email'])) {
                    $entityData['email'] = InputHelper::email($entityData['email']);
                }

                if (!empty($entityData['dateCreated'])) {
                    $entityData['dateCreated'] = $this->formatDateForMautic($entityData['dateCreated']);
                }

                $isModified = false;
                $recordId = $entityData['netsuite_id'];
                $integrationId = $integrationEntityRepo->getIntegrationsEntityId($this->getName(), $object, $mauticObjectReference, null, null, null, false, 0, 0, $recordId);

                if (count($integrationId)) {
                    $model = $object === 'company' ? $this->companyModel : $this->leadModel;
                    $entity = $model->getEntity($integrationId[0]['internal_entity_id']);
                    $matchedFields = $this->populateMauticLeadData($entityData, $config, $object);

                    print_r($matchedFields);
                    $priorityObject = $object === 'company' ? 'mautic_company' : 'mautic';
                    $fieldsToUpdateInMautic = $this->getPriorityFieldsForMautic($config, $object, $priorityObject);

                    if (!empty($fieldsToUpdateInMautic)) {
                        $configFields = $object === 'company' ? $config['companyFields'] : $config['leadFields'];
                        $fieldsToUpdateInMautic = array_intersect_key($configFields, array_flip($fieldsToUpdateInMautic));
                        $newMatchedFields = array_intersect_key($matchedFields, array_flip($fieldsToUpdateInMautic));
                    } else {
                        $newMatchedFields = $matchedFields;
                    }

                    // Update values for empty fields
                    foreach ($matchedFields as $field => $value) {
                        if (empty($entity->getFieldValue($field))) {
                            $newMatchedFields[$field] = $value;
                        }
                    }

                    // remove unchanged fields
                    foreach ($newMatchedFields as $key => $value) {
                        if ($entity->getFieldValue($key) === $value) {
                            unset($newMatchedFields[$key]);
                        }
                    }

                    print_r($newMatchedFields);

                    if (count($newMatchedFields)) {
                        $model->setFieldValues($entity, $newMatchedFields, false, false);
                        $model->saveEntity($entity, false);
                        $isModified = true;
                    }
                } else {
                    $entity = $object === 'company'
                        ? $this->getMauticCompany($entityData, $object)
                        : $this->getMauticLead($entityData);
                }

                if ($entity) {
                    if ($object !== 'company' && !empty($entityData['company'])) {
                        $company = IdentifyCompanyHelper::identifyLeadsCompany(
                            ['company' => $entityData['company']],
                            null,
                            $this->companyModel
                        );

                        if (!empty($company[2])) {
                            $this->companyModel->addLeadToCompany($company[2], $entity);
                            $this->em->detach($company[2]);
                        }
                    }

                    if (method_exists($entity, 'isNewlyCreated') && $entity->isNewlyCreated()) {
                        ++$created;
                    } else {
                        ++$updated;
                    }

                    $integrationId = $integrationEntityRepo->getIntegrationsEntityId($this->getName(), $object, $mauticObjectReference, $entity->getId());

                    if (count($integrationId) === 0) {
                        $integrationEntity = new IntegrationEntity();
                        $integrationEntity
                            ->setDateAdded(new \DateTime())
                            ->setIntegration($this->getName())
                            ->setIntegrationEntity($object)
                            ->setIntegrationEntityId($recordId)
                            ->setInternalEntity($mauticObjectReference)
                            ->setInternalEntityId($entity->getId());
                        $integrationEntities[] = $integrationEntity;
                    } elseif ($isModified) {
                        /** @var IntegrationEntity $integrationEntity */
                        $integrationEntity = $integrationEntityRepo->getEntity($integrationId[0]['id']);
                        $integrationEntity->setLastSyncDate(new \DateTime());
                        $integrationEntities[] = $integrationEntity;
                    }

                    $this->em->detach($entity);
                    unset($entity);
                }
            }

            $integrationEntityRepo->saveEntities($integrationEntities);
            $this->em->clear(IntegrationEntity::class);
            $this->em->clear();
        }

        return [$updated, $created];
    }

    public function populateMauticLeadData($data, $config = [], $object = null)
    {
        if ($object !== 'company') {
            $object = 'lead';
        }

        return parent::populateMauticLeadData($data, $config, $object);
    }

    private function formatDateForMautic($dateString) {
        $date = new \DateTime($dateString);
        $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $date->format('Y-m-d H:i:s');
    }

    private function normalizeParams(array &$params) {
        if (isset($params['fetchAll']) && $params['fetchAll']) {
            $params['start'] = null;
            $params['end'] = null;
        }
    }

    private function pushRecords($params = [], $object = 'contacts') {
        $object = strtolower($object);
        $this->normalizeParams($params);
        $maxRecords = (isset($params['limit']) && $params['limit'] < 100) ? $params['limit'] : 100;
        list($fromDate, $toDate) = $this->getSyncTimeframeDates($params);

        $config = $this->mergeConfigToFeatureSettings();
        $recordFields = $object === 'company' ? 'companyFields' : 'leadFields';
        $leadFields = array_unique(array_values($config[$recordFields]));

        $totalUpdated = 0;
        $totalCreated = 0;
        $totalErrors = 0;

        if (empty($leadFields)) {
            return [$totalUpdated, $totalCreated, $totalErrors];
        }

        $availableFields = $this->getAvailableLeadFields(['feature_settings' => ['objects' => [$object]]])[$object];
        $fields = $this->getFields($leadFields);

        list($totalToUpdate, $totalToCreate, $totalCount) = $this->getTotalCounts($object, $fields, $fromDate, $toDate, $maxRecords);

        $progress = new ProgressUpdater($totalCount, "About $totalToUpdate to update and about $totalToCreate to create/update");
        $totalUpdated += $this->updateLeads($object, $fields, $totalToUpdate, $fromDate, $toDate, $availableFields, $config, $maxRecords, $progress);
        $totalCreated += $this->createLeads($object, $fields, $totalToCreate, $fromDate, $toDate, $availableFields, $config, $maxRecords, $progress);
        $progress->finish();

        return [$totalUpdated, $totalCreated, $totalErrors];
    }

    private function updateLeads($object, $fields, $totalToUpdate, $fromDate, $toDate, $availableFields, $config, $maxRecords, ProgressUpdater $progress) {
        $leadData = [];
        $rowNum = 0;
        $integrationEntities = [];
        $leadsToUpdate = $this->getLeadsToUpdate($object, $fields, $totalToUpdate, $fromDate, $toDate, $integrationEntities);

        foreach ($leadsToUpdate as $key => $lead) {
            $progress->advance();
            $leadData[$lead['integration_entity_id']] = $this->getLeadDataForUpdate($lead, $object, $availableFields, $config);
            ++$rowNum;

            if ($maxRecords === $rowNum) {
                $this->getApiHelper()->updateLeads($leadData, $object);
                $leadData = [];
                $rowNum = 0;
            }
        }

        $this->getApiHelper()->updateLeads($leadData, $object);

        if (count($integrationEntities)) {
            $this->em->getRepository('MauticPluginBundle:IntegrationEntity')->saveEntities($integrationEntities);
            $this->em->clear(IntegrationEntity::class);
        }

        return count($leadsToUpdate);
    }

    private function createLeads($object, $fields, $totalToCreate, $fromDate, $toDate, $availableFields, $config, $maxRecords, ProgressUpdater $progress) {
        $leadData = [];
        $rowNum = 0;
        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
        $leadsToCreate = $this->getLeadsToCreate($object, $fields, $totalToCreate, $fromDate, $toDate);

        foreach ($leadsToCreate as $lead) {
            $progress->advance();
            $leadData[$lead['internal_entity_id']] = $this->getLeadDataForCreate($lead, $object, $availableFields, $config);
            ++$rowNum;

            if ($maxRecords === $rowNum) {
                $ids = $this->getApiHelper()->createLeads($leadData, $object);
                $this->createIntegrationEntities($ids, $object, $integrationEntityRepo);
                $leadData = [];
                $rowNum = 0;
            }
        }

        $ids = $this->getApiHelper()->createLeads($leadData, $object);
        $this->createIntegrationEntities($ids, $object, $integrationEntityRepo);

        return count($leadsToCreate);
    }

    private function getLeadDataForUpdate($lead, $object, $availableFields, $config) {
        $fieldToCheck = $object === 'company' ? 'companyname' : 'email';
        $recordFields = $object === 'company' ? 'companyFields' : 'leadFields';
        $fieldsToUpdate = $this->getFieldsToUpdate($config, $availableFields, $recordFields);
        $existingRecord = $this->getExistingRecord($fieldToCheck, $lead[$fieldToCheck], $object);
        $objectFields = $this->prepareFieldsForPush($availableFields);
        $fieldsToUpdate = $this->getBlankFieldsToUpdate($fieldsToUpdate, $existingRecord, $objectFields, $config);
        return $this->getMappedFields($fieldsToUpdate, $lead, $availableFields, true);
    }

    private function getLeadDataForCreate($lead, $object, $availableFields, $config) {
        $recordFields = $object === 'company' ? 'companyFields' : 'leadFields';
        return $this->getMappedFields($config[$recordFields], $lead, $availableFields);
    }

    private function getFieldsToUpdate($config, $availableFields, $recordFields) {
        $fieldsToUpdateInCrm = isset($config['update_mautic']) ? array_keys($config['update_mautic'], 0) : [];
        $fieldsToUpdate = array_values(array_intersect(array_keys($availableFields), $fieldsToUpdateInCrm));

        return array_intersect_key($config[$recordFields], array_flip($fieldsToUpdate));
    }

    private function getLeadsToUpdate($object, $fields, $totalToUpdate, $fromDate, $toDate, &$integrationEntities) {
        $leadsToUpdateInNs = [];
        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
        $fieldToCheck = $object === 'company' ? 'companyname' : 'email';
        $internalEntity = $object === 'company' ? 'company' : 'lead';
        $leadsToUpdate = $integrationEntityRepo->findLeadsToUpdate($this->getName(), $internalEntity, $fields, $totalToUpdate, $fromDate, $toDate, [$object])[$object];

        if (is_array($leadsToUpdate)) {
            foreach ($leadsToUpdate as $lead) {
                if (!empty($lead[$fieldToCheck])) {
                    $key = mb_strtolower($this->cleanPushData($lead[$fieldToCheck]));
                    $lead = $this->getCompoundMauticFields($lead);
                    $lead['integration_entity'] = $object;
                    $leadsToUpdateInNs[$key] = $lead;
                    /** @var IntegrationEntity $integrationEntity */
                    $integrationEntity = $this->em->getReference('MauticPluginBundle:IntegrationEntity', $lead['id']);
                    $integrationEntities[] = $integrationEntity->setLastSyncDate(new \DateTime());
                }
            }
        }

        return $leadsToUpdateInNs;
    }

    private function getLeadsToCreate($object, $fields, $totalToCreate, $fromDate, $toDate) {
        $leadsToCreateInNs = [];
        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
        $fieldToCheck = $object === 'company' ? 'companyname' : 'email';
        $internalEntity = $object === 'company' ? 'company' : 'lead';
        $leadsToCreate = $integrationEntityRepo->findLeadsToCreate($this->getName(), $fields, $totalToCreate, $fromDate, $toDate, $internalEntity);

        if (is_array($leadsToCreate)) {
            foreach ($leadsToCreate as $lead) {
                if (!empty($lead[$fieldToCheck])) {
                    $key = mb_strtolower($this->cleanPushData($lead[$fieldToCheck]));
                    $lead = $this->getCompoundMauticFields($lead);
                    $lead['integration_entity'] = $object;
                    $leadsToCreateInNs[$key] = $lead;
                }
            }
        }

        return $leadsToCreateInNs;
    }

    private function getFields($queryFields) {
        foreach ($queryFields as $index => $queryField) {
            if ($queryField === 'mauticContactIsContactableByEmail') {
                unset($queryFields[$index]);
            }
        }

        $fields = implode(', l.', $queryFields);
        return 'l.' . $fields;
    }

    private function getTotalCounts($object, $fields, $fromDate, $toDate, $maxRecords) {
        $internalEntity = $object === 'company' ? 'company' : 'lead';
        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');

        $totalToUpdate = $integrationEntityRepo->findLeadsToUpdate($this->getName(), $internalEntity, $fields, false, $fromDate, $toDate, [$object]);
        $totalToUpdate = $this->getTotalLeads($totalToUpdate, $maxRecords);

        $totalToCreate = $integrationEntityRepo->findLeadsToCreate($this->getName(), $fields, false, $fromDate, $toDate, $internalEntity);
        $totalToCreate = $this->getTotalLeads($totalToCreate, $maxRecords);

        $totalCount = $totalToCreate + $totalToUpdate;
        if ($totalCount > $maxRecords) {
            $totalCount = (int)$maxRecords;
        }

        return [$totalToUpdate, $totalToCreate, $totalCount];
    }

    private function getTotalLeads($leads, $maxRecords) {
        $total = 0;

        if (is_array($leads)) {
            $total = array_sum($leads);
        }
        if ($leads > $maxRecords) {
            $total = $maxRecords;
        }

        return (int) $total;
    }

    private function getMappedFields($fields, $lead, $availableFields, $skipEmpty = false) {
        $mappedData = [];

        foreach ($fields as $k => $v) {
            foreach ($lead as $dk => $dv) {
                if ($v === $dk && isset($availableFields[$k]) && (!$skipEmpty || $dv)) {
                    $mappedData[$k] = $dv;
                }
            }
        }

        return $mappedData;
    }

    private function getExistingRecord($searchColumn, $searchValue, $object = 'contacts')
    {
        return $object === 'company'
            ? $this->getApiHelper()->getCompanyBy($searchColumn, $searchValue)
            : $this->getApiHelper()->getContactBy($searchColumn, $searchValue);
    }

    /**
     * @param array $ids
     * @param $object
     * @param IntegrationEntityRepository $integrationEntityRepo
     */
    private function createIntegrationEntities($ids, $object, $integrationEntityRepo)
    {
        $internalEntity = $object === 'company' ? 'company' : 'lead';

        foreach ($ids as $oid => $leadId) {
            $this->logger->debug('CREATE INTEGRATION ENTITY: ' . $oid);

            $integrationId = $integrationEntityRepo->getIntegrationsEntityId($this->getName(), $object,
                $internalEntity, null, null, null, false, 0, 0,
                "'".$oid."'"
            );

            if (count($integrationId) === 0) {
                $this->createIntegrationEntity($object, $oid, $internalEntity, $leadId);
            }
        }
    }

    public function pushLead($lead, $config = [])
    {
        $config = $this->mergeConfigToFeatureSettings($config);
        $object = 'contacts';

        if (empty($config['leadFields'])) {
            return [];
        }

        $mappedData = $this->populateLeadData($lead, $config, $object);
        $this->amendLeadDataBeforePush($mappedData);

        if (empty($mappedData)) {
            return false;
        }

        try {
            if ($this->isAuthorized()) {
                $integrationEntityRepo = $this->getIntegrationEntityRepository();
                $id = $lead instanceof Lead ? $lead->getId() : $lead['id'];
                $integrationId = $integrationEntityRepo->getIntegrationsEntityId($this->getName(), $object, 'lead', $id);

                if (!empty($integrationId)) {
                    $integrationEntityId = $integrationId[0]['integration_entity_id'];
                    $this->getApiHelper()->updateLead($mappedData, $integrationEntityId);
                    return $integrationEntityId;
                }

                $nsId = $this->getApiHelper()->createLead($mappedData, $lead);
                if (!empty($nsId)) {
                    if (empty($integrationId)) {
                        $integrationEntity = new IntegrationEntity();
                        $integrationEntity
                            ->setDateAdded(new \DateTime())
                            ->setIntegration($this->getName())
                            ->setIntegrationEntity($object)
                            ->setIntegrationEntityId($nsId)
                            ->setInternalEntity('lead')
                            ->setInternalEntityId($id);
                    } else {
                        $integrationEntity = $integrationEntityRepo->getEntity($integrationId[0]['id']);
                    }

                    $integrationEntity->setLastSyncDate(new \DateTime());
                    $this->em->persist($integrationEntity);
                    $this->em->flush($integrationEntity);

                    return $id;
                }

                return true;
            }
        } catch (\Exception $exception) {
            $this->logIntegrationError($exception);
        }

        return false;
    }

    public function pushLeadActivity($params = [])
    {
        $executed = null;
        $query = $this->getFetchQuery($params);
        $config = $this->mergeConfigToFeatureSettings([]);
        $nsObjects = (!empty($config['objects'])) ? $config['objects'] : ['lead'];
        $integrationEntityRepo = $this->getIntegrationEntityRepository();
        $startDate = new \DateTime($query['start']);
        $endDate = new \DateTime($query['end']);
        $limit = 100;

        sort($nsObjects); // @todo Determine if this is really needed

        foreach ($nsObjects as $object) {
            if (!in_array(strtolower($object), ['contacts', 'lead'])) {
                continue;
            }

            try {
                if ($this->isAuthorized()) {
                    $start = 0;
                    $netSuiteIds = $this->getNetSuiteIds($startDate, $endDate, $start, $limit, $object);

                    while (!empty($netSuiteIds)) {
                        $executed += count($netSuiteIds);

                        $leadIds = [];
                        foreach ($netSuiteIds as $ids) {
                            $leadIds[] = $ids['internal_entity_id'];
                        }

                        $leadActivity = $this->getLeadData($startDate, $endDate, $leadIds);

                        $nsLeadData = [];
                        foreach ($netSuiteIds as $ids) {
                            $leadId = $ids['internal_entity_id'];

                            if (isset($leadActivity[$leadId])) {
                                $nsId = $ids['integration_entity_id'];
                                $nsLeadData[$nsId] = $leadActivity[$leadId];
                                $nsLeadData[$nsId]['id'] = $nsId;
                                $nsLeadData[$nsId]['leadId'] = $ids['internal_entity_id'];
                                $nsLeadData[$nsId]['leadUrl'] = $this->router->generate(
                                    'mautic_plugin_timeline_view',
                                    ['integration' => $this->getName(), 'leadId' => $leadId],
                                    UrlGeneratorInterface::ABSOLUTE_URL
                                );
                            }
                        }

                        if (!empty($nsLeadData)) {
                            $this->getApiHelper()->createLeadActivity($nsLeadData, $object);
                        }

                        $start += $limit;
                        $netSuiteIds = $this->getNetSuiteIds($startDate, $endDate, $start, $limit, $object);
                    }
                }
            } catch (\Exception $exception) {
                $this->logIntegrationError($exception);
            }
        }

        return $executed;
    }

    private function getNetSuiteIds(\DateTime $startDate, \DateTime $endDate, $start, $limit, $object) {
        return $this->getIntegrationEntityRepository()->getIntegrationsEntityId(
            $this->getName(),
            $object,
            'lead',
            null,
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s'),
            true,
            $start,
            $limit
        );
    }
}
