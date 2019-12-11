<?php

namespace MauticPlugin\MauticNetSuiteBundle\Integration;

use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Helper\IdentifyCompanyHelper;
use Mautic\PluginBundle\Entity\IntegrationEntity;
use Mautic\PluginBundle\Entity\IntegrationEntityRepository;
use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuiteApi;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuiteApiException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

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
    public function getAvailableLeadFields($settings = [])
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
    public function populateLeadData($lead, $config = [], $object = 'Contacts')
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

    private function getRecords($params = [], $query = null, &$executed = null, $result = [], $object = 'contacts') {
        if (strtolower($object) === 'contact') {
            $object = 'contacts';
        }

        if (!$query) {
            $query = $this->getFetchQuery($params);
        }

        if (!is_array($executed)) {
            $executed = [0 => 0, 1 => 0];
        }

        try {
            if ($this->isAuthorized()) {
                $progress = false;

                if (isset($params['output']) && $params['output']->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) {
                    $progress = new ProgressBar($params['output']);
                    $progress->start();
                }

                while (true) {
                    $data = $object === 'company'
                        ? $this->getApiHelper()->getCompanies($query)
                        : $this->getApiHelper()->getContacts($query);

                    if (empty($data)) {
                        break;
                    }

                    list($updated, $created) = $this->amendLeadDataBeforeMauticPopulate($data, $object, $params);
                    $executed[0] += $updated;
                    $executed[1] += $created;

                    if (isset($params['output'])) {
                        if ($params['output']->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                            $params['output']->writeln($result);
                        } else {
                            $progress->advance();
                        }
                    }

                    $more = false;

                    if (!$more) {
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
                if (!empty($entityData['email'])) {
                    $entityData['email'] = InputHelper::email($entityData['email']);
                }

                $isModified = false;
                $recordId = $entityData['netsuite_id'];
                $integrationId = $integrationEntityRepo->getIntegrationsEntityId($this->getName(), $object, $mauticObjectReference, null, null, null, false, 0, 0, "'$recordId'");

                if (count($integrationId)) {
                    $model = $object === 'company' ? $this->companyModel : $this->leadModel;
                    $entity = $model->getEntity($integrationId[0]['internal_entity_id']);
                    $matchedFields = $this->populateMauticLeadData($entityData, $config, $object);

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

                if ($object !== 'company' && !empty($entityData['company']) && $entityData['company'] !== $this->translator->trans('mautic.integration.form.lead.unknown')) {
                    // @todo verify functionality of company identification
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

                if ($entity) {
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
            $this->em->clear('Mautic\PluginBundle\Entity\IntegrationEntity');
            $this->em->clear();
        }

        return [$updated, $created];
    }

    private function pushRecords($params = [], $object = 'contacts') {
        $maxRecords = (isset($params['limit']) && $params['limit'] < 100) ? $params['limit'] : 100;

        if (isset($params['fetchAll']) && $params['fetchAll']) {
            $params['start'] = null;
            $params['end'] = null;
        }

        list($fromDate, $toDate) = $this->getSyncTimeframeDates($params);
        $config = $this->mergeConfigToFeatureSettings();
        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
        $fieldsToUpdateInCrm = isset($config['update_mautic']) ? array_keys($config['update_mautic'], 0) : [];
        $recordFields = $object === 'company' ? 'companyFields' : 'leadFields';
        $leadFields = array_unique(array_values($config[$recordFields]));
        $totalUpdated = 0;
        $totalCreated = 0;
        $totalErrors = 0;

        if (empty($leadFields)) {
            return [0, 0, 0];
        }

        $fields = implode(', l.', $leadFields);
        $fields = 'l.' . $fields;

        $availableFields = $this->getAvailableLeadFields(['feature_settings' => ['objects' => [$object]]]);
        $fieldsToUpdate[$object] = array_values(array_intersect(array_keys($availableFields[$object]), $fieldsToUpdateInCrm));
        $fieldsToUpdate[$object] = array_intersect_key($config[$recordFields], array_flip($fieldsToUpdate[$object]));

        $progress = false;

        $internalEntity = $object === 'company' ? 'company' : 'lead';

        $totalToUpdate = $integrationEntityRepo->findLeadsToUpdate($this->getName(), $internalEntity, $fields, false, $fromDate, $toDate, [$object]);
        if (is_array($totalToUpdate)) {
            $totalToUpdate = array_sum($totalToUpdate);
        }

        $totalToCreate = $integrationEntityRepo->findLeadsToCreate($this->getName(), $fields, false, $fromDate, $toDate, $internalEntity);
        if (is_array($totalToCreate)) {
            $totalToCreate = array_sum($totalToCreate);
        }

        $totalCount = $totalToCreate + $totalToUpdate;

        if (defined('IN_MAUTIC_CONSOLE') && $totalToUpdate + $totalToCreate) {
            $output = new ConsoleOutput();
            $output->writeln("About $totalToUpdate to update and about $totalToCreate to create/update");
            $progress = new ProgressBar($output, $totalCount);
        }

        $leadsToCreateInNs = [];
        $leadsToUpdateInNs = [];
        $integrationEntities = [];
        $fieldToCheck = $object === 'company' ? 'companyName' : 'email';
        $leadsToUpdate = $integrationEntityRepo->findLeadsToUpdate($this->getName(), $internalEntity, $fields, $totalToUpdate, $fromDate, $toDate, $object, [])[$object];

        if (is_array($leadsToUpdate)) {
            $totalUpdated += count($leadsToUpdate);

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
        unset($leadsToUpdate);

        $leadsToCreate = $integrationEntityRepo->findLeadsToCreate($this->getName(), $fields, $totalToCreate, $fromDate, $toDate, $internalEntity);

        if (is_array($leadsToCreate)) {
            $totalCreated += count($leadsToCreate);

            foreach ($leadsToCreate as $lead) {
                if (!empty($lead[$fieldToCheck])) {
                    $key = mb_strtolower($this->cleanPushData($lead[$fieldToCheck]));
                    $lead = $this->getCompoundMauticFields($lead);
                    $lead['integration_entity'] = $object;
                    $leadsToCreateInNs[$key] = $lead;
                }
            }
        }
        unset($leadsToCreate);

        if (count($integrationEntities)) {
            $integrationEntityRepo->saveEntities($integrationEntities);
            $this->em->clear(IntegrationEntity::class);
        }

        $leadData = [];
        $rowNum = 0;

        foreach ($leadsToUpdateInNs as $key => $lead) {
            if (defined('IN_MAUTIC_CONSOLE') && $progress) {
                $progress->advance();
            }

            $existingRecord = $this->getExistingRecord($fieldToCheck, $lead[$fieldToCheck], $object);
            $objectFields = $this->prepareFieldsForPush($availableFields[$object]);
            $fieldsToUpdate[$object] = $this->getBlankFieldsToUpdate($fieldsToUpdate[$object], $existingRecord, $objectFields, $config);
            $mappedData = $this->getMappedFields($fieldsToUpdate[$object], $lead, $availableFields[$object], true);
            $leadData[$lead['integration_entity_id']] = $mappedData;

            ++$rowNum;

            if ($maxRecords === $rowNum) {
                $this->getApiHelper()->updateLeads($leadData, $object);
                $leadData = [];
                $rowNum = 0;
            }
        }

        $this->getApiHelper()->updateLeads($leadData, $object);
        $leadData = [];
        $rowNum = 0;

        foreach ($leadsToCreateInNs as $lead) {
            if (defined('IN_MAUTIC_CONSOLE') && $progress) {
                $progress->advance();
            }

            $mappedData = $this->getMappedFields($config[$recordFields], $lead, $availableFields[$object]);
            $leadData[$lead['internal_entity_id']] = $mappedData;

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

        if ($progress) {
            $progress->finish();
            $output->writeln('');
        }

        return [$totalUpdated, $totalCreated, $totalErrors];
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
}
