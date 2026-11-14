<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckRun extends Model
{
    use MassPrunable;

    protected $fillable = ['domain_id', 'status', 'trigger', 'started_at', 'finished_at', 'duration_ms', 'changes', 'changes_count', 'notified_at'];

    protected function casts(): array
    {
        return [
            'status' => CheckStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'notified_at' => 'datetime',
            'changes' => 'array',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(CheckResult::class)->orderBy('id');
    }

    /** Alte Protokolle löschen, aber den letzten Lauf jeder Domain immer behalten. */
    public function prunable(): Builder
    {
        $days = AppSetting::current()->retention_days ?: 180;

        return static::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotIn('id', static::query()->selectRaw('max(id)')->groupBy('domain_id'));
    }
}
