<?php

namespace Drupal\sfweb2lead_webform\Plugin\WebformHandler;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sfweb2lead_webform\Event\Sfweb2leadWebformEvent;
use Drupal\webform\Plugin\WebformHandler\RemotePostWebformHandler;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Webform submission remote post handler.
 *
 * @WebformHandler(
 *   id = "sfweb2lead_post",
 *   label = @Translation("Salesforce Web-to-Lead post"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts webform submissions to a Salesforce.com URL."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class SalesforceWebToLeadPostWebformHandler extends RemotePostWebformHandler {

  /**
   * Event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Typical salesforce campaign fields
   * Used for available list of campaign fields.
   *
   * @see https://help.salesforce.com/articleView?id=setting_up_web-to-lead.htm&type=0
   * @var array
   */
  protected $salesforceCampaignFields = ['description', 'email', 'first_name', 'last_name', 'lead_source', 'phone'];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'type' => 'x-www-form-urlencoded',
      'salesforce_url' => '',
      'salesforce_oid' => '',
      'salesforce_mapping' => [],
      'excluded_data' => [],
      'custom_data' => '',
      'debug' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $webform = $this->getWebform();
    $form['salesforce_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Salesforce URL'),
      '#description' => $this->t('The full URL to POST to on salesforce.com. E.g. https://www.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['salesforce_url'],
    ];

    $form['salesforce_oid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Salesforce OID value'),
      '#description' => $this->t('The OID value to post to Salesforce.'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['salesforce_oid'],
    ];

    $map_sources = [];
    $elements = $this->webform->getElementsInitializedAndFlattened();
    foreach ($elements as $key => $element) {
      if (strpos($key, '#') === 0 || empty($element['#title']) || !empty($element['#webform_composite_elements'])) {
        if (!empty($element['#webform_composite_elements'])) {
          foreach ($element['#webform_composite_elements'] as $subkey => $subelement) {
            $map_sources[$key . '_' . $subkey] = $element['#title'] . ' - ' . $subelement['#title'];
          }
        }
        continue;
      }
      $map_sources[$key] = $element['#title'];
    }
    $field_definitions = $this->submissionStorage->getFieldDefinitions();
    $field_definitions = $this->submissionStorage->checkFieldDefinitionAccess($webform, $field_definitions);
    foreach ($field_definitions as $key => $field_definition) {
      $map_sources[$key] = $field_definition['title'] . ' (type : ' . $field_definition['type'] . ')';
    }

    $form['salesforce_mapping'] = [
      '#type' => 'webform_mapping',
      '#title' => $this->t('Webform to Salesforce mapping'),
      '#description' => $this->t('Only Maps with specified "Salesforce Web-to-Lead Campaign Field" will be submitted to salesforce.'),
      '#source__title' => t('Webform Submitted Data'),
      '#destination__title' => t('Salesforce Web-to-Lead Campaign Field'),
      '#source' => $map_sources,
      '#destination__type' => 'webform_select_other',
      '#destination' => array_combine($this->salesforceCampaignFields, $this->salesforceCampaignFields),
      '#default_value' => $this->configuration['salesforce_mapping'],
    ];

    $form['custom_data'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom data'),
      '#description' => $this->t('Custom data will take precedence over submission data. You may use tokens.'),
    ];

    $form['custom_data']['custom_data'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Custom data'),
      '#description' => $this->t('Enter custom data that will be included in all remote post requests.'),
      '#parents' => ['settings', 'custom_data'],
      '#default_value' => $this->configuration['custom_data'],
    ];
    $form['custom_data']['custom_data'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Insert data'),
      '#description' => $this->t("Enter custom data that will be included when a webform submission is saved."),
      '#parents' => ['settings', 'custom_data'],
      '#default_value' => $this->configuration['custom_data'],
    ];

    $form['custom_data']['token_tree_link'] = $this->tokenManager->buildTreeLink();

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debugging'),
      '#description' => $this->t('If checked, posted submissions will be displayed onscreen to all users.'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration['debug'],
    ];
    return $form;

  }

  /**
   * {@inheritdoc}
   */
  protected function remotePost($state, WebformSubmissionInterface $webform_submission) {
    if (!empty($this->configuration['salesforce_url']) && $state === WebformSubmissionInterface::STATE_COMPLETED) {
      $this->configuration[$state . '_url'] = $this->configuration['salesforce_url'];
    }
    return parent::remotePost($state, $webform_submission);
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequestData($state, WebformSubmissionInterface $webform_submission) {
    $data = parent::getRequestData($state, $webform_submission);
    $salesforce_data = [
      'oid' => $this->configuration['salesforce_oid'],
    ];

    // Get Salesforce field mappings.
    $salesforce_mapping = $this->configuration['salesforce_mapping'];
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        foreach ($value as $sub_key => $sub_value) {
          $new_key = $key . '_' . $sub_key;
          if (array_key_exists($new_key, $salesforce_mapping)) {
            $salesforce_data[$salesforce_mapping[$new_key]] = $sub_value;
          }
        }
        continue;
      }
      if (array_key_exists($key, $salesforce_mapping)) {
        $salesforce_data[$salesforce_mapping[$key]] = $value;
      }
    }

    // Add custom data.
    $custom_data = Yaml::decode($this->configuration['custom_data']);
    if (!empty($custom_data)) {
      foreach ($custom_data as $key => $value) {
        if (array_key_exists($key, $data)) {
          $salesforce_data[$key] = $data[$key];
        }
      }
    }

    // Allow modification of data by other modules.
    $event = new Sfweb2leadWebformEvent($salesforce_data, $this, $webform_submission);
    $this->eventDispatcher()->dispatch($event, Sfweb2leadWebformEvent::SUBMIT);

    return $event->getData();
  }

  /**
   * Avoid using proper DIC here and rely on the parent class constructor.
   *
   * The parent class constructor relies on 9 injected services, thus is very
   * cumbersome to override and maintain. By using this quick and dirty helper,
   * we can more simply wrap the event dispatcher service.
   *
   * @return \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected function eventDispatcher() {
    if (!$this->eventDispatcher) {
      $this->eventDispatcher = \Drupal::service('event_dispatcher');
    }
    return $this->eventDispatcher;
  }

}
