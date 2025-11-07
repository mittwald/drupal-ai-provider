# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Drupal module (`ai_provider_mittwald`) that provides a mittwald AI provider integration for the Drupal AI module. It enables Drupal sites to use mittwald's AI hosting services as a provider for various AI operations.

## Architecture

### Plugin System
The module extends Drupal's AI module plugin architecture. The main provider plugin is `MittwaldProvider` (src/Plugin/AiProvider/MittwaldProvider.php), which extends `OpenAiBasedProviderClientBase` since mittwald uses an OpenAI-compatible API.

### Key Components

- **MittwaldProvider**: Main plugin that handles AI operations. Currently implements chat and embeddings fully; moderation, text_to_image, text_to_speech, and speech_to_text throw "not implemented" exceptions.
- **MittwaldHelper**: Service class that provides utility functions, primarily for rate limit validation during setup.
- **MittwaldConfigForm**: Configuration form for API key management using Drupal's Key module.
- **MittwaldChatMessageIterator**: Extends `StreamedChatMessageIterator` to handle streaming chat responses from the mittwald API.

### Configuration
- Config is stored in `ai_provider_mittwald.settings` with keys: `api_key`, `moderation`, `host`
- API keys are managed through the Drupal Key module (not stored directly in config)
- Default API endpoint: `llm.aihosting.mittwald.de/v1` (can be overridden via `host` config)
- Default models defined in `getSetupData()` method of MittwaldProvider

### Model Filtering
The `getModels()` method filters available models based on operation type using regex patterns:
- Chat: `gpt-oss`, `mistral-small-`, `qwen3-coder-` prefixes
- Embeddings: `qwen3-embedding` prefix
- Vision capability requires `mistral-small-` models

## Development Commands

### Code Quality
```bash
# Run PHP CodeSniffer (via GrumPHP)
vendor/bin/grumphp run

# Run PHPStan static analysis
vendor/bin/phpstan analyse
```

GrumPHP is configured with:
- PHPCS using Drupal and DrupalPractice standards
- Git blacklist checking for debugging statements (var_dump, die, dpm, print_r)

PHPStan is configured at level 1 and checks .php, .module, .inc, and .install files.

### Dependencies
```bash
# Install dependencies
composer install
```

## Important Implementation Details

### API Authentication
- Uses bearer token authentication via the Key module
- API key validation occurs in `MittwaldConfigForm::validateForm()` by attempting to fetch model list
- Rate limit checking happens in `MittwaldHelper::testRateLimit()` via a test chat completion request

### Drupal AI Module Integration
- Implements AI operation types as defined by the base Drupal AI module
- Uses `definitions/api_defaults.yml` to define default configurations for each operation type
- Configuration options include temperature, max_tokens, frequency_penalty, presence_penalty, top_p for chat operations

### Module Dependencies
- `drupal/ai`: ^1.2.0 - The base AI module
- `drupal/key`: ^1.18 - For secure API key storage
