<?php

namespace Drupal\ai_provider_mittwald\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ai_provider_mittwald\MittwaldHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure mittwald API access.
 */
class MittwaldConfigForm extends ConfigFormBase {

  /**
   * Config settings.
   */
  const CONFIG_NAME = 'ai_provider_mittwald.settings';

  /**
   * Default provider ID.
   */
  const PROVIDER_ID = 'mittwald';

  /**
   * Constructs a new MittwaldConfigForm object.
   */
  final public function __construct(
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly KeyRepositoryInterface  $keyRepository,
    private readonly MittwaldHelper          $mittwaldHelper,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('key.repository'),
      $container->get('ai_provider_mittwald.helper'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mittwald_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::CONFIG_NAME);

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('mittwald API Key'),
      '#description' => $this->t('A valid API key is required to use mittwald services. Read  <a href=":url" target="_blank">the documentation</a> on how to obtain API access.', [':url' => 'https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/access/']),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['moderation'] = [
      '#markup' => '<p>' . $this->t('Moderation is always on by default for any text based call. You can disable it for each request either via code or by changing manually in ai_provider_mittwald.settings.yml.') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate the api key against model listing.
    $key = $form_state->getValue('api_key');
    if (empty($key)) {
      $form_state->setErrorByName('api_key', $this->t('The API key is required. Please select a valid key from the list.'));
      return;
    }
    $api_key = $this->keyRepository->getKey($key)->getKeyValue();
    if (!$api_key) {
      $form_state->setErrorByName('api_key', $this->t('The API key is invalid. Please double-check that the selected key has a value. If you are using a file-based Key, ensure the file is present in the environment and contains a value.'));
      return;
    }
    /** @var \Drupal\ai_provider_mittwald\Plugin\AiProvider\MittwaldProvider $provider */
    $provider = $this->aiProviderManager->createInstance('mittwald');

    // Temporarily set the API key and host for validation.
    $provider->setAuthentication($api_key);
    $host = $this->config(static::CONFIG_NAME)->get('host');
    if (!empty($host)) {
      $provider->setConfiguration(['host' => $host]);
    }

    try {
      // Test connectivity by attempting to get configured models.
      $provider->getConfiguredModels();
    }
    catch (\Exception $err) {
      $form_state->setErrorByName('api_key', $this->t('The selected API key is not working. Please double-check the correct API key was entered and that it has credit(s) available.'));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $api_key = $this->keyRepository->getKey($form_state->getValue('api_key'))->getKeyValue();
    // If it all passed through, we do one last check of rate limits via chat.
    $this->mittwaldHelper->testRateLimit($api_key);
    // Retrieve the configuration.
    $this->config(static::CONFIG_NAME)
      ->set('api_key', $form_state->getValue('api_key'))
      ->save();

    // Set default models.
    $this->setDefaultModels();
    parent::submitForm($form, $form_state);
  }

  /**
   * Set default models for the AI provider.
   */
  private function setDefaultModels() {
    // Create provider instance.
    $provider = $this->aiProviderManager->createInstance(static::PROVIDER_ID);

    // Check if getSetupData() method exists and is callable.
    if (is_callable([$provider, 'getSetupData'])) {
      // Fetch setup data.
      $setup_data = $provider->getSetupData();

      // Ensure the setup data is valid.
      if (!empty($setup_data) && is_array($setup_data) && !empty($setup_data['default_models']) && is_array($setup_data['default_models'])) {
        // Loop through and set default models for each operation type.
        foreach ($setup_data['default_models'] as $op_type => $model_id) {
          $this->aiProviderManager->defaultIfNone($op_type, static::PROVIDER_ID, $model_id);
        }
      }
    }
  }

}
