<?php

namespace Drupal\ai_provider_mittwald\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\Moderation\ModerationInput;
use Drupal\ai\OperationType\Moderation\ModerationOutput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechInput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ai_provider_mittwald\MittwaldHelper;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use OpenAI\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'mittwald' provider.
 */
#[AiProvider(
  id: 'mittwald',
  label: new TranslatableMarkup('mittwald'),
)]
class MittwaldProvider extends OpenAiBasedProviderClientBase {


  use ChatTrait;

  /**
   * The helper to use.
   */
  protected MittwaldHelper $mittwaldHelper;

  /**
   * Run moderation call, before a normal call.
   *
   * @var bool|null
   */
  protected bool|null $moderation = NULL;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $parent_instance                 = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $parent_instance->mittwaldHelper = $container->get('ai_provider_mittwald.helper');
    $parent_instance->logger         = $container->get('logger.factory')->get('ai_provider_mittwald');
    return $parent_instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    // Load all models, and since mittwald does not provide information about
    // which models does what, we need to hard code it in a helper function.
    $this->loadClient();
    return $this->getModels($operation_type ?? '', $capabilities);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return [
      'chat',
      'embeddings',
      'moderation',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    if ($model_id == 'Qwen3-Embedding-8B') {
      // NOTE: DO NOT set dimensions for the Qwen3-Embedding-8B model here,
      // as this will confuse the model, even if the dimensions are correct.
      // $generalConfig['dimensions']['default'] = 4096;.
    }

    // @todo move this to an object once supported.
    if ($this->isReasoningModel($model_id)) {
      $generalConfig['reasoning_effort'] = [
        'type'        => 'select',
        'label'       => 'Reasoning Effort',
        'description' => 'Constrains effort on reasoning for reasoning models.',
        'default'     => 'medium',
        'constraints' => [
          'options' => [
            'minimal',
            'low',
            'medium',
            'high',
          ],
        ],
      ];
    }

    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function getClient(string $api_key = ''): Client {
    // If the moderation is not set, we load it from the configuration.
    if (is_null($this->moderation)) {
      $this->moderation = $this->getConfig()->get('moderation');
    }
    return parent::getClient($api_key);
  }

  /**
   * {@inheritdoc}
   */
  protected function loadClient(): void {
    // Set custom endpoint from host config if available.
    if (!empty($this->getConfig()->get('host'))) {
      $this->setEndpoint($this->getConfig()->get('host'));
    }
    else {
      $this->setEndpoint('llm.aihosting.mittwald.de/v1');
    }

    try {
      parent::loadClient();
    }
    catch (AiSetupFailureException $e) {
      throw new AiSetupFailureException('Failed to initialize mittwald client: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(EmbeddingsInput|string $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $this->loadClient();

    if ($input instanceof EmbeddingsInput) {
      $input = $input->getPrompt();
    }

    $payload = [
      'model' => $model_id,
      'input' => $input,
    ] + $this->configuration;

    if ($model_id === 'Qwen3-Embedding-8B') {
      unset($payload['dimensions']);
    }

    try {
      $response = $this->client->embeddings()->create($payload)->toArray();
      return new EmbeddingsOutput($response['data'][0]['embedding'], $response, []);
    }
    catch (\Exception $e) {
      $this->handleApiException($e);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string|ModerationInput $input, ?string $model_id = NULL, array $tags = []): ModerationOutput {
    throw new \Exception("not implemented");
  }

  /**
   * {@inheritdoc}
   */
  public function textToImage(string|TextToImageInput $input, string $model_id, array $tags = []): TextToImageOutput {
    throw new \Exception("not implemented");
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string|TextToSpeechInput $input, string $model_id, array $tags = []): TextToSpeechOutput {
    throw new \Exception("not implemented");
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string|SpeechToTextInput $input, string $model_id, array $tags = []): SpeechToTextOutput {
    throw new \Exception("not implemented");
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupData(): array {
    return [
      'key_config_name' => 'api_key',
      'default_models'  => [
        'chat'                          => 'Mistral-Small-3.2-24B-Instruct',
        'chat_with_image_vision'        => 'Mistral-Small-3.2-24B-Instruct',
        'chat_with_complex_json'        => 'Mistral-Small-3.2-24B-Instruct',
        'chat_with_tools'               => 'Mistral-Small-3.2-24B-Instruct',
        'chat_with_structured_response' => 'Mistral-Small-3.2-24B-Instruct',
        'embeddings'                    => 'Qwen3-Embedding-8B',

        /*
        'text_to_image'                 => 'dall-e-3',
        'moderation'                    => 'omni-moderation-latest',
        'text_to_speech'                => 'tts-1-hd',
        'speech_to_text'                => 'whisper-1',
        */
      ],
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function postSetup(): void {
    // Throw an error on installation with rate limit.
    $this->mittwaldHelper->testRateLimit($this->loadApiKey());
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    return match (strtolower($model_id)) {
      'qwen3-embedding-8b' => 4096,
      default => 0,
    };
  }

  /**
   * Obtains a list of models from mittwald and caches the result.
   *
   * This method does its best job to filter out deprecated or unused models.
   * The mittwald API endpoint does not have a way to filter those out yet.
   *
   * @param string $operation_type
   *   The bundle to filter models by.
   * @param array $capabilities
   *   The capabilities to filter models by.
   *
   * @return array
   *   A filtered list of public models.
   */
  public function getModels(string $operation_type, $capabilities): array {
    $models = [];

    $cache_key  = 'mittwald_models_' . $operation_type . '_' . Crypt::hashBase64(Json::encode($capabilities));
    $cache_data = $this->cacheBackend->get($cache_key);

    if (!empty($cache_data)) {
      return $cache_data->data;
    }

    $list = $this->client->models()->list()->toArray();

    foreach ($list['data'] as $model) {
      if ($model['owned_by'] === 'openai-dev') {
        continue;
      }

      // Basic model type filtering based on operation type.
      switch ($operation_type) {
        case 'chat':
          if (!preg_match('/^(gpt-oss|mistral-small-|qwen3-coder-)/i', $model['id'])) {
            continue 2;
          }
          break;

        case 'embeddings':
          if (!preg_match('/^(qwen3-embedding)/i', trim($model['id']))) {
            continue 2;
          }
          break;

        case 'moderation':
          if (!preg_match('/^(text-moderation|omni-moderation)/i', $model['id'])) {
            continue 2;
          }
          break;

        /*
        case 'text_to_image':
        if (!preg_match('/^(dall-e|clip|gpt-image)/i', $model['id'])) {
        continue 2;
        }
        break;

        case 'speech_to_text':
        if (!preg_match('/^(whisper)/i', $model['id'])) {
        continue 2;
        }
        break;

        case 'text_to_speech':
        if (!preg_match('/^(tts)/i', $model['id'])) {
        continue 2;
        }
        break;
         */
      }

      // If its a vision model, we only allow it if the capability is set.
      if (in_array(AiModelCapability::ChatWithImageVision, $capabilities) && !preg_match('/^(mistral-small-)/i', $model['id'])) {
        continue;
      }

      // Include all GPT models for JSON output capability.
      if (in_array(AiModelCapability::ChatJsonOutput, $capabilities) && !preg_match('/^(gpt-oss-|mistral-small-|qwen3-coder-)/i', $model['id'])) {
        continue;
      }
      // Don't allow audio or video for now.
      if (in_array(AiModelCapability::ChatWithAudio, $capabilities)) {
        continue;
      }
      if (in_array(AiModelCapability::ChatWithVideo, $capabilities)) {
        continue;
      }

      $models[$model['id']] = $model['id'];
    }

    if (!empty($models)) {
      asort($models);
      $this->cacheBackend->set($cache_key, $models);
    }

    return $models;
  }

  /**
   * Heuristic to determine if a model is a reasoning model.
   *
   * Reasoning models have different token usage breakdowns and settings.
   *
   * @param string $modelId
   *   The model ID to check.
   *
   * @return bool
   *   TRUE if the model is likely a reasoning model, FALSE otherwise.
   */
  protected function isReasoningModel(string $modelId): bool {
    $id = strtolower($modelId);

    if (str_starts_with($id, 'gpt-oss-')) {
      return TRUE;
    }

    return FALSE;
  }

}
