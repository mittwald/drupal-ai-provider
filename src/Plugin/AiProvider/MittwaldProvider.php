<?php

namespace Drupal\ai_provider_mittwald\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\Exception\AiUnsafePromptException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\GenericType\AudioFile;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\Moderation\ModerationInput;
use Drupal\ai\OperationType\Moderation\ModerationOutput;
use Drupal\ai\OperationType\Moderation\ModerationResponse;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechInput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ai_provider_mittwald\MittwaldChatMessageIterator;
use Drupal\ai_provider_mittwald\MittwaldHelper;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\File\FileExists;
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
class MittwaldProvider extends OpenAiBasedProviderClientBase
{


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
    protected bool|null $moderation = null;

    /**
     * The logger.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $parent_instance                 = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $parent_instance->mittwaldHelper = $container->get('ai_provider_mittwald.helper');
        $parent_instance->logger         = $container->get('logger.factory')->get('ai_provider_mittwald');
        return $parent_instance;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguredModels(?string $operation_type = null, array $capabilities = []): array
    {
        // Load all models, and since mittwald does not provide information about
        // which models does what, we need to hard code it in a helper function.
        $this->loadClient();
        return $this->getModels($operation_type ?? '', $capabilities);
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedOperationTypes(): array
    {
        return [
            'chat',
            'embeddings',
            'moderation',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getModelSettings(string $model_id, array $generalConfig = []): array
    {
        if ($model_id == 'Qwen3-Embedding-8B') {
            $generalConfig['dimensions']['default'] = 4096;
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
    public function getClient(string $api_key = ''): Client
    {
        // If the moderation is not set, we load it from the configuration.
        if (is_null($this->moderation)) {
            $this->moderation = $this->getConfig()->get('moderation');
        }
        return parent::getClient($api_key);
    }

    /**
     * {@inheritdoc}
     */
    protected function loadClient(): void
    {
        // Set custom endpoint from host config if available.
        if (!empty($this->getConfig()->get('host'))) {
            $this->setEndpoint($this->getConfig()->get('host'));
        } else {
            $this->setEndpoint('llm.aihosting.mittwald.de/v1');
        }

        try {
            parent::loadClient();
        } catch (AiSetupFailureException $e) {
            throw new AiSetupFailureException('Failed to initialize mittwald client: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function _zzzdisabled_chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput
    {
        $this->loadClient();
        // Normalize the input if needed.
        $chat_input = $input;
        if ($input instanceof ChatInput) {
            $chat_input = [];
            // Add a system role if wanted.
            if ($this->chatSystemRole) {
                // If its o1 or o3 in it, we add it as a user message.
                if (preg_match('/(o1|o3)/i', $model_id)) {
                    $chat_input[] = [
                        'role'    => 'user',
                        'content' => $this->chatSystemRole,
                    ];
                } else {
                    $chat_input[] = [
                        'role'    => 'system',
                        'content' => $this->chatSystemRole,
                    ];
                }
            }
            /** @var \Drupal\ai\OperationType\Chat\ChatMessage $message */
            foreach ($input->getMessages() as $message) {
                $content = [
                    [
                        'type' => 'text',
                        'text' => $message->getText(),
                    ],
                ];
                if (method_exists($message, 'getFiles') && count($message->getFiles())) {
                    foreach ($message->getFiles() as $file) {
                        if ($file instanceof ImageFile) {
                            $content[] = [
                                'type'      => 'image_url',
                                'image_url' => [
                                    'url' => $file->getAsBase64EncodedString(),
                                ],
                            ];
                        } elseif ($file->getMimeType() === 'application/pdf') {
                            $content[] = [
                                'type' => 'file',
                                'file' => [
                                    'filename'  => $file->getFilename(),
                                    'file_data' => $file->getAsBase64EncodedString(),
                                ],
                            ];
                        }
                    }
                } elseif (count($message->getImages())) {
                    foreach ($message->getImages() as $image) {
                        $content[] = [
                            'type'      => 'image_url',
                            'image_url' => [
                                'url' => $image->getAsBase64EncodedString(),
                            ],
                        ];
                    }
                }
                $new_message = [
                    'role'    => $message->getRole(),
                    'content' => $content,
                ];

                // If it's a tool's response.
                if ($message->getToolsId()) {
                    $new_message['tool_call_id'] = $message->getToolsId();
                }

                // If we want the results from some older tools call.
                if ($message->getTools()) {
                    $new_message['tool_calls'] = $message->getRenderedTools();
                }

                $chat_input[] = $new_message;
            }
        }
        // Moderation check - tokens are still there using json.
        //$this->moderationEndpoints(json_encode($chat_input));

        $payload = [
                'model'    => $model_id,
                'messages' => $chat_input,
            ] + $this->configuration;
        // If we want to add tools to the input.
        if (is_object($input) && method_exists($input, 'getChatTools') && $input->getChatTools()) {
            $payload['tools'] = $input->getChatTools()->renderToolsArray();
            foreach ($payload['tools'] as $key => $tool) {
                $payload['tools'][$key]['function']['strict'] = false;
            }
        }
        // Check for structured json schemas.
        if (is_object($input) && method_exists($input, 'getChatStructuredJsonSchema') && $input->getChatStructuredJsonSchema()) {
            $payload['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => $input->getChatStructuredJsonSchema(),
            ];
        }
        // Include usage for streamed responses.
        if ($this->streamed) {
            $payload['stream_options']['include_usage'] = true;
        }
        try {
            if ($this->streamed) {
                $response = $this->client->chat()->createStreamed($payload);
                $message  = new MittwaldChatMessageIterator($response);
            }
            // If we are in a fibre, we will use a streamed response as the SDK
            // doesn't support direct async.
            elseif (\Fiber::getCurrent()) {
                $payload['stream_options'] = [
                    'include_usage' => true,
                ];
                $response                  = $this->client->chat()->createStreamed($payload);
                $stream                    = new MittwaldChatMessageIterator($response);
                // We consume the stream in a fiber.
                foreach ($stream as $chunk) {
                    // Suspend fiber if we haven't finished yet.
                    if (empty($stream->getFinishReason())) {
                        \Fiber::suspend();
                    }
                }

                // Create the final message from accumulated data.
                $message = $stream->reconstructChatOutput()->getNormalized();
            } else {
                $response = $this->client->chat()->create($payload)->toArray();
                // If tools are generated.
                $tools = [];
                if (!empty($response['choices'][0]['message']['tool_calls'])) {
                    foreach ($response['choices'][0]['message']['tool_calls'] as $tool) {
                        $arguments = Json::decode($tool['function']['arguments']);
                        $tools[]   = new ToolsFunctionOutput($input->getChatTools()->getFunctionByName($tool['function']['name']), $tool['id'], $arguments);
                    }
                }
                $message = new ChatMessage($response['choices'][0]['message']['role'], $response['choices'][0]['message']['content'] ?? "", []);
                if (!empty($tools)) {
                    $message->setTools($tools);
                }
            }
        } catch (\Exception $e) {
            // Try to figure out rate limit issues.
            if (strpos($e->getMessage(), 'Request too large') !== false) {
                throw new AiRateLimitException($e->getMessage());
            }
            if (strpos($e->getMessage(), 'Too Many Requests') !== false) {
                throw new AiRateLimitException($e->getMessage());
            }
            // Try to figure out quota issues.
            if (strpos($e->getMessage(), 'You exceeded your current quota') !== false) {
                throw new AiQuotaException($e->getMessage());
            } else {
                throw $e;
            }
        }

        $chat_output = new ChatOutput($message, $response, []);

        // We only set the token usage if its not streamed or in a fiber.
        if (!$this->streamed && !\Fiber::getCurrent()) {
            $this->setChatTokenUsage($chat_output, $response);
        }

        return $chat_output;
    }

    /**
     * {@inheritdoc}
     */
    public function moderation(string|ModerationInput $input, ?string $model_id = null, array $tags = []): ModerationOutput
    {
        throw new \Exception("not implemented");
    }

    /**
     * {@inheritdoc}
     */
    public function textToImage(string|TextToImageInput $input, string $model_id, array $tags = []): TextToImageOutput
    {
        throw new \Exception("not implemented");
    }

    /**
     * {@inheritdoc}
     */
    public function textToSpeech(string|TextToSpeechInput $input, string $model_id, array $tags = []): TextToSpeechOutput
    {
        throw new \Exception("not implemented");
    }

    /**
     * {@inheritdoc}
     */
    public function speechToText(string|SpeechToTextInput $input, string $model_id, array $tags = []): SpeechToTextOutput
    {
        throw new \Exception("not implemented");
    }

    /**
     * {@inheritdoc}
     */
    public function getSetupData(): array
    {
        return [
            'key_config_name' => 'api_key',
            'default_models'  => [
                'chat'                          => 'Mistral-Small-3.2-24B-Instruct',
                'chat_with_image_vision'        => 'Mistral-Small-3.2-24B-Instruct',
                'chat_with_complex_json'        => 'Mistral-Small-3.2-24B-Instruct',
                'chat_with_tools'               => 'Mistral-Small-3.2-24B-Instruct',
                'chat_with_structured_response' => 'Mistral-Small-3.2-24B-Instruct',
                'embeddings'                    => 'Qwen3-Embedding-8B',
//                'text_to_image'                 => 'dall-e-3',
//                'moderation'                    => 'omni-moderation-latest',
//                'text_to_speech'                => 'tts-1-hd',
//                'speech_to_text'                => 'whisper-1',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function postSetup(): void
    {
        // Throw an error on installation with rate limit.
        $this->mittwaldHelper->testRateLimit($this->loadApiKey());
    }

    /**
     * {@inheritdoc}
     */
    public function embeddingsVectorSize(string $model_id): int
    {
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
    public function getModels(string $operation_type, $capabilities): array
    {
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

//        case 'text_to_image':
//          if (!preg_match('/^(dall-e|clip|gpt-image)/i', $model['id'])) {
//            continue 2;
//          }
//          break;
//
//        case 'speech_to_text':
//          if (!preg_match('/^(whisper)/i', $model['id'])) {
//            continue 2;
//          }
//          break;
//
//        case 'text_to_speech':
//          if (!preg_match('/^(tts)/i', $model['id'])) {
//            continue 2;
//          }
//          break;
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
    protected function isReasoningModel(string $modelId): bool
    {
        $id = strtolower($modelId);

        if (str_starts_with($id, 'gpt-oss-')) {
            return true;
        }

        return false;
    }

}
