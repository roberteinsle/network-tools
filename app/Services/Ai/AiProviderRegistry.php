<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Models\AiModel;

/** Ordnet jedem KI-Anbieter seinen Client zu. Neue Anbieter: Fall in AiProvider + Eintrag hier. */
class AiProviderRegistry
{
    /** @var array<string, class-string<AiClient>> */
    public const CLIENTS = [
        'anthropic' => AnthropicClient::class,
        'openai' => OpenAiCompatibleClient::class,
        'openrouter' => OpenAiCompatibleClient::class,
        'openai_compatible' => OpenAiCompatibleClient::class,
        'gemini' => GeminiClient::class,
    ];

    public function client(AiProvider $provider): AiClient
    {
        return app(self::CLIENTS[$provider->value]);
    }

    public function complete(AiModel $model, string $system, string $prompt): string
    {
        if ($model->provider->requiresApiKey() && blank($model->api_key)) {
            throw new AiException("Für „{$model->name}“ ist kein API-Schlüssel hinterlegt.");
        }

        return $this->client($model->provider)->complete($model, $system, $prompt);
    }
}
