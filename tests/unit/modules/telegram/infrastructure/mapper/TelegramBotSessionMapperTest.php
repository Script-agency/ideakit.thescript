<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\mapper;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use modules\telegram\application\enum\TelegramSessionPersistenceErrorCode;
use modules\telegram\application\exception\TelegramSessionPersistenceException;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;
use modules\telegram\domain\valueObject\TelegramSessionDraft;
use modules\telegram\infrastructure\mapper\TelegramBotSessionMapper;

final class TelegramBotSessionMapperTest extends Unit
{
    /**
     * @dataProvider states
     * @param array<string, mixed> $changes
     */
    public function testRestoresStateWithoutTransitionsOrDraftRepair(array $changes): void
    {
        $row = array_replace(self::validRow(), $changes);
        $loaded = (new TelegramBotSessionMapper())->fromRow($row);
        $session = $loaded->session();

        self::assertSame($row['id'], $session->getId()->value());
        self::assertSame($row['telegram_identity_profile_id'], $session->getTelegramIdentityProfileId());
        self::assertSame($row['bot_key'], $session->getBotKey());
        self::assertSame(91, $session->getChatId());
        self::assertSame($row['content_mode'], $session->getContentMode()->value);
        self::assertSame($row['flow_code'], $session->getFlow()->value);
        self::assertSame($row['content_subject_id'], $session->getContentSubjectId());
        self::assertSame($row['agency_lead_id'], $session->getAgencyLeadId());
        self::assertSame(4, $session->getInteractionRevision()->value());
        self::assertSame(7, $loaded->lockVersion());
        if ($row['draft_comment'] === null) {
            self::assertNull($session->getDraft());
        } else {
            $draft = $session->getDraft();
            self::assertNotNull($draft);
            self::assertSame($row['draft_comment'], $draft->comment());
            self::assertSame('UTC', $draft->expiresAt()->getTimezone()->getName());
            self::assertSame('2000-01-01T10:00:00.123456+00:00', $draft->expiresAt()->format('Y-m-d\TH:i:s.uP'));
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function states(): iterable
    {
        yield 'browsing without context' => [[]];
        yield 'awaiting comment' => [[
            'flow_code' => 'AWAITING_LEAD_COMMENT',
            'content_subject_id' => '550e8400-e29b-41d4-a716-446655440000',
        ]];
        yield 'expired confirmation' => [self::confirmation()];
        yield 'reserved mode and flow' => [[
            'content_mode' => 'CATALOG', 'flow_code' => 'LEAD_REPLY',
            'agency_lead_id' => '550e8400-e29b-41d4-a716-446655440001',
        ]];
    }

    public function testRequiresEveryColumnEvenWhenNullable(): void
    {
        foreach (array_keys(self::validRow()) as $column) {
            $row = self::validRow();
            unset($row[$column]);
            $this->assertInvalidRecord($row);
        }
    }

    /**
     * @dataProvider invalidRecords
     * @param array<string, mixed> $changes
     */
    public function testRejectsInvalidRecordsWithoutDefaults(array $changes): void
    {
        $this->assertInvalidRecord(array_replace(self::validRow(), $changes));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRecords(): iterable
    {
        yield 'required null' => [['id' => null]];
        yield 'wrong uuid type' => [['id' => 91]];
        yield 'invalid uuid' => [['id' => 'synthetic-invalid-id']];
        yield 'profile version' => [['telegram_identity_profile_id' => '550e8400-e29b-41d4-a716-446655440000']];
        yield 'invalid reference' => [['content_subject_id' => '']];
        yield 'wrong nullable type' => [['agency_lead_id' => false]];
        yield 'padded bot key' => [['bot_key' => ' session-mapping-test']];
        yield 'unknown mode' => [['content_mode' => 'UNKNOWN']];
        yield 'unknown flow' => [['flow_code' => 'UNKNOWN']];
        yield 'wrong enum type' => [['flow_code' => 0]];
        yield 'zero chat' => [['chat_id' => '0']];
        yield 'negative lock' => [['lock_version' => -1]];
        yield 'missing draft' => [['flow_code' => 'AWAITING_LEAD_CONFIRMATION']];
        yield 'comment only' => [['draft_comment' => 'Synthetic comment']];
        yield 'expiry only' => [['expires_at' => '2000-01-01T10:00:00Z']];
        yield 'draft in browsing' => [array_replace(self::confirmation(), ['flow_code' => 'BROWSING'])];
        yield 'noncanonical line endings' => [array_replace(self::confirmation(), ['draft_comment' => "Synthetic\r\ncomment"])];
        yield 'control character' => [array_replace(self::confirmation(), ['draft_comment' => "Synthetic\tcomment"])];
        yield 'invalid updated time' => [['updated_at' => 'invalid']];
        yield 'invalid expiry type' => [array_replace(self::confirmation(), ['expires_at' => 1])];
    }

    /** @dataProvider numericRepresentations */
    public function testPreservesExactBigintBoundaries(bool $strings): void
    {
        $row = self::validRow();
        foreach (['chat_id', 'interaction_revision', 'lock_version'] as $column) {
            $row[$column] = $strings ? (string) PHP_INT_MAX : PHP_INT_MAX;
        }
        $loaded = (new TelegramBotSessionMapper())->fromRow($row);
        self::assertSame(PHP_INT_MAX, $loaded->session()->getChatId());
        self::assertSame(PHP_INT_MAX, $loaded->session()->getInteractionRevision()->value());
        self::assertSame(PHP_INT_MAX, $loaded->lockVersion());

        $row['interaction_revision'] = $strings ? '0' : 0;
        $row['lock_version'] = $strings ? '0' : 0;
        $loaded = (new TelegramBotSessionMapper())->fromRow($row);
        self::assertSame(0, $loaded->session()->getInteractionRevision()->value());
        self::assertSame(0, $loaded->lockVersion());
    }

    /** @return iterable<array{bool}> */
    public static function numericRepresentations(): iterable
    {
        yield [false];
        yield [true];
    }

    /** @dataProvider invalidNumbers */
    public function testRejectsInvalidBigintBeforeConversion(mixed $value): void
    {
        $this->assertInvalidRecord(array_replace(self::validRow(), ['interaction_revision' => $value]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidNumbers(): iterable
    {
        yield 'negative int' => [-1];
        yield 'negative string' => ['-1'];
        yield 'float' => [1.0];
        yield 'boolean' => [true];
        yield 'exponent' => ['1e3'];
        yield 'fraction' => ['1.5'];
        yield 'sign' => ['+1'];
        yield 'padded' => [' 1'];
        yield 'leading zero' => ['01'];
        yield 'empty' => [''];
        yield 'same length overflow' => ['9223372036854775808'];
        yield 'long overflow' => [(string) PHP_INT_MAX . '0'];
    }

    /** @dataProvider timestamps */
    public function testNormalizesOnlyTimeRepresentation(string $input, string $expected): void
    {
        $row = array_replace(self::validRow(), self::confirmation(), ['expires_at' => $input]);
        $draft = (new TelegramBotSessionMapper())->fromRow($row)->session()->getDraft();
        self::assertNotNull($draft);
        self::assertSame('UTC', $draft->expiresAt()->getTimezone()->getName());
        self::assertSame($expected, $draft->expiresAt()->format('Y-m-d\TH:i:s.uP'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function timestamps(): iterable
    {
        yield 'UTC without fraction' => ['2000-01-01T10:00:00Z', '2000-01-01T10:00:00.000000+00:00'];
        yield 'short UTC offset' => ['2000-01-01 10:00:00.1+00', '2000-01-01T10:00:00.100000+00:00'];
        yield 'short positive offset' => ['2000-01-01 13:00:00.123456+03', '2000-01-01T10:00:00.123456+00:00'];
        yield 'negative offset' => ['2000-01-01T06:30:00.12-03:30', '2000-01-01T10:00:00.120000+00:00'];
        yield 'maximum offset' => ['2000-01-02T00:00:00+14:00', '2000-01-01T10:00:00.000000+00:00'];
        yield 'leap day' => ['2000-02-29T10:00:00Z', '2000-02-29T10:00:00.000000+00:00'];
        yield 'minimum year' => ['0001-01-01T00:00:00Z', '0001-01-01T00:00:00.000000+00:00'];
        yield 'maximum year' => ['9999-12-31T23:59:59.999999Z', '9999-12-31T23:59:59.999999+00:00'];
    }

    /** @dataProvider invalidTimestamps */
    public function testRejectsInvalidTimeWithoutRollover(mixed $value): void
    {
        $this->assertInvalidRecord(array_replace(self::validRow(), ['created_at' => $value]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTimestamps(): iterable
    {
        yield 'wrong type' => [1];
        yield 'invalid calendar date' => ['2025-02-29T10:00:00Z'];
        yield 'zero month' => ['2000-00-01T10:00:00Z'];
        yield 'hour rollover' => ['2000-01-01T24:00:00Z'];
        yield 'minute rollover' => ['2000-01-01T10:60:00Z'];
        yield 'leap second' => ['2000-01-01T10:00:60Z'];
        yield 'no zone' => ['2000-01-01T10:00:00'];
        yield 'infinity' => ['infinity'];
        yield 'negative infinity' => ['-infinity'];
        yield 'relative' => ['tomorrow'];
        yield 'empty' => [''];
        yield 'tail' => ["2000-01-01T10:00:00Z\n"];
        yield 'excess precision' => ['2000-01-01T10:00:00.1234567Z'];
        yield 'zone name' => ['2000-01-01T10:00:00UTC'];
        yield 'compact offset' => ['2000-01-01T10:00:00+0300'];
        yield 'offset hours' => ['2000-01-01T10:00:00+15'];
        yield 'offset minutes' => ['2000-01-01T10:00:00+03:60'];
        yield 'offset above maximum' => ['2000-01-01T10:00:00+14:01'];
        yield 'zero year' => ['0000-01-01T00:00:00Z'];
        yield 'UTC underflow' => ['0001-01-01T00:00:00+01'];
        yield 'UTC overflow' => ['9999-12-31T23:59:59-01'];
    }

    public function testPreparesExactInsertAndExplicitDraftClearing(): void
    {
        $mapper = new TelegramBotSessionMapper();
        $session = $mapper->fromRow(array_replace(self::validRow(), self::confirmation()))->session();
        $createdAt = new DateTimeImmutable('2026-10-13T10:00:00.654321', new DateTimeZone('UTC'));
        $expected = array_replace(self::validRow(), self::confirmation(), [
            'chat_id' => 91, 'interaction_revision' => 4, 'lock_version' => 0,
            'expires_at' => '2000-01-01T10:00:00.123456+00:00',
            'created_at' => '2026-10-13T10:00:00.654321+00:00',
            'updated_at' => '2026-10-13T10:00:00.654321+00:00',
        ]);
        $insert = $mapper->toInsertParameters($session, $createdAt);
        ksort($expected);
        ksort($insert);
        self::assertSame($expected, $insert);
        self::assertSame([
            'flow_code' => 'AWAITING_LEAD_CONFIRMATION', 'content_subject_id' => null,
            'draft_comment' => "  Synthetic запуск\nSecond line  ",
            'expires_at' => '2000-01-01T10:00:00.123456+00:00', 'interaction_revision' => 4,
        ], $mapper->toUpdateParameters($session));

        $session->expireDraft($createdAt, null);
        self::assertSame([
            'flow_code' => 'BROWSING', 'content_subject_id' => null,
            'draft_comment' => null, 'expires_at' => null, 'interaction_revision' => 5,
        ], $mapper->toUpdateParameters($session));
    }

    /** @dataProvider invalidWriteTimes */
    public function testRejectsInvalidWriteTime(string $time, string $zone): void
    {
        $mapper = new TelegramBotSessionMapper();
        $session = $mapper->fromRow(self::validRow())->session();
        try {
            $mapper->toInsertParameters($session, new DateTimeImmutable($time, new DateTimeZone($zone)));
            self::fail('Expected invalid write time.');
        } catch (TelegramSessionPersistenceException $failure) {
            self::assertSame(TelegramSessionPersistenceErrorCode::INVALID_ARGUMENT, $failure->errorCode);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidWriteTimes(): iterable
    {
        yield 'non UTC' => ['2026-10-13T10:00:00', 'Europe/Moscow'];
        yield 'year zero' => ['0000-01-01T00:00:00', 'UTC'];
        yield 'year above maximum' => ['+10000-01-01T00:00:00', 'UTC'];
    }

    public function testRejectsOutOfRangeDraftExpiryOnWrite(): void
    {
        $session = TelegramBotSession::restore(
            new TelegramBotSessionId('01890f4d-3c2a-7f48-8c0b-123456789ab3'),
            '01890f4d-3c2a-7f48-8c0b-123456789ab2',
            'session-mapping-test',
            91,
            TelegramContentMode::DEMO,
            TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION,
            null,
            null,
            TelegramSessionDraft::restore('Synthetic comment', new DateTimeImmutable('+10000-01-01', new DateTimeZone('UTC'))),
            new TelegramInteractionRevision(4),
        );
        try {
            (new TelegramBotSessionMapper())->toUpdateParameters($session);
            self::fail('Expected invalid draft expiry.');
        } catch (TelegramSessionPersistenceException $failure) {
            self::assertSame(TelegramSessionPersistenceErrorCode::INVALID_ARGUMENT, $failure->errorCode);
        }
    }

    public function testPreservesDomainCauseWithoutExposingRecord(): void
    {
        $row = array_replace(self::validRow(), ['bot_key' => ' synthetic-private-record ']);
        $failure = $this->assertInvalidRecord($row);
        self::assertInstanceOf(TelegramSessionStateViolationException::class, $failure->getPrevious());
        self::assertSame(['errorCode' => TelegramSessionPersistenceErrorCode::INVALID_RECORD], get_object_vars($failure));
    }

    /** @param array<string, mixed> $row */
    private function assertInvalidRecord(array $row): TelegramSessionPersistenceException
    {
        try {
            (new TelegramBotSessionMapper())->fromRow($row);
            self::fail('Expected invalid session record.');
        } catch (TelegramSessionPersistenceException $failure) {
            self::assertSame(TelegramSessionPersistenceErrorCode::INVALID_RECORD, $failure->errorCode);
            self::assertSame('telegram_session_invalid_record', $failure->getMessage());

            return $failure;
        }
    }

    /** @return array<string, mixed> */
    private static function confirmation(): array
    {
        return [
            'flow_code' => 'AWAITING_LEAD_CONFIRMATION',
            'draft_comment' => "  Synthetic запуск\nSecond line  ",
            'expires_at' => '2000-01-01 13:00:00.123456+03',
        ];
    }

    /** @return array<string, mixed> */
    private static function validRow(): array
    {
        return [
            'id' => '01890f4d-3c2a-7f48-8c0b-123456789ab3',
            'telegram_identity_profile_id' => '01890f4d-3c2a-7f48-8c0b-123456789ab2',
            'bot_key' => 'session-mapping-test', 'chat_id' => '91',
            'flow_code' => 'BROWSING', 'content_mode' => 'DEMO',
            'content_subject_id' => null, 'agency_lead_id' => null,
            'draft_comment' => null, 'expires_at' => null,
            'interaction_revision' => '4', 'lock_version' => '7',
            'created_at' => '2026-10-13 10:00:00.123456+00',
            'updated_at' => '2026-10-13 10:00:00.123456+00',
        ];
    }
}
