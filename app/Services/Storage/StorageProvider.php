<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;

/**
 * Contract every external storage driver implements. BOS keeps only metadata;
 * the file itself lives in the provider and is opened there.
 */
interface StorageProvider
{
    /** Check credentials and reachability. */
    public function test(): bool;

    /** Create (or find) a folder by path segments under the root folder; returns the provider folder id. */
    public function ensureFolder(array $segments): string;

    /**
     * Upload a file into a folder.
     *
     * @return array{external_id: string, url: string, name: string, size: int, mime: string}
     */
    public function upload(UploadedFile $file, string $folderId): array;

    public function delete(string $externalId): void;
}
