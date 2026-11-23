<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\mapper;

use DateTimeImmutable;
use DateTimeZone;
use modules\telegram\application\dto\VersionedTelegramBotSession;
use modules\telegram\application\enum\TelegramSessionPersistenceErrorCode;
use modules\telegram\application\exception\TelegramSessionPersistenceException;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;
use modules\telegram\domain\valueObject\TelegramSessionDraft;

/**
 * @phpstan-type SessionUpdate array{
 *     flow_code: string, content_subject_id: string|null, draft_comment: string|null,
 *     expires_at: string|null, interaction_revision: int
 * }
 * @phpstan-type SessionInsert array{
 *     id: string, telegram_identity_profile_id: string, bot_key: string, chat_id: int,
 *     flow_code: string, content_mode: string, content_subject_id: string|null,
 *     agency_lead_id: string|null, draft_comment: string|null, expires_at: string|null,
 *     interaction_revision: int, lock_version: int, created_at: string, updated_at: string
 * }
 */
final class TelegramBotSessionMapper
{
    private const COLUMNS = [
        'id', 'telegram_identity_profile_id', 'bot_key', 'chat_id',
        'flow_code', 'content_mode', 'content_subject_id', 'agency_lead_id',
        'draft_comment', 'expires_at', 'interaction_revision', 'lock_version',
        'created_at', 'updated_at',
    ];

    /**
     * @param array<string, mixed> $row
     * @throws TelegramSessionPersistenceException
     */
    public function fromRow(array $row): VersionedTelegramBotSession
    {
        foreach (self::COLUMNS as $column) {
            if (!array_key_exists($column, $row)) {
                throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
            }
        }
        $flow = TelegramSessionFlow::tryFrom($this->string($row['flow_code']));
        $mode = TelegramContentMode::tryFrom($this->string($row['content_mode']));
        if ($flow === null || $mode === null) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
        }
        $comment = $this->nullableString($row['draft_comment']);
        $expiry = $this->nullableString($row['expires_at']);
        if (($comment === null) !== ($expiry === null)) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
        }
        $this->readTime($row['created_at']);
        $this->readTime($row['updated_at']);

        try {
            $draft = $comment === null || $expiry === null
                ? null
                : TelegramSessionDraft::restore($comment, $this->readTime($expiry));
            $session = TelegramBotSession::restore(
                new TelegramBotSessionId($this->string($row['id'])),
                $this->string($row['telegram_identity_profile_id']),
                $this->string($row['bot_key']),
                $this->integer($row['chat_id'], 1),
                $mode,
                $flow,
                $this->nullableString($row['content_subject_id']),
                $this->nullableString($row['agency_lead_id']),
                $draft,
                new TelegramInteractionRevision($this->integer($row['interaction_revision'], 0)),
            );
        } catch (TelegramSessionStateViolationException $failure) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD, $failure);
        }

        return new VersionedTelegramBotSession($session, $this->integer($row['lock_version'], 0));
    }

    /**
     * @return SessionInsert
     * @throws TelegramSessionPersistenceException
     */
    public function toInsertParameters(TelegramBotSession $session, DateTimeImmutable $createdAt): array
    {
        $created = $this->writeTime($createdAt);

        return [
            'id' => $session->getId()->value(),
            'telegram_identity_profile_id' => $session->getTelegramIdentityProfileId(),
            'bot_key' => $session->getBotKey(),
            'chat_id' => $session->getChatId(),
            ...$this->toUpdateParameters($session),
            'content_mode' => $session->getContentMode()->value,
            'agency_lead_id' => $session->getAgencyLeadId(),
            'lock_version' => 0,
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * @return SessionUpdate
     * @throws TelegramSessionPersistenceException
     */
    public function toUpdateParameters(TelegramBotSession $session): array
    {
        $draft = $session->getDraft();

        return [
            'flow_code' => $session->getFlow()->value,
            'content_subject_id' => $session->getContentSubjectId(),
            'draft_comment' => $draft?->comment(),
            'expires_at' => $draft === null ? null : $this->writeTime($draft->expiresAt()),
            'interaction_revision' => $session->getInteractionRevision()->value(),
        ];
    }

    /** @throws TelegramSessionPersistenceException */
    public function toTimestampParameter(DateTimeImmutable $time): string
    {
        return $this->writeTime($time);
    }

    private function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->string($value);
    }

    private function integer(mixed $value, int $minimum): int
    {
        if (is_int($value)) {
            if ($value < $minimum) {
                throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
            }

            return $value;
        }
        $maximum = (string) PHP_INT_MAX;
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1
            || strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
        }
        $converted = (int) $value;
        if ($converted < $minimum) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
        }

        return $converted;
    }

    private function readTime(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match(
            '/\A(?<date>[0-9]{4}-[0-9]{2}-[0-9]{2})[ T](?<time>[0-9]{2}:[0-9]{2}:[0-9]{2})'
            . '(?:\.(?<fraction>[0-9]{1,6}))?(?<zone>Z|[+-][0-9]{2}(?::[0-9]{2})?)\z/',
            $value,
            $parts,
        ) !== 1) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
        }
        $zone = $parts['zone'] === 'Z' ? '+00:00' : $parts['zone'];
        if (strlen($zone) === 3) {
            $zone .= ':00';
        }
        $zoneHours = (int) substr($zone, 1, 2);
        $zoneMinutes = (int) substr($zone, 4, 2);
        if (!checkdate((int) substr($parts['date'], 5, 2), (int) substr($parts['date'], 8, 2), (int) substr($parts['date'], 0, 4))
            || (int) substr($parts['time'], 0, 2) > 23
            || (int) substr($parts['time'], 3, 2) > 59
            || (int) substr($parts['time'], 6, 2) > 59
            || $zoneHours > 14 || $zoneMinutes > 59 || ($zoneHours === 14 && $zoneMinutes !== 0)
        ) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
        }
        $canonical = $parts['date'] . 'T' . $parts['time'] . '.'
            . str_pad($parts['fraction'], 6, '0') . $zone;
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $canonical);
        $errors = DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
        }
        $utc = $time->setTimezone(new DateTimeZone('UTC'));
        if ((int) $utc->format('Y') < 1 || (int) $utc->format('Y') > 9999) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_RECORD);
        }

        return $utc;
    }

    private function writeTime(DateTimeImmutable $time): string
    {
        if ($time->getTimezone()->getName() !== 'UTC'
            || (int) $time->format('Y') < 1 || (int) $time->format('Y') > 9999
        ) {
            throw new TelegramSessionPersistenceException(TelegramSessionPersistenceErrorCode::INVALID_ARGUMENT);
        }

        return $time->format('Y-m-d\TH:i:s.uP');
    }
}
