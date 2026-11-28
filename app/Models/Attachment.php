<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** See Services\Storage\Attachments: the only place that uploads, retries and deletes attachments. */
class Attachment extends Model
{
    use SoftDeletes;

    /** Failed uploads are retried automatically up to this many attempts; after that, by hand. */
    public const MAX_AUTO_ATTEMPTS = 5;

    protected $fillable = ['attachable_type', 'attachable_id', 'storage_connection_id', 'provider', 'name', 'size', 'mime', 'temp_path', 'uploaded_by'];

    protected $hidden = ['temp_path'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'attempts' => 'integer', 'uploaded_at' => 'datetime'];
    }

    public function isUploaded(): bool
    {
        return $this->status === 'uploaded';
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function storageConnection(): BelongsTo
    {
        return $this->belongsTo(StorageConnection::class, 'storage_connection_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
