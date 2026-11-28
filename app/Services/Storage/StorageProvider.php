<?php

namespace App\Services\Storage;

/**
 * Contract every external storage driver implements. BOS keeps only metadata;
 * the file itself lives in the provider and is opened there.
 *
 * Folder ids are whatever the provider uses to address a folder: an item id (Google Drive,
 * OneDrive) or a path (Dropbox, WebDAV). Every method throws StorageException on failure.
 */
interface StorageProvider
{
    /** Check credentials and reachability; returns a label for the connected account. */
    public function test(): string;

    /** The BOS root folder (created on first use); returns its folder id. */
    public function rootFolder(): string;

    /** Find or create a child folder by name; returns its folder id. */
    public function ensureChild(string $parentId, string $name): string;

    /**
     * Upload a local file into a folder. An existing file with the same name is never overwritten.
     *
     * @return array{external_id: string, url: ?string, name: string, size: int, mime: ?string}
     */
    public function upload(string $localPath, string $name, string $mime, string $folderId): array;

    /** Delete (or move to the provider's trash) a file. A file that is already gone is not an error. */
    public function delete(string $externalId): void;

    /** The file's content as a streamed HTTP response. */
    public function read(string $externalId): \Illuminate\Http\Client\Response;
}
