<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhotobookProject extends Model
{
    protected $fillable = [
        'folder_hash',
        'folder',
        'pages_json',
        'cover_json',
        'analysis_version',
    ];

    protected $casts = [
        'pages_json' => 'array',
        'cover_json' => 'array',
    ];

    public function overrides(): HasMany
    {
        return $this->hasMany(PhotobookOverride::class, 'project_id');
    }

    /** Find or create project by folder hash. */
    public static function forHash(string $folderHash, ?string $folder = null): self
    {
        return static::firstOrCreate(
            ['folder_hash' => $folderHash],
            ['folder' => $folder]
        );
    }
}
