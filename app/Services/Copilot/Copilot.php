<?php

namespace App\Services\Copilot;

use App\Models\AiModel;
use App\Services\Ai\AiProviderRegistry;
use Illuminate\Support\Facades\Cache;

/** Holt Empfehlungen zu Prüfergebnissen beim gewählten KI-Modell; gleiche Anfragen kommen aus dem Cache. */
class Copilot
{
    public const CACHE_DAYS = 7;

    public function __construct(private readonly AiProviderRegistry $providers) {}

    /** @param list<array> $results */
    public function recommend(AiModel $model, string $domain, array $results, ?string $check = null, bool $fresh = false): string
    {
        $system = CopilotPrompt::system();
        $prompt = CopilotPrompt::user($domain, $results, $check);
        $key = 'copilot:'.hash('sha256', implode('|', [$model->id, $model->provider->value, $model->model, $model->updated_at, $system, $prompt]));

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addDays(self::CACHE_DAYS), fn () => $this->providers->complete($model, $system, $prompt));
    }
}
