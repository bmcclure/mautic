<?php

namespace MauticPlugin\MauticNetSuiteBundle\Integration;

use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuiteApi;
use MauticPlugin\MauticNetSuiteBundle\Api\NetSuiteApiException;
use NetSuite\Classes\CustomizationFieldType;
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
        return ['get_leads', 'push_leads', 'push_lead'];
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
    public function getDataPriority()
    {
        return true;
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
}
