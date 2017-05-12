<?php

namespace Drupal\sfweb2lead_webform\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\webform\Element\WebformOtherBase;
use Drupal\webform\Plugin\WebformHandler\RemotePostWebformHandler;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Webform submission remote post handler.
 *
 * @WebformHandler(
 *   id = "sfweb2lead_post",
 *   label = @Translation("Salesforce Web-to-Lead post"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts webform submissions to a Salesforce.com URL."),
 *   cardinality = \Drupal\webform\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class SalesforceWebToLeadPostWebformHandler extends RemotePostWebformHandler {

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
    $field_names = array_keys(\Drupal::service('entity_field.manager')->getBaseFieldDefinitions('webform_submission'));
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
    $elements = $this->webform->getElementsDecoded();
    foreach ($elements as $key => $element) {
      if (strpos($key, '#') === 0 || empty($element['#title'])) {
        continue;
      }
      $map_sources[$key] = $element['#title'];
    }
    /** @var \Drupal\webform\WebformSubmissionStorageInterface $submission_storage */
    $submission_storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
    $field_definitions = $submission_storage->getFieldDefinitions();
    $field_definitions = $submission_storage->checkFieldDefinitionAccess($webform, $field_definitions);
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
   * Execute a remote post.
   *
   * @param string $operation
   *   The type of webform submission operation to be posted. Can be 'insert',
   *   'update', or 'delete'.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   */
  protected function remotePost($operation, WebformSubmissionInterface $webform_submission) {

    if ($operation != 'insert') {
      // Not an insert, don't bother.
      return;
    }

    $request_url = $this->configuration['salesforce_url'];
    if (empty($request_url)) {
      return;
    }

    $request_type = 'x-www-form-urlencoded';
    $request_post_data = $this->getPostData($operation, $webform_submission);

    try {
      $response = $this->httpClient->post($request_url, ['form_params' => $request_post_data]);
    }
    catch (RequestException $request_exception) {
      $message = $request_exception->getMessage();
      $response = $request_exception->getResponse();

      // Encode HTML entities to prevent broken markup from breaking the page.
      $message = nl2br(htmlentities($message));

      // If debugging is enabled, display the error message on screen.
      $this->debug($message, $operation, $request_url, $request_type, $request_post_data, $response, 'error');

      // Log error message.
      $context = [
        '@form' => $this->getWebform()->label(),
        '@operation' => $operation,
        '@type' => $request_type,
        '@url' => $request_url,
        '@message' => $message,
        'link' => $this->getWebform()
          ->toLink(t('Edit'), 'handlers-form')
          ->toString(),
      ];
      $this->logger->error('@form webform remote @type post (@operation) to @url failed. @message', $context);
      return;
    }

    // If debugging is enabled, display the request and response.
    $this->debug(t('Remote post successful!'), $operation, $request_url, $request_type, $request_post_data, $response, 'warning');
  }

  /**
   * Get a webform submission's post data.
   *
   * @param string $operation
   *   The type of webform submission operation to be posted. Can be 'insert',
   *   'update', or 'delete'.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   *
   * @return array
   *   A webform submission converted to an associative array.
   */
  protected function getPostData($operation, WebformSubmissionInterface $webform_submission) {
    $salesforce_data = [
      'oid' => $this->configuration['salesforce_oid'],
    ];
    $data = $webform_submission->toArray(TRUE);
    $data = $data['data'] + $data;
    unset($data['data']);
    // Get data from parent.
    // Well the idea is that only mapped data and custom data are passed to salesforce.
    // Also curation is best handled only at salesforce_mapping.
    // Unable to make use of parent getPostData logic.
    // $data = parent::getPostData($operation, $webform_submission); .
    // Get Salesforce field mappings.
    $salesforce_mapping = $this->configuration['salesforce_mapping'];
    foreach ($data as $key => $value) {
      $salesforce_campaign_field = '';
      $select_or_other_values = $salesforce_mapping[$key];
      if (!empty($select_or_other_values['select']) && $select_or_other_values['select'] != WebformOtherBase::OTHER_OPTION) {
        $salesforce_campaign_field = $select_or_other_values['select'];
      }
      elseif (!empty($select_or_other_values['select']) && $select_or_other_values['select'] == WebformOtherBase::OTHER_OPTION && !empty($select_or_other_values['other'])) {
        $salesforce_campaign_field = $select_or_other_values['other'];
      }
      if (!empty($value) && !empty($salesforce_campaign_field)) {
        $salesforce_data[$salesforce_campaign_field] = $value;
      }
    }
    // Append custom data.
    if (!empty($this->configuration['custom_data'])) {
      $salesforce_data = Yaml::decode($this->configuration['custom_data']) + $salesforce_data;
    }
    // Append operation data.
    if (!empty($this->configuration[$operation . '_custom_data'])) {
      $salesforce_data = Yaml::decode($this->configuration[$operation . '_custom_data']) + $salesforce_data;
    }
    // Replace tokens.
    $salesforce_data = $this->tokenManager->replace($salesforce_data, $webform_submission);
    // Allow modification of data by other modules.
    \Drupal::moduleHandler()->alter('sfweb2lead_webform_posted_data', $salesforce_data, $this->webform, $webform_submission);
    return $salesforce_data;

  }

}
