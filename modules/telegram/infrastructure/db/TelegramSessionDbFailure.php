<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\db;

use modules\telegram\application\enum\TelegramSessionPersistenceErrorCode;
use modules\telegram\application\exception\TelegramSessionPersistenceException;
use PDOException;
use Throwable;
use yii\db\Exception;

final class TelegramSessionDbFailure
{
    private const TRANSIENT_STATES = [
        '40001', '40P01', '55P03', '57014', '08001', '08003', '08006',
        '57P01', '57P02', '57P03', '53300',
    ];

    public function translate(Exception|PDOException $failure): TelegramSessionPersistenceException
    {
        $code = in_array($this->sqlState($failure), self::TRANSIENT_STATES, true)
            ? TelegramSessionPersistenceErrorCode::STORAGE_UNAVAILABLE
            : TelegramSessionPersistenceErrorCode::STORAGE_FAILURE;

        return new TelegramSessionPersistenceException($code, $failure);
    }

    private function sqlState(Throwable $failure): ?string
    {
        for ($depth = 0; $depth < 8; ++$depth) {
            if ($failure instanceof Exception || $failure instanceof PDOException) {
                $state = $failure->errorInfo[0] ?? null;
                if (is_string($state) && preg_match('/\A[0-9A-Z]{5}\z/', $state) === 1) {
                    return $state;
                }
            }
            $previous = $failure->getPrevious();
            if ($previous === null) {
                return null;
            }
            $failure = $previous;
        }

        return null;
    }
}
