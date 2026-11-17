<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein im Backend angelegtes KI-Modell (Anbieter, Modell-ID, Schlüssel) für den Copilot.
 * Der API-Schlüssel liegt mit APP_KEY verschlüsselt in der Datenbank.
 */
class AiModel extends Model
{
    protected $fillable = ['name', 'provider', 'model', 'api_key', 'base_url', 'is_default', 'active'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'api_key' => 'encrypted',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Höchstens ein Standardmodell
        static::saved(function (AiModel $model): void {
            if ($model->is_default) {
                static::query()->whereKeyNot($model->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Standardmodell, sonst das erste aktive. */
    public static function preferred(): ?self
    {
        return static::query()->active()->orderByDesc('is_default')->orderBy('name')->first();
    }

    public function baseUrl(): ?string
    {
        return rtrim((string) ($this->base_url ?: $this->provider->defaultBaseUrl()), '/') ?: null;
    }
}
