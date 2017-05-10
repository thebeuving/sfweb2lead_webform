<?php

namespace Drupal\sfweb2lead_webform_d8\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\webform\Plugin\WebformHandler\RemotePostWebformHandler;

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
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $field_names = array_keys(\Drupal::service('entity_field.manager')
      ->getBaseFieldDefinitions('webform_submission'));
    $excluded_data = array_combine($field_names, $field_names);
    return [
      'type' => 'x-www-form-urlencoded',
      'salesforce_url' => '',
      'salesforce_oid' => '',
      'salesforce_mapping' => array(),
      'excluded_data' => $excluded_data,
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

    $form['submission_data'] = [
      '#type' => 'details',
      '#title' => $this->t('Submission data'),
    ];
    $form['submission_data']['excluded_data'] = [
      '#type' => 'webform_excluded_columns',
      '#title' => $this->t('Posted data'),
      '#title_display' => 'invisible',
      '#webform' => $webform,
      '#required' => TRUE,
      '#parents' => ['settings', 'excluded_data'],
      '#default_value' => $this->configuration['excluded_data'],
    ];

    $form['salesforce_mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Salesforce field mapping'),
      '#description' => $this->t('Specify alternate field names to be sent to Salesforce (e.g. <em>00M1a000010Id6x</em>). If not set, the webform key will be used.'),
    ];
    $elements = $this->webform->getElementsDecoded();
    foreach ($elements as $key => $element) {
      if (strpos($key, '#') === 0 || empty($element['#title'])) {
        continue;
      }
      $form['salesforce_mapping'][$key] = [
        '#type' => 'textfield',
        '#title' => $element['#title'] . ' (' . $key . ')',
        '#default_value' => (!empty($this->configuration['salesforce_mapping'][$key])) ? $this->configuration['salesforce_mapping'][$key] : NULL,
        '#attributes' => [
          'placeholder' => $key,
        ],
      ];
    }

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

    // Get data from parent.
    $data = parent::getPostData($operation, $webform_submission);

    // Get Salesforce field mappings.
    $salesforce_mapping = $this->configuration['salesforce_mapping'];

    $salesforce_data = array(
      'oid' => $this->configuration['salesforce_oid'],
    );
    foreach ($data as $key => $value) {
      if (!empty($salesforce_mapping[$key])) {
        $salesforce_data[$salesforce_mapping[$key]] = $value;
      }
      else {
        $salesforce_data[$key] = $value;
      }
    } // Loop thru data fields.

    // Allow modification of data by other modules.
    \Drupal::moduleHandler()->alter('sfweb2lead_webform_d8_post_data', $salesforce_data, $this->webform, $webform_submission);

    return $salesforce_data;

  }

}
