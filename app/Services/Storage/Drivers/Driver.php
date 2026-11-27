<?php

namespace App\Services\Storage\Drivers;

use App\Models\StorageConnection;
use App\Services\Storage\StorageException;
use App\Services\Storage\StorageProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class Driver implements StorageProvider
{
    public const TIMEOUT = 60;

    public function __construct(protected StorageConnection $connection) {}

    protected function rootName(): string
    {
        return self::cleanName($this->connection->root_folder_name ?: 'Insight BOS');
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT)->connectTimeout(15)->acceptJson();
    }

    /** Run a request, turning network errors into StorageException. */
    protected function call(callable $fn): Response
    {
        try {
            return $fn();
        } catch (ConnectionException $e) {
            throw new StorageException('تعذّر الوصول إلى خدمة التخزين: '.$e->getMessage());
        }
    }

    /** Throw a readable StorageException unless the response is successful (or its status is allowed). */
    protected function check(Response $r, string $what, array $allow = []): Response
    {
        if ($r->successful() || in_array($r->status(), $allow, true)) {
            return $r;
        }
        $detail = $r->json('error.message') ?? $r->json('error_description') ?? $r->json('error_summary')
            ?? (is_string($r->json('error')) ? $r->json('error') : null) ?? mb_substr(trim(strip_tags($r->body())), 0, 200);

        throw new StorageException(trim("$what ({$r->status()}) ".($detail ?: '')), $r->status());
    }

    /** A folder or file name safe for every provider. */
    public static function cleanName(string $name): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name), " .\t");

        return mb_substr($name !== '' ? $name : 'بدون اسم', 0, 120);
    }
}
