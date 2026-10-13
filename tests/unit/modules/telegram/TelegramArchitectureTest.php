<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram;

use Codeception\Test\Unit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class TelegramArchitectureTest extends Unit
{
    public function testDomainDependsOnlyOnLocalTypesAndExplicitInputs(): void
    {
        self::assertSame([], $this->violations(dirname(__DIR__, 4) . '/modules/telegram/domain', []));
    }

    public function testApplicationAndCoreDependencyBoundaries(): void
    {
        $root = dirname(__DIR__, 4);
        $violations = [
            ...$this->violations($root . '/modules/telegram/application', [
                'yii\\', 'modules\\telegram\\infrastructure\\', 'modules\\telegram\\presentation\\',
            ]),
            ...$this->violations($root . '/core', ['modules\\telegram\\']),
            ...$this->violations($root . '/modules/telegram/infrastructure/mapper', [
                'modules\\users\\', 'core\\', 'modules\\platform\\',
            ]),
            ...$this->violations($root . '/modules/telegram', [
                'modules\\platform\\infrastructure\\', 'modules\\platform\\presentation\\',
                'modules\\users\\infrastructure\\', 'core\\infrastructure\\db\\',
                'core\\infrastructure\\repository\\',
            ]),
        ];
        self::assertSame([], $violations);
    }

    /**
     * @param list<string> $prefixes
     * @return list<string>
     */
    private function violations(string $directory, array $prefixes): array
    {
        $violations = [];
        $isDomain = str_ends_with($directory, '/domain');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                throw new RuntimeException('Unable to read PHP source file.');
            }
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if ($isDomain && $this->isForbiddenDomainToken($token)) {
                    $violations[] = $file->getFilename() . ': ' . $token[1];
                }
                if ($token[0] === T_STRING && in_array($token[1], ['Yii', 'ActiveRecord'], true)
                    && str_ends_with($directory, '/application')) {
                    $violations[] = $file->getFilename() . ': ' . $token[1];
                }
                if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                    continue;
                }
                $name = strtolower(ltrim($token[1], '\\'));
                if ($isDomain && !str_starts_with($name, 'modules\\telegram\\domain\\')
                    && !in_array($name, ['dateinterval', 'datetimeimmutable', 'domainexception'], true)) {
                    $violations[] = $file->getFilename() . ': ' . $token[1];
                }
                foreach ($prefixes as $prefix) {
                    if (str_starts_with(strtolower(ltrim($token[1], '\\')), strtolower($prefix))) {
                        $violations[] = $file->getFilename() . ': ' . $token[1];
                    }
                }
            }
        }
        return $violations;
    }

    /** @param array{int, string, int} $token */
    private function isForbiddenDomainToken(array $token): bool
    {
        if (in_array($token[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL, T_GLOBAL], true)) {
            return true;
        }
        if ($token[0] === T_VARIABLE) {
            return in_array($token[1], ['$_ENV', '$_SERVER', '$GLOBALS', '$_GET', '$_POST', '$_REQUEST', '$_SESSION', '$_COOKIE', '$_FILES'], true);
        }
        if (!in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        return in_array(strtolower(ltrim($token[1], '\\')), [
            'yii', 'activerecord', 'pdo', 'getenv', 'putenv', 'time', 'microtime', 'hrtime',
            'date', 'gmdate', 'getdate', 'strtotime', 'random_bytes', 'random_int', 'rand', 'mt_rand', 'uniqid',
            'file_get_contents', 'file_put_contents', 'fopen', 'fread', 'fwrite', 'file', 'readfile',
            'unlink', 'rename', 'mkdir', 'rmdir', 'scandir', 'glob', 'file_exists', 'is_file',
            'splfileobject', 'directoryiterator', 'recursivedirectoryiterator',
        ], true);
    }
}
