<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Faq extends Model
{
    use HasFactory;

    protected $fillable = ['salon_id', 'question', 'answer', 'source_fingerprint'];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }
}
