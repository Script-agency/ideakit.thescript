<?php

declare(strict_types=1);

namespace tests\fixtures\telegram\session;

use yii\caching\ArrayCache;

final class RecordingSessionQueryCache extends ArrayCache
{
    public int $writes = 0;

    protected function setValue($key, $value, $duration): bool
    {
        ++$this->writes;

        return parent::setValue($key, $value, $duration);
    }
}
