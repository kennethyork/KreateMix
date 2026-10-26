<?php
declare(strict_types=1);

namespace App\Providers;

class ProviderException extends \RuntimeException
{
    /** True when the account's authorization is no longer valid and it must be reconnected. */
    public bool $unauthorized = false;

    public static function unauthorized(string $message): self
    {
        $e = new self($message);
        $e->unauthorized = true;
        return $e;
    }
}
