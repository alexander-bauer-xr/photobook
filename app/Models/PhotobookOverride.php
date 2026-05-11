<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotobookOverride extends Model
{
    protected $fillable = [
        'project_id',
        'page_id',
        'data_json',
    ];

    protected $casts = [
        'data_json' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(PhotobookProject::class, 'project_id');
    }

    /** Upsert a page override for a project. */
    public static function upsertPage(int $projectId, string $pageId, array $data): self
    {
        return static::updateOrCreate(
            ['project_id' => $projectId, 'page_id' => $pageId],
            ['data_json' => $data]
        );
    }
}
