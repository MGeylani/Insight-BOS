<?php

namespace App\Services\Storage;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Task;
use App\Models\StorageConnection;
use App\Models\StorageFolder;
use App\Services\Storage\Drivers\Driver;
use Illuminate\Database\Eloquent\Model;

/**
 * Folder layout in the provider, created on first upload and remembered in storage_folders:
 *
 *   Insight BOS/
 *     العملاء/
 *       <client name> #<id>/            client files
 *         العقود/                         contract files (signed copies)
 *         عروض الأسعار/                    quote files
 *         المشاريع/
 *           <PRJ-2026-0001 · name>/        project files
 *
 * Folders are remembered per record, so renaming a client or project keeps using its folder.
 */
class StorageManager
{
    /** The active provider, when it is connected. */
    public static function connected(): ?StorageConnection
    {
        $c = StorageConnection::active();

        return $c && $c->status === 'connected' ? $c : null;
    }

    public static function folderFor(StorageConnection $c, Model $owner): string
    {
        $driver = Providers::driver($c);

        return match (true) {
            $owner instanceof Client => self::remember($c, "client:{$owner->id}", fn () => self::path($driver,
                self::root($c, $driver), ['العملاء', Driver::cleanName($owner->name).' #'.$owner->id])),
            $owner instanceof Project => self::remember($c, "project:{$owner->id}", fn () => self::path($driver,
                self::folderFor($c, $owner->client), ['المشاريع', $owner->label()])),
            $owner instanceof Contract => self::remember($c, "client:{$owner->client_id}:contracts", fn () => self::path($driver,
                self::folderFor($c, $owner->client), ['العقود'])),
            $owner instanceof Quote => self::remember($c, "client:{$owner->client_id}:quotes", fn () => self::path($driver,
                self::folderFor($c, $owner->client), ['عروض الأسعار'])),
            $owner instanceof \App\Models\Invoice => self::remember($c, "client:{$owner->client_id}:invoices", fn () => self::path($driver,
                self::folderFor($c, $owner->client), ['الفواتير'])),
            // Expense receipts: the project's folder, else «المصروفات/<YYYY-MM>» under the BOS root.
            $owner instanceof \App\Models\Expense => $owner->project_id && $owner->project
                ? self::folderFor($c, $owner->project)
                : self::remember($c, 'expenses:'.$owner->date->format('Y-m'), fn () => self::path($driver,
                    self::root($c, $driver), ['المصروفات', $owner->date->format('Y-m')])),
            // Task files go to the project's folder, or to the client's "المهام" folder.
            $owner instanceof Task => $owner->project_id && $owner->project
                ? self::folderFor($c, $owner->project)
                : self::remember($c, "client:{$owner->client_id}:tasks", fn () => self::path($driver,
                    self::folderFor($c, $owner->client), ['المهام'])),
            default => throw new StorageException('لا يوجد مجلد تخزين لهذا النوع من السجلات.'),
        };
    }

    /** Forget the remembered folders of a record (after the provider said "not found"). */
    public static function forget(StorageConnection $c, Model $owner): void
    {
        $clientId = $owner instanceof Client ? $owner->id : $owner->getAttribute('client_id');
        $keys = ['root', "client:$clientId", "client:$clientId:contracts", "client:$clientId:quotes", "client:$clientId:tasks", "client:$clientId:invoices"];
        if ($owner instanceof \App\Models\Expense) {
            $keys[] = 'expenses:'.$owner->date->format('Y-m');
        }
        if ($owner instanceof Project) {
            $keys[] = "project:{$owner->id}";
        }
        if ($owner instanceof Task && $owner->project_id) {
            $keys[] = "project:{$owner->project_id}";
        }
        StorageFolder::where('storage_connection_id', $c->id)->whereIn('key', $keys)->delete();
    }

    private static function root(StorageConnection $c, StorageProvider $driver): string
    {
        $id = self::remember($c, 'root', fn () => $driver->rootFolder());
        if ($c->root_folder_id !== $id) {
            $c->forceFill(['root_folder_id' => $id, 'root_folder_name' => $c->root_folder_name ?: 'Insight BOS'])->save();
        }

        return $id;
    }

    /** @param list<string> $segments */
    private static function path(StorageProvider $driver, string $parentId, array $segments): string
    {
        foreach ($segments as $name) {
            $parentId = $driver->ensureChild($parentId, $name);
        }

        return $parentId;
    }

    private static function remember(StorageConnection $c, string $key, callable $make): string
    {
        $hit = StorageFolder::where('storage_connection_id', $c->id)->where('key', $key)->value('external_id');
        if ($hit !== null) {
            return $hit;
        }
        $id = $make();
        StorageFolder::updateOrCreate(['storage_connection_id' => $c->id, 'key' => $key], ['external_id' => $id]);

        return $id;
    }
}
