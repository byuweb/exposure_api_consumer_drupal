<?php
/**
 * @file
 * Contains \Drupal\exposure_api_consumer\Form\ExposureApiConsumerConfigForm.
 */

namespace Drupal\exposure_api_consumer\Form;

use DateTime;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Exception;

class ExposureApiConsumerConfigForm extends ConfigFormBase
{

  private $options;
  private $uid;

  /**
   * ExposureApiConsumerConfigForm constructor.
   *
   * @param ConfigFactoryInterface $config_factory
   */
  public function __construct(
    ConfigFactoryInterface $config_factory
  )
  {
    parent::__construct($config_factory);
    $this->options = [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET",
      CURLOPT_SSL_VERIFYSTATUS => FALSE,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_FOLLOWLOCATION
    ];
    $this->uid = $this->currentUser()->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'exposure_api_consumer_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form              = parent::buildForm($form, $form_state);
    $config            = $this->config('exposure_api_consumer.settings');
    $form['endpoint'] = array(
      '#type' => 'textfield',
      '#title' => t('Endpoint:'),
      '#required' => TRUE,
      '#default_value' => $config->get('endpoint'),
      '#description' => t('Example: https://exposure.co/api/3/site/{endpoint}')
    );
    $form['sync_api'] = [
      '#type' => 'submit',
      '#value' => t('Sync with API'),
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $op = $form_state->getValue('op');
    switch ($op) {
      case 'Save configuration':
        $this->submitForm($form, $form_state);
        break;
      case 'Sync with API':
        $this->syncWithApi($form, $form_state);
        break;
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $config = $this->config('exposure_api_consumer.settings');
    $config->set('endpoint', $form_state->getValue('endpoint'));
    $config->save();
    $message = $this->messenger();
    $message->addMessage($this->t("Update successful."), $message::TYPE_STATUS);
  }

  /**
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function syncWithApi(array &$form, FormStateInterface $form_state)
  {
    $config = $this->config('exposure_api_consumer.settings');

    //Get the data
    $this->getData($config);

    parent::submitForm($form, $form_state);
  }

  /**
   * @param Config $config
   */
  private
  function getData(Config &$config)
  {
    // Get collection and gallery data
    $config = $this->config('exposure_api_consumer.settings');
    $endpoint = $config->get('endpoint');
    $fullUrl = 'https://exposure.co/api/3/site/' . $endpoint . '/stories';
    $curl = curl_init($fullUrl);
    curl_setopt_array($curl, $this->options);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
      echo "cURL Error #:" . $err;
      exit(1);
    }
    $raw = json_decode($response, TRUE);
    $response = $raw['stories']['stories'];

    // Cycle through all collections
    foreach ($response as $story) {
      $this->saveStory($story);
      unset($story);
    }
    unset($response);
  }

  /**
   * @param array $story
   */
  private function saveStory(
    array &$story
  )
  {
    $storyId = $story['id'];
    $storyTitle = $story['title'];
    $storyUrl = $story['urls']['story_web'];
    $storyThumb = $story['cover_photo']['url'];
    $storyModified = $story['published_at'];

    if ($storyThumb !== NULL) {
      $file = File::create([
        'uri' => $storyThumb,
        'alt' => t($storyTitle),
      ]);
      try {
        $file->save();
      } catch (EntityStorageException $e) {
        echo $e->getMessage();
      }
    }

    // Check if node exists
    $connection = \Drupal::database();
    $query = $connection->query("SELECT * FROM {node__field_story_link} WHERE `field_story_link_uri`='$storyUrl'");
    $result = $query->fetchAll();

    if ($result == NULL) {
      $node = Node::create([
        'nid' => NULL,
        'langcode' => 'en',
        'uid' => $this->uid,
        'type' => 'photo_essay',
        'title' => $storyTitle,
        'status' => 1,
        'promote' => 0,
        'comment' => 0,
        'field_thumbnail' => isset($file) ?
          ['target_id' => $file->id(), 'alt' => $storyTitle] : NULL,
        'field_story_id' => $storyId,
        'field_story_link' => $storyUrl
      ]);
      /**THIS CODE ADDS A DATE TO THE NODE*/
      //get the date; format it as a timestamp
      $dateStr = substr($storyModified, 0, 19);
      $timezone = new \DateTimeZone(substr($storyModified, -6));
      $date = DateTime::createFromFormat("Y-m-d\TH:i:s", $dateStr, $timezone);
      $timestamp = $date->getTimestamp();

      //change time of the node's creation
      $node->setCreatedTime($timestamp);
      /**END ADDED CODE*/
      try {
        $node->save();
      } catch (Exception $e) {
        echo $e->getMessage();
        exit(1);
      }
      if (isset($file)) {
        unset($file);
      }
      unset($node);
    }
  }

  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames()
  {
    return ['exposure_api_consumer.settings'];
  }
}
