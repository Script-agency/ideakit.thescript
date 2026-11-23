<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\db;

use yii\db\Command;

final class TelegramSessionDbCommand extends Command
{
    /** @return array{false, null} */
    protected function logQuery($category): array
    {
        // Session bindings must not enter SQL logs or profiling.
        return [false, null];
    }
}
