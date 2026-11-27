<?php

namespace App\Services\Storage;

use RuntimeException;

/** A storage call failed. `status` is the provider's HTTP status when there was one (404 = not found). */
class StorageException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
