<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhotoFeature extends Model
{
    protected $fillable = [
        'path',
        'phash',
        'sharpness',
        'faces',
        'aesthetic',
        'saliency',
        'horizon_deg',
        'suggested_crop',
        'dominant_colors',
        'analysis_version',
        'analyzed_at',
    ];

    protected $casts = [
        'faces'           => 'array',
        'saliency'        => 'array',
        'suggested_crop'  => 'array',
        'dominant_colors' => 'array',
        'analyzed_at'     => 'datetime',
    ];
}
