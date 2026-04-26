<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    use HasFactory;

    protected $fillable = ['salon_id', 'name', 'type', 'staff', 'price', 'duration', 'location_ids', 'notes'];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'staff' => 'array',
            'location_ids' => 'array',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }
}
