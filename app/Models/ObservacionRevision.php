<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservacionRevision extends Model
{
    protected $table = 'observaciones_revision';

    protected $fillable = [
        'codigo_proyecto',
        'observacion',
        'user_id',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
