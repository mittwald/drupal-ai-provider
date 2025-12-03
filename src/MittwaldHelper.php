<?php

namespace Drupal\ai_provider_mittwald;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;

/**
 * Small helper commands that both form and provider needs.
 */
class MittwaldHelper {

  use StringTranslationTrait;

  /**
   * Configuration provider.
   */
  protected ConfigFactoryInterface $configFactory;

  public function __construct(
    private readonly MessengerInterface $messenger,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->configFactory = $config_factory;
  }

  /**
   * Check the rate limit and create a warning message if its free tier.
   *
   * @param string $api_key
   *   The API Key.
   */
  public function testRateLimit(string $api_key) {
    $headers = [];

    // Create a Guzzle client with a handler to capture response headers.
    $guzzle = new Client([
      'on_stats' => function (TransferStats $stats) use (&$headers) {
        if ($stats->hasResponse()) {
            $headers = $stats->getResponse()->getHeaders();
        }
      },
    ]);

    // Build the endpoint from config.
    $host     = $this->configFactory->get('ai_provider_mittwald.settings')->get('host');
    $endpoint = 'https://' . ($host ?: 'llm.aihosting.mittwald.de/v1') . '/chat/completions';

    // We need to catch errors, since the API key might be invalid, so plain
    // Guzzle is used.
    $content = $guzzle->request('POST', $endpoint, [
      'headers'     => [
        'Authorization' => 'Bearer ' . $api_key,
      ],
          // Do not throw errors.
      'http_errors' => FALSE,
      'json'        => [
        'model'    => 'Mistral-Small-3.2-24B-Instruct',
        'messages' => [
                  [
                    'role'    => 'user',
                    'content' => 'Answer with Hello',
                  ],
        ],
      ],
    ]);

    $response = Json::decode($content->getBody()->getContents());
    if ((isset($response['error']['code']) && $response['error']['code'] === 'insufficient_quota') || (isset($headers['x-ratelimit-limit-requests'][0]) && $headers['x-ratelimit-limit-requests'][0] <= 200)) {
      $this->messenger->addError($this->t('You have exceeded your mittwald AI usage quota. This will limit almost all the ways you can use AI in Drupal. You can read more here <a href=":link" target="_blank">:link</a>.', [
        ':link' => 'https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/terms-of-use/',
      ]));
    }
  }

}
