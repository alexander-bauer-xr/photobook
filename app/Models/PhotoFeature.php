<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhotoFeature extends Model
{
    protected $fillable = ['path','phash','sharpness','faces','aesthetic','saliency','horizon_deg'];
    protected $casts = [
        'faces' => 'array',
        'saliency' => 'array',
    ];
}
