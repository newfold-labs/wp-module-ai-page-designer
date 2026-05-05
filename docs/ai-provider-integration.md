# AI Provider Integration Guide

The `AiClient` class has been refactored to support easy integration of different AI API providers using centralized constants.

## Configuration Constants

The following constants define the configuration values and can be modified directly in the class:

| Constant | Description | Default Value |
|----------|-------------|---------------|
| `DEFAULT_MODEL` | AI model name | `gpt-5.4-mini` |
| `AI_API_ENDPOINT` | Standard API endpoint | `https://api-gw.builderservices.io/ai-api/v1/response` |
| `AI_STREAM_ENDPOINT` | Streaming API endpoint | `https://api-gw.builderservices.io/ai-api/v1/response/stream` |
| `JWT_WORKER_ENDPOINT` | JWT authentication endpoint | `https://cf-worker-newfold-services-jwt.bluehost.workers.dev/` |
| `DEFAULT_PROMPT_ID` | Default prompt ID | `4d5d7866-cbaf-4274-ad72-f789e358965d` |
| `DEFAULT_TIMEOUT` | HTTP request timeout (seconds) | `120` |
| `JWT_TIMEOUT` | JWT request timeout (seconds) | `30` |
| `MAX_OUTPUT_TOKENS` | Maximum output tokens | `5000` |

## How to Integrate Different AI Providers

### Option 1: Modify Constants Directly

Simply edit the constants in the `AiClient` class:

```php
class AiClient {
    // Change these constants for different providers
    const DEFAULT_MODEL = 'gpt-4o';
    const AI_API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const AI_STREAM_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const JWT_WORKER_ENDPOINT = 'https://your-auth-service.com/token';
    const DEFAULT_TIMEOUT = 180; // 3 minutes
    const MAX_OUTPUT_TOKENS = 8000;
    // ... rest of the class
}
```

### Option 2: Create Provider-Specific Subclasses

For more advanced scenarios, extend the `AiClient` class:

```php
class OpenAiClient extends AiClient {
    const DEFAULT_MODEL = 'gpt-4';
    const AI_API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const AI_STREAM_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const DEFAULT_TIMEOUT = 300;
}

class AnthropicClient extends AiClient {
    const DEFAULT_MODEL = 'claude-3-sonnet-20240229';
    const AI_API_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const AI_STREAM_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const DEFAULT_TIMEOUT = 180;
}
```

### Option 3: Environment-Based Configuration

Use different constants based on environment:

```php
class AiClient {
    const DEFAULT_MODEL = WP_DEBUG ? 'gpt-3.5-turbo' : 'gpt-4';
    const AI_API_ENDPOINT = WP_DEBUG 
        ? 'https://api-staging.example.com/ai' 
        : 'https://api.example.com/ai';
    const DEFAULT_TIMEOUT = WP_DEBUG ? 60 : 120;
    // ... rest of constants
}
```

## Example Integrations

### OpenAI Integration
```php
const DEFAULT_MODEL = 'gpt-4';
const AI_API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
const AI_STREAM_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
const DEFAULT_TIMEOUT = 300; // OpenAI can be slower
const MAX_OUTPUT_TOKENS = 4000; // GPT-4 limit
```

### Anthropic Claude Integration
```php
const DEFAULT_MODEL = 'claude-3-sonnet-20240229';
const AI_API_ENDPOINT = 'https://api.anthropic.com/v1/messages';
const AI_STREAM_ENDPOINT = 'https://api.anthropic.com/v1/messages';
const MAX_OUTPUT_TOKENS = 4096; // Claude limit
```

### Google Gemini Integration
```php
const DEFAULT_MODEL = 'gemini-pro';
const AI_API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1/models/gemini-pro:generateContent';
const DEFAULT_TIMEOUT = 180;
const MAX_OUTPUT_TOKENS = 8192;
```

## Benefits of This Approach

1. **Simplicity**: No complex filter system, just straightforward constants
2. **Clear Configuration**: All settings are visible in one place
3. **Easy Debugging**: No hidden filter modifications to trace
4. **Type Safety**: Constants provide better IDE support and error checking
5. **Performance**: No filter overhead during requests
6. **Maintainability**: Easier to understand and modify

## Migration Path

If you need more dynamic configuration later, you can easily add:

1. **Database-driven configuration**
2. **WordPress options integration**
3. **Environment variable support**
4. **Filter system** (if needed for plugins to hook into)

But for most use cases, simple constants provide the clearest and most maintainable solution.

## Next Steps

1. **Modify the constants** in `AiClient.php` to match your preferred AI provider
2. **Test the integration** with your new provider
3. **Update authentication logic** if your provider uses different auth methods
4. **Adjust request/response handling** if the API format is significantly different

The refactored code maintains full backward compatibility while providing a clean foundation for different AI provider integrations.