<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckResult extends Model
{
    protected $fillable = ['check_run_id', 'domain_id', 'check', 'status', 'summary', 'details', 'state', 'fingerprint'];

    protected function casts(): array
    {
        return [
            'check' => CheckType::class,
            'status' => CheckStatus::class,
            'details' => 'array',
            'state' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CheckRun::class, 'check_run_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
