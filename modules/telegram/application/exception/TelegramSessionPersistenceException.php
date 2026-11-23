<?php

declare(strict_types=1);

namespace modules\telegram\application\exception;

use modules\telegram\application\enum\TelegramSessionPersistenceErrorCode;
use RuntimeException;
use Throwable;

final class TelegramSessionPersistenceException extends RuntimeException
{
    public function __construct(
        public readonly TelegramSessionPersistenceErrorCode $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode->value, 0, $previous);
    }
}
