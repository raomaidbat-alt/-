<?php
declare(strict_types=1);

namespace App\Bitrix;

final class BitrixApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = '',
        public readonly int $httpStatus = 0,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSec = null,
    ) {
        parent::__construct($message);
    }
}
