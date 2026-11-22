<?php

namespace App\Models;

use App\Enums\NotifyLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'contact_email', 'notification_emails', 'notify_level', 'dkim_providers', 'notes'];

    protected function casts(): array
    {
        return [
            'notification_emails' => 'array',
            'dkim_providers' => 'array',
            'notify_level' => NotifyLevel::class,
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
}
