<?php

declare(strict_types=1);

namespace modules\telegram\application\enum;

enum TelegramSessionPersistenceErrorCode: string
{
    case INVALID_ARGUMENT = 'telegram_session_invalid_argument';
    case INVALID_RECORD = 'telegram_session_invalid_record';
    case TRANSACTION_REQUIRED = 'telegram_session_transaction_required';
    case OWNER_MISMATCH = 'telegram_session_owner_mismatch';
    case MODE_MISMATCH = 'telegram_session_mode_mismatch';
    case NOT_FOUND = 'telegram_session_not_found';
    case VERSION_CONFLICT = 'telegram_session_version_conflict';
    case VERSION_OVERFLOW = 'telegram_session_version_overflow';
    case STORAGE_UNAVAILABLE = 'telegram_session_storage_unavailable';
    case STORAGE_FAILURE = 'telegram_session_storage_failure';
}
