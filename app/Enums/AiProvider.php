<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/** KI-Anbieter für den Copilot. Die passenden Clients stehen in App\Services\Ai\AiProviderRegistry. */
enum AiProvider: string implements HasDescription, HasLabel
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case OpenRouter = 'openrouter';
    case OpenAiCompatible = 'openai_compatible';

    public function getLabel(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic (Claude)',
            self::OpenAi => 'OpenAI (ChatGPT)',
            self::Gemini => 'Google Gemini',
            self::OpenRouter => 'OpenRouter',
            self::OpenAiCompatible => 'OpenAI-kompatibel (Mistral, Ollama, LM Studio …)',
        };
    }

    /** Kurzname für Tabellen. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
            self::Gemini => 'Gemini',
            self::OpenRouter => 'OpenRouter',
            self::OpenAiCompatible => 'OpenAI-kompatibel',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Anthropic => 'API-Schlüssel aus der Claude Console.',
            self::OpenAi => 'API-Schlüssel von platform.openai.com.',
            self::Gemini => 'API-Schlüssel aus Google AI Studio.',
            self::OpenRouter => 'Ein Schlüssel für viele Modelle; Modell-ID im Format anbieter/modell.',
            self::OpenAiCompatible => 'Eigene Basis-URL mit /v1/chat/completions, Schlüssel optional.',
        };
    }

    /** Standard-Basis-URL; null = fest im Client bzw. Pflichtfeld. */
    public function defaultBaseUrl(): ?string
    {
        return match ($this) {
            self::OpenAi => 'https://api.openai.com/v1',
            self::OpenRouter => 'https://openrouter.ai/api/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
            default => null,
        };
    }

    public function requiresBaseUrl(): bool
    {
        return $this === self::OpenAiCompatible;
    }

    public function requiresApiKey(): bool
    {
        return $this !== self::OpenAiCompatible;
    }

    /** Vorschläge für das Modell-Feld (frei überschreibbar). @return list<string> */
    public function suggestedModels(): array
    {
        return match ($this) {
            self::Anthropic => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'],
            default => [],
        };
    }

    public function modelPlaceholder(): string
    {
        return match ($this) {
            self::Anthropic => 'claude-opus-5-5',
            self::OpenRouter => 'anbieter/modell',
            default => 'Modell-ID laut Anbieter',
        };
    }
}
