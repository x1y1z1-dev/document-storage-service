<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FileRecord extends Model
{
    protected $fillable = [
        'original_filename',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'upload_timestamp',
        'expiration_timestamp',
    ];

    protected $casts = [
        'file_size_bytes'      => 'integer',
        'upload_timestamp'     => 'datetime',
        'expiration_timestamp' => 'datetime',
    ];

    /**
     * Scope: records whose retention period has elapsed.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expiration_timestamp', '<=', now()->utc());
    }
}
