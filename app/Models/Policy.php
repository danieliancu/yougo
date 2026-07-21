<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Policy extends Model
{
    use HasFactory;

    protected $fillable = ['salon_id', 'title', 'content', 'source_fingerprint'];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }
}
