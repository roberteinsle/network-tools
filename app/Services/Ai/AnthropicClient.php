<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\RequestOptions;
use App\Models\AiModel;
use Psr\Http\Client\ClientInterface;

/** Claude über das offizielle Anthropic-PHP-SDK. */
class AnthropicClient implements AiClient
{
    /** @param ClientInterface|null $transporter nur für Tests (PSR-18-Client statt echtem HTTP) */
    public function __construct(private readonly ?ClientInterface $transporter = null) {}

    public function complete(AiModel $model, string $system, string $prompt): string
    {
        $client = new Client(apiKey: (string) $model->api_key, baseUrl: $model->base_url ?: null);

        try {
            // Beta-Endpunkt nur für die serverseitigen Ausweichmodelle bei Ablehnungen ("default":
            // vom Server festgelegt; Modelle ohne Unterstützung laufen einfach allein).
            $message = $client->beta->messages->create(
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $model->model,
                system: $system,
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
                requestOptions: RequestOptions::with(timeout: 120, maxRetries: 2, transporter: $this->transporter),
            );
        } catch (AuthenticationException) {
            throw new AiException('Anthropic: API-Schlüssel ungültig.');
        } catch (NotFoundException) {
            throw new AiException("Anthropic: Modell „{$model->model}“ nicht gefunden.");
        } catch (RateLimitException) {
            throw new AiException('Anthropic: Ratenlimit erreicht – bitte später erneut versuchen.');
        } catch (APIStatusException $e) {
            throw new AiException('Anthropic: '.($e->type?->value ?? 'Fehler').' – '.mb_strimwidth($e->getMessage(), 0, 300, '…'));
        } catch (APIConnectionException $e) {
            throw new AiException('Anthropic nicht erreichbar: '.mb_strimwidth($e->getMessage(), 0, 200, '…'));
        }

        if ($message->stopReason === 'refusal') {
            throw new AiException('Das Modell hat die Anfrage abgelehnt.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return trim($text) !== '' ? trim($text) : throw new AiException('Anthropic: leere Antwort.');
    }
}
