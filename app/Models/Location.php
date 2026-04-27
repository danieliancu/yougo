<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = ['salon_id', 'name', 'address', 'email', 'phone', 'hours'];

    protected function casts(): array
    {
        return [
            'hours' => 'array',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }
}
