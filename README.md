## DreamFactory AI

> **Note:** This repository contains an AI service package for DreamFactory. If you want the full DreamFactory platform, visit the main [DreamFactory repository](https://github.com/dreamfactorysoftware/dreamfactory).

DreamFactory is a secure, self-hosted enterprise data access platform that provides governed API access to any data source, connecting enterprise applications and on-prem LLMs with role-based access and identity passthrough.

## Overview

DreamFactory AI (`df-ai`) is a service connection package that provides unified REST API access to LLM providers. It adds the `ai_connection` service type to DreamFactory, allowing administrators to configure AI providers and expose them as governed API endpoints with role-based access control.

### Supported Providers

| Provider | Completion | Chat | Models | Embeddings |
|----------|:---:|:---:|:---:|:---:|
| Anthropic (Claude) | x | x | x | |
| OpenAI (GPT) | x | x | x | x |
| xAI (Grok) | x | x | x | x |
| Ollama (local) | x | x | x | x |
| OpenAI-Compatible | x | x | x | x |

### Resources

Each AI Connection service exposes the following REST resources:

- `POST /{service}/completion` - Single-turn text completion
- `POST /{service}/chat` - Multi-turn conversation
- `POST /{service}/embeddings` - Vector embeddings
- `GET /{service}/models` - List available models
- `GET /{service}/health` - Provider health check
- `GET /{service}/usage` - Usage statistics and token counts

### Features

- Encrypted API key storage with masked display in admin UI
- Per-service configuration (model, temperature, max tokens, system prompt)
- Automatic base URL detection for known providers
- Usage logging with token counts and latency tracking
- Allowed model filtering per service
- OpenAPI/Swagger documentation for all endpoints

## Requirements

- PHP 8.3+
- DreamFactory 7.x+ with `df-core`

## Installation

Add the package to your DreamFactory `composer.json`:

```json
"require": {
    "dreamfactory/df-ai": "~1.0"
}
```

Run `composer update` and the service provider will be auto-discovered via Laravel's package discovery.

## Configuration

Create a new service in the DreamFactory admin panel:

1. Go to **Services** > **Create**
2. Select **AI Connection** from the service type list
3. Choose a provider, enter your API key, and configure defaults
4. Save and use the service API endpoints


### License

DreamFactory AI is open-sourced software available for use under the [Apache Version 2.0 license](http://www.apache.org/licenses/LICENSE-2.0).
