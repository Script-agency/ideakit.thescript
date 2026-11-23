<?php

declare(strict_types=1);

namespace modules\telegram\domain\valueObject;

use DateInterval;
use DateTimeImmutable;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;

final class TelegramSessionDraft
{
    private function __construct(
        private readonly string $comment,
        private readonly DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * @throws TelegramSessionStateViolationException
     */
    public static function tryCreate(string $text, DateTimeImmutable $now): ?self
    {
        self::assertUtc($now);
        $comment = self::normalizeAndValidate($text);
        if ($comment === null) {
            return null;
        }

        return new self($comment, $now->add(new DateInterval('PT24H')));
    }

    /**
     * @throws TelegramSessionStateViolationException
     */
    public static function restore(string $comment, DateTimeImmutable $expiresAt): self
    {
        self::assertUtc($expiresAt);
        if (self::normalizeAndValidate($comment) !== $comment) {
            throw new TelegramSessionStateViolationException('invalid_session_draft');
        }

        return new self($comment, $expiresAt);
    }

    public function comment(): string
    {
        return $this->comment;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * @throws TelegramSessionStateViolationException
     */
    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        self::assertUtc($now);

        return $now >= $this->expiresAt;
    }

    public function equals(self $other): bool
    {
        return $this->comment === $other->comment && $this->expiresAt == $other->expiresAt;
    }

    private static function assertUtc(DateTimeImmutable $time): void
    {
        if ($time->getTimezone()->getName() !== 'UTC') {
            throw new TelegramSessionStateViolationException('session_time_must_be_utc');
        }
    }

    private static function normalizeAndValidate(string $text): ?string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $normalized = preg_replace('/(?!\n)\p{Cc}/u', '', $normalized);
        if ($normalized === null) {
            throw new TelegramSessionStateViolationException('draft_normalization_failed');
        }

        $hasContent = preg_match('/[^\p{Z}\n]/u', $normalized);
        if ($hasContent === false) {
            throw new TelegramSessionStateViolationException('draft_validation_failed');
        }
        if ($hasContent === 0 || mb_strlen($normalized, 'UTF-8') > 2000) {
            return null;
        }

        return $normalized;
    }
}
