<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Models\AiModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Chat-Completions-Schnittstelle: OpenAI, OpenRouter und kompatible Server (Mistral, Ollama, LM Studio …). */
class OpenAiCompatibleClient implements AiClient
{
    public function complete(AiModel $model, string $system, string $prompt): string
    {
        $base = $model->baseUrl() ?? throw new AiException('Für diesen Anbieter fehlt die Basis-URL.');
        $label = $model->provider->getLabel();

        $request = Http::timeout(120)->connectTimeout(10)->acceptJson()->asJson();
        if (filled($model->api_key)) {
            $request = $request->withToken((string) $model->api_key);
        }
        if ($model->provider === AiProvider::OpenRouter) {
            $request = $request->withHeaders(['X-Title' => 'Network-Tools']);
        }

        try {
            $response = $request->post("{$base}/chat/completions", [
                'model' => $model->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
        } catch (ConnectionException $e) {
            throw new AiException("{$label} nicht erreichbar: ".mb_strimwidth($e->getMessage(), 0, 200, '…'));
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? mb_strimwidth($response->body(), 0, 300, '…');
            throw new AiException("{$label}: HTTP {$response->status()} – {$detail}");
        }

        $content = $response->json('choices.0.message.content');
        // Manche Server liefern den Inhalt als Liste von Teilen
        if (is_array($content)) {
            $content = implode('', array_map(fn ($p) => is_array($p) ? ($p['text'] ?? '') : (string) $p, $content));
        }

        return filled($content) ? trim((string) $content) : throw new AiException("{$label}: leere Antwort.");
    }
}
