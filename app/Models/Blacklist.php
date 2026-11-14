<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Blacklist extends Model
{
    protected $fillable = ['name', 'zone', 'type', 'enabled', 'info_url', 'description'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
