<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistribucionVersion extends Model
{
    protected $table = 'distribucion_versiones';

    protected $fillable = [
        'distribucion_id', 'evento', 'user_id', 'user_nombre', 'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function distribucion()
    {
        return $this->belongsTo(Distribucion::class, 'distribucion_id');
    }
}