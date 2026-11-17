<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Google Gemini über die generateContent-Schnittstelle. */
class GeminiClient implements AiClient
{
    public function complete(AiModel $model, string $system, string $prompt): string
    {
        $url = $model->baseUrl().'/models/'.rawurlencode($model->model).':generateContent';

        try {
            $response = Http::timeout(120)->connectTimeout(10)->acceptJson()->asJson()
                ->withHeaders(['x-goog-api-key' => (string) $model->api_key])
                ->post($url, [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                ]);
        } catch (ConnectionException $e) {
            throw new AiException('Gemini nicht erreichbar: '.mb_strimwidth($e->getMessage(), 0, 200, '…'));
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? mb_strimwidth($response->body(), 0, 300, '…');
            throw new AiException("Gemini: HTTP {$response->status()} – {$detail}");
        }

        if ($response->json('promptFeedback.blockReason')) {
            throw new AiException('Gemini hat die Anfrage blockiert ('.$response->json('promptFeedback.blockReason').').');
        }

        $text = implode('', array_map(fn ($p) => $p['text'] ?? '', $response->json('candidates.0.content.parts') ?? []));

        return trim($text) !== '' ? trim($text) : throw new AiException('Gemini: leere Antwort.');
    }
}
