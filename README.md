# mittwald AI Provider for Drupal

A Drupal module that integrates [mittwald AI Hosting](https://developer.mittwald.de/docs/v2/platform/aihosting/) with the Drupal AI module, enabling AI-powered features on your Drupal site using mittwald's infrastructure.

## About

This module provides a mittwald provider implementation for the [Drupal AI module](https://www.drupal.org/project/ai), allowing you to leverage mittwald's AI hosting platform for various AI operations including chat completions, embeddings, and more.

**Credit**: This module was forked from the [`ai_provider_openai`](https://www.drupal.org/project/ai_provider_openai) module and adapted for mittwald's AI hosting platform, which uses an OpenAI-compatible API.

## Requirements

- Drupal 10.3+ or Drupal 11+
- [AI module](https://www.drupal.org/project/ai) (^1.2.0)
- [Key module](https://www.drupal.org/project/key) (^1.18)
- A [mittwald AI Hosting](https://www.mittwald.de/mstudio/ai-hosting) account with API access

## Installation

Install this module using Composer:

```bash
composer require mittwald/drupal-ai-provider
```

Enable the module:

```bash
drush en ai_provider_mittwald
```

## Configuration

1. **Obtain an API Key**: Follow the [mittwald AI Hosting access guide](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/access/) to get your API credentials.

2. **Create a Key Entity**:
   - Navigate to `/admin/config/system/keys/add`
   - Create a new key for storing your mittwald API key
   - Choose an appropriate key type (e.g., "Authentication")

3. **Configure the Provider**:
   - Navigate to `/admin/config/ai/providers/mittwald`
   - Select your API key from the dropdown
   - Save the configuration

The module will automatically validate your API key and check for rate limits during configuration.

## Supported Operations & Models

### Chat Completions ✅

Fully supported for conversational AI, content generation, and chat-based interactions.

**Available Models:**
- **GPT-OSS models**: Open-source GPT-compatible models
- **Mistral Small**: `mistral-small-*` (supports vision/image input)
- **Qwen3 Coder**: `qwen3-coder-*` - optimized for code generation

**Capabilities:**
- Standard text chat
- Image vision (Mistral Small models only)
- JSON output formatting
- Tool/function calling
- Streaming responses

**Default Model**: `Mistral-Small-3.2-24B-Instruct`

### Embeddings ✅

Fully supported for semantic search, similarity matching, and vector operations.

**Available Models:**
- **Qwen3 Embedding**: `Qwen3-Embedding-8B` (4096 dimensions)

**Default Model**: `Qwen3-Embedding-8B`

### Text to Image ⏸️

Not currently supported by mittwald AI Hosting.

### Text to Speech ⏸️

Not currently supported by mittwald AI Hosting.

### Speech to Text ⏸️

Not currently supported by mittwald AI Hosting.

### Moderation ⏸️

Not currently supported by mittwald AI Hosting.

## Usage Examples

### Using Chat Completions

```php
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

$provider = \Drupal::service('ai.provider')->createInstance('mittwald');

$input = new ChatInput([
  new ChatMessage('user', 'What is the capital of France?'),
]);

$response = $provider->chat($input, 'Mistral-Small-3.2-24B-Instruct');
echo $response->getNormalized()->getText();
```

### Using Embeddings

```php
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;

$provider = \Drupal::service('ai.provider')->createInstance('mittwald');

$input = new EmbeddingsInput('Your text to embed');
$response = $provider->embeddings($input, 'Qwen3-Embedding-8B');

$vector = $response->getNormalized();
// $vector is a 4096-dimensional array
```

## Configuration Options

### Chat Configuration

- **Temperature** (0-2): Controls randomness. Lower is more focused, higher is more creative. Default: 1
- **Max Tokens** (0-4096): Maximum tokens in the response. Default: 4096
- **Top P** (0-1): Nucleus sampling parameter. Default: 1
- **Frequency Penalty** (-2 to 2): Reduces repetition of token sequences. Default: 0
- **Presence Penalty** (-2 to 2): Encourages new topics. Default: 0

### Custom Endpoint

You can configure a custom API endpoint by setting the `host` configuration value in `ai_provider_mittwald.settings`.

## Rate Limits & Quotas

mittwald AI Hosting has usage limits based on your account tier. The module automatically checks rate limits during setup and will display a warning if you're on a free tier with restricted quota.

For details on usage limits and terms, see the [mittwald AI Hosting terms of use](https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/terms-of-use/).

## Resources

- **mittwald AI Hosting Documentation**: https://developer.mittwald.de/docs/v2/platform/aihosting/
- **API Access Guide**: https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/access/
- **Terms of Use**: https://developer.mittwald.de/docs/v2/platform/aihosting/access-and-usage/terms-of-use/
- **Drupal AI Module**: https://www.drupal.org/project/ai

## Development

### Code Quality Tools

This module uses GrumPHP for automated code quality checks:

```bash
# Run all checks
vendor/bin/grumphp run

# Run PHPStan static analysis
vendor/bin/phpstan analyse
```

### Coding Standards

- Follows Drupal coding standards
- PHPStan level 1 compliance
- No debugging statements allowed in commits

## Contributing

Contributions are welcome! Areas where help is especially appreciated:

- Adding tests
- Documentation improvements
- Bug fixes and performance improvements

Please ensure your code passes all GrumPHP checks before submitting a pull request.

## License

GPL-2.0-or-later

## Support

For issues related to:
- **This module**: Open an issue in this repository
- **mittwald AI Hosting**: See [mittwald documentation](https://developer.mittwald.de/docs/v2/platform/aihosting/)
- **Drupal AI module**: See the [project page](https://www.drupal.org/project/ai)
