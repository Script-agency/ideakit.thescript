# Модуль Telegram

## Реализованная часть

Подготовлены PostgreSQL-таблицы `telegram_bot_sessions` для состояния диалога и `telegram_updates`
для надёжного приёма входящих обновлений. Webhook подключён к основной web-композиции с настоящим
атомарным inbox/outbox acceptor. Доменная модель сессии защищает переходы демонстрационного диалога,
черновик и интерактивную ревизию. Для сессии подготовлены внутренний контракт хранения и строгий
PostgreSQL mapper и репозиторий с ленивым web/console DI. Подключение сессии к обработке обновлений,
production-обработка и отправка сообщений ещё не реализованы.

## Контракт содержимого карточек

[IIdeaContentProvider](../../../modules/telegram/application/port/IIdeaContentProvider.php)
определяет чтение первой карточки, карточки и подробностей по идентификатору, а также следующей
карточки относительно текущей. Отсутствие результата обозначается `null`; неизвестный
идентификатор не заменяется первой карточкой.

Неизменяемые [IdeaCardView](../../../modules/telegram/application/dto/IdeaCardView.php) и
[IdeaDetailsView](../../../modules/telegram/application/dto/IdeaDetailsView.php) содержат обычный
текст без Telegram-разметки, клавиатур и типов хранения. Подробности включают карточку и шесть
именованных разделов. Контракт не меняет состояние диалога и не определяет персональную выдачу
настоящего каталога.

[ConfiguredDemoIdeaProvider](../../../modules/telegram/infrastructure/content/ConfiguredDemoIdeaProvider.php)
предоставляет три записи: «Тестовая карточка 1», «Тестовая карточка 2» и «Тестовая карточка 3».
Их UUID заданы в коде и не меняются между запусками. Следующая карточка определяется по переданному
UUID: `1 → 2 → 3 → 1`. Источник не хранит положение пользователя, не меняет сессию и не обращается
к БД, брокеру или Telegram API.

Четыре [unit-теста](../../../tests/unit/modules/telegram/infrastructure/content/ConfiguredDemoIdeaProviderTest.php)
проверяют цикл, согласованность карточек и подробностей, отсутствие подстановки при неизвестном
UUID и независимость повторных вызовов. Содержимое синтетическое и не является реальным каталогом.

Источник ещё не подключён через DI. Клавиатуры, отправка сообщений и персональная выдача
настоящего каталога не реализованы этой частью.

## Формирование сообщений

[TelegramMessageRenderer](../../../modules/telegram/presentation/message/TelegramMessageRenderer.php)
принадлежит Presentation: оформляет переданные карточку, шесть разделов подробностей и тексты
демонстрационного диалога. Он не выбирает карточку и не получает источник, сессию или окружение.
[RenderedTelegramText](../../../modules/telegram/presentation/message/RenderedTelegramText.php)
содержит только неизменяемый HTML-текст и режим `HTML`, без адресации и признака доставки.

Собственная разметка ограничена `<b>` и LF. Все подстановки экранируются как обычный текст;
буквальные HTML-сущности не декодируются. Общий контроль сначала проверяет UTF-8, затем размер
всего видимого текста: от 1 до 4096 UTF-16-единиц. Длинное сообщение не сокращается и не делится.
Уже принятый комментарий показывается полностью, с пробелами, переносами и эмодзи; рендерер
не повторяет доменную нормализацию, проверку длины комментария или срока его хранения.

[Типизированная ошибка](../../../modules/telegram/presentation/exception/TelegramMessageRenderingException.php)
возвращает только [код](../../../modules/telegram/presentation/enum/TelegramMessageRenderingErrorCode.php)
`invalid_text` или `text_too_long`. Исходный текст не сохраняется в собственных полях исключения;
полный Throwable и его trace нельзя выводить или передавать в журнал. Демонстрационное подтверждение
и завершение явно сообщают, что реальная заявка не отправлена; PDF остаётся текстовой заглушкой.

[Unit-набор рендерера](../../../tests/unit/modules/telegram/presentation/message/TelegramMessageRendererTest.php)
проверяет шаблоны, экранирование, UTF-16-границы, полный комментарий и независимость вызовов.
После пересборки образа в локальном тестовом окружении:

```bash
docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e DB_USERNAME=ideakit -e TEST_DB_USERNAME=ideakit -e DB_PASSWORD=local-development-only -e TEST_DB_PASSWORD=local-development-only php-fpm vendor/bin/codecept run unit tests/unit/modules/telegram/presentation/message/TelegramMessageRendererTest.php --no-colors
```

Клавиатуры, DI, выбор сообщения по состоянию диалога и отправка в Telegram этим рендерером не реализованы.

## Доменная сессия

[TelegramBotSession](../../../modules/telegram/domain/entity/TelegramBotSession.php) принадлежит Telegram
и зависит только от PHP и локальных доменных типов. Идентификаторы и время передаются явно;
UUID профиля не импортирует типы Users и не подтверждает право доступа.
`createDemo` создаёт `DEMO/BROWSING` с ревизией 0. `restore` проверяет состояние, но не выполняет
переходов и не очищает просроченный черновик.

Модель поддерживает начало оформления, принятие комментария, подтверждение, inline-отмену
с проверкой ревизии, отмену текущего оформления, перезапуск и явное истечение черновика.
Навигация разрешена только в `DEMO/BROWSING` и сама состояние не меняет.
Каждый применённый переход увеличивает `interaction_revision` ровно один раз, включая повторный
перезапуск в `BROWSING`. Устаревшее действие отклоняется до проверки срока и не очищает черновик;
переполнение и некорректные аргументы не оставляют частично изменённую сущность.

[Черновик](../../../modules/telegram/domain/valueObject/TelegramSessionDraft.php) объединяет текст
и срок: максимум 2000 Unicode-символов после нормализации CR/LF и удаления остальных управляющих
символов `Cc`; ровно 24 часа от принятия комментария, UTC с сохранением микросекунд.
При актуальном просроченном подтверждении черновик очищается с результатом `DRAFT_EXPIRED`,
который, как и `APPLIED`, требует сохранения. Неизменяемый результат не содержит комментарий.
Возврат к просмотру использует явно переданный subject, включая `null`, без подстановки subject заказа.

Допустимые `CATALOG` и `LEAD_REPLY` восстанавливаются, но демо-операции возвращают
`UNSUPPORTED_MODE` или `UNSUPPORTED_FLOW` без изменения данных. Настоящая заявка не создаётся.
Application orchestration и Telegram delivery сессии пока отсутствуют;
интерактивная ревизия не заменяет блокировки, `lock_version` или дедупликацию inbox.

## Контракт хранения и mapping сессии

[ITelegramBotSessionRepository](../../../modules/telegram/application/port/ITelegramBotSessionRepository.php)
— внутренний write-side порт Telegram с операциями `createIfAbsent`, `loadForUpdate` и `save`.
Он описывает работу в активной транзакции вызывающего сценария на том же соединении, без собственного
commit, rollback или retry. Порт подключён к PostgreSQL-адаптеру в общей web/console-композиции.

[VersionedTelegramBotSession](../../../modules/telegram/application/dto/VersionedTelegramBotSession.php)
содержит доменную сессию и загруженный `lock_version`, который не меняется при доменном переходе.
[TelegramSessionCreationResult](../../../modules/telegram/application/dto/TelegramSessionCreationResult.php)
различает созданную и существующую сессию; признак создания не подтверждает commit или доставку.

[TelegramBotSessionMapper](../../../modules/telegram/infrastructure/mapper/TelegramBotSessionMapper.php)
проверяет наличие всех 14 полей строки и различает отсутствующее поле и допустимый `null`.
UUID, enum, bigint и timestamps преобразуются строго, без потери точности и автоматического исправления дат.
Mapper сохраняет nullable-ссылки, `CATALOG`/`LEAD_REPLY`, точный текст и UTC-микросекунды,
не очищает просроченный черновик, не продлевает TTL и не выполняет запросов или переходов.
Массивы строки и параметров остаются в Infrastructure.

Вставка содержит 14 явных полей. Изменение содержит только `flow_code`, `content_subject_id`,
`draft_comment`, `expires_at` и `interaction_revision`; очистка передаёт оба draft-поля как `null`.
Техническое увеличение `lock_version` и изменение `updated_at` выполняет репозиторий.
Ошибки преобразования возвращаются как
[TelegramSessionPersistenceException](../../../modules/telegram/application/exception/TelegramSessionPersistenceException.php)
с закрытым [кодом](../../../modules/telegram/application/enum/TelegramSessionPersistenceErrorCode.php):
`INVALID_RECORD` при чтении, `INVALID_ARGUMENT` при неверном времени записи.
Исходная доменная причина сохраняется внутренне и не предназначена для внешнего вывода или логирования.
Блокировки, конкурентное создание и optimistic save этим mapping не реализованы.

## PostgreSQL-репозиторий сессии

[DbTelegramBotSessionRepository](../../../modules/telegram/infrastructure/db/DbTelegramBotSessionRepository.php)
работает только с `telegram_bot_sessions`. `createIfAbsent` создаёт начальную сессию либо блокирует
существующую пару bot/chat без сброса диалога и смены владельца. `loadForUpdate` загружает сессию
под row lock; только отсутствие возвращает `null`. `save` проверяет владельца, неизменяемые поля
и ожидаемую storage version до сравнения изменяемого состояния.

Вызывающий сценарий владеет активными Yii/PDO-транзакциями: READ COMMITTED, read-write, ISO и UTC.
Он задаёт через `SET LOCAL` положительный `lock_timeout` не более 2 секунд и `statement_timeout`
больше lock timeout, но не более 5 секунд. Репозиторий проверяет контекст каждого вызова,
не исправляет настройки, не открывает транзакцию и не выполняет commit, rollback или retry.
Успешный результат ещё не подтверждает commit или доставку сообщения.

Изменение увеличивает `lock_version` один раз; no-change сохраняет версию, timestamps и физическую
строку без UPDATE и вызова часов. Устаревшая версия отклоняется и для no-change. Максимальная версия
допускает чтение/no-change, но изменение даёт `VERSION_OVERFLOW`. `interaction_revision` независима
от storage version; expired draft сохраняется при чтении и очищается только явным Domain-переходом.

Ошибки имеют закрытые коды; DB-причина сохраняется только внутренне. Локальная DAO-команда
исключает SQL logging/profiling и query cache, не меняя общие флаги соединения.
[DI](../../../config/container.php) получает текущее application db при каждом разрешении порта;
создание адаптера не открывает соединение и не требует webhook secret или брокера.

## HTTP-граница webhook

[Контроллер](../../../modules/telegram/presentation/controller/WebhookController.php) вызывает только
[IAcceptTelegramUpdate](../../../modules/telegram/application/port/IAcceptTelegramUpdate.php).
Неизменяемая команда содержит серверный bot key, числовой Telegram update ID, тип обновления,
nullable chat ID, причину игнорирования, исходный JSON, версию payload и его SHA-256.
У проигнорированного обновления chat ID остаётся `null`. Числовой update ID не является UUID inbox:
receipt содержит отдельный внутренний UUID и исход `ACCEPTED` либо `DUPLICATE`.
Только после такого receipt возвращается `200 {"ok":true}`. Контроллер не пишет в БД,
не вызывает Users или Platform writer и не повторяет приём автоматически.

[Web fragment](../../../config/telegram_webhook.php) допускает только фактический
`POST /telegram/webhook` без query string. Method override не меняет метод webhook.
Другие методы получают `405` с `Allow: POST`, а HEAD — без тела. Альтернативный Yii action URL,
дополнительные сегменты и неканонические адреса не достигают приёма. Исключение административного JWT
и отключение CSRF ограничены этим входом; другие endpoints сохраняют прежние проверки.

Настройки `TELEGRAM_BOT_KEY` и `TELEGRAM_WEBHOOK_SECRET` загружаются лениво после начальных HTTP gates.
Secret проверяется до чтения тела. Принимается JSON с UTF-8 без сжатия; фактический предел тела —
65 536 байт, независимо от Content-Length. Parser вызывается один раз, hash считается по исходным байтам.
Nginx применяет отдельный предел 64k непосредственно в точном location и не пишет его access log;
общий предел остальных маршрутов остаётся 10m.

Отказы возвращают только `error.code` и закрытый `error.message`: неверный запрос/update — 400,
secret — 403, метод — 405, размер — 413, media/encoding — 415, недоступность приёма/настроек — 503,
неожиданная ошибка — 500. Ошибки до action также защищены. Для чувствительного пути debug/Gii
исключаются до bootstrap; журнал содержит только безопасный reason, HTTP-статус и серверный correlation ID,
без body, secret и исходного исключения. Обычная dev-композиция сохраняется.

Основной `config/web.php` применяет fragment один раз после dev debug/gii-конфигурации.
Endpoint и точное JWT-исключение подключены вместе с настоящим acceptor. Тестовая подмена остаётся
только в tests для изолированной проверки HTTP gates. Обычные web/console bootstrap и разрешение
acceptor не требуют webhook secret и не открывают сетевые соединения.

## Атомарный приём inbox/outbox

[AcceptTelegramUpdateHandler](../../../modules/telegram/application/handler/AcceptTelegramUpdateHandler.php)
использует локальные порты inbox и транзакции, а также публичный `Platform IOutboxWriter`.
DAO, transaction runner и writer получают одно PostgreSQL-соединение. Операция владеет верхнеуровневой
транзакцией; чужая активная транзакция отклоняется без её изменения. Receipt возвращается только
после подтверждённого commit. Ошибка до commit откатывает обе новые записи; неопределённый commit
не разрешает успешный ответ, внутренний повтор или компенсирующее удаление.

Первый приём, включая `IgnoredUpdate`, создаёт `RECEIVED` inbox с нулём попыток, без профиля и lease.
Raw JSON сохраняется в JSONB, SHA-256 относится к исходным HTTP-байтам, время хранится в UTC,
срок raw payload — 30 дней от приёма. Через writer создаётся намерение
`telegram.update.received/1.0 → critical`; payload содержит только внутренний UUID inbox.
RabbitMQ в HTTP-транзакции не вызывается.

Уникальная пара `(bot_key, update_id)` защищает последовательные и конкурентные повторы.
Первый сохранённый body остаётся исходным: duplicate не меняет payload, hash, статус, timestamps
или TTL и не создаёт новый outbox, в том числе после терминального состояния inbox.
Непредставимый в JSONB новый payload получает безопасный `400 webhook_invalid_update`;
временная недоступность — `503`, нарушение целостности или неожиданная ошибка — `500`.
Ни Users, ни сессия, ни административная identity при приёме не создаются.
`200 {"ok":true}` подтверждает durable приём или duplicate, но не выполнение worker-ом.

## Транспортные модели

В Infrastructure добавлены неизменяемые типы `MessageUpdate`, `CallbackQueryUpdate`,
`MyChatMemberUpdate` и `IgnoredUpdate`. Они раздельно сохраняют ID отправителя, чата и участника
события изменения статуса бота. `IgnoredUpdate` сохраняет исходный вид обновления и безопасную причину
игнорирования. Инфраструктурный parser ограничивает размер и глубину JSON, строго проверяет необходимые
поля и преобразует личные `message`, `callback_query` и `my_chat_member` в эти модели.
Для события с достоверным `update_id`, которое нельзя обработать, он возвращает `IgnoredUpdate`;
для некорректного JSON или недостоверного `update_id` — безопасную ошибку до записи в inbox.
У проигнорированного callback сохраняется только ID запроса для будущего подтверждения, не его `data`.
Данные callback в результате parser остаются непроверенными. Отдельный codec ниже может проверить
их подпись, но parser сам его не вызывает. Обработчики сессий и исходящее подтверждение
callback пока не реализованы.

Отдельный классификатор сопоставляет текст `MessageUpdate` с `/start`, точным текстом
reply-кнопок `Следующая идея` и `Отменить` либо `OTHER_TEXT`. Он не меняет текст и не
определяет состояние диалога: обычный текст может стать комментарием только в будущем
обработчике при ожидании комментария. Проверка callback через codec также подключается
позже, после получения доверенного Telegram-профиля.

## Подписанные callback

Инфраструктурный codec создаёт и проверяет callback версии `1` в формате
`version.action.subject.signature` (не более 64 байт). Действия `d/o/p` содержат UUID карточки
в каноническом Base64URL, `c/x` — неотрицательную ревизию в строчной base36. Подпись HMAC-SHA256
привязана к доверенным `bot_key` и UUID Telegram-профиля. Codec отвергает неизвестные и
неканонические значения безопасной ошибкой, не раскрывающей входные данные.

Ключи передаются codec в каноническом стандартном Base64: текущий ключ подписывает и проверяет,
необязательный предыдущий только проверяет. Каждый ключ после декодирования должен содержать не
менее 32 байт; значения ключей не хранятся в коде модуля. В версии `1` нет срока действия:
после удаления предыдущего ключа старые кнопки становятся недействительными.

Проверенная подпись подтверждает только целостность кнопки, но не право выполнить действие.
Получение доверенного профиля, подключение codec к конфигурации приложения, проверки сессии и
бизнес-доступа и ответ на callback остаются для следующих срезов.

## Владение данными

Telegram владеет состоянием диалога и inbox входящих обновлений. Профиль принадлежит Users,
общая учётная запись и идентичности — Core. Сессия и опционально входящее обновление связаны
с `telegram_identity_profiles.id` внешними ключами с `RESTRICT`.
Эти внешние ключи не разрешают прикладному коду Telegram обращаться к таблицам или репозиториям Users.
Административная аутентификация не связана с таблицами Telegram.

## Хранение и ограничения

### Сессии

- На сочетание `(bot_key, chat_id)` допускается одна сессия.
- Состояния: `BROWSING`, `AWAITING_LEAD_COMMENT`, `AWAITING_LEAD_CONFIRMATION`, `LEAD_REPLY`.
- Источник содержимого: `DEMO` или `CATALOG`.
- Комментарий и срок его действия заполняются вместе только в состоянии подтверждения.
- `interaction_revision` и `lock_version` неотрицательны, начинаются с нуля и имеют разные назначения.
- `content_subject_id` не является физическим FK или источником подтверждённого показа идеи.
- `agency_lead_id` — nullable UUID без FK в текущей реализации.
- Миграция задаёт defaults технических времён; mapper при вставке передаёт явные UTC `created_at` и `updated_at`.
  При изменении репозиторий записывает явный UTC `updated_at`.

Миграция не реализует увеличение ревизий, optimistic locking, истечение срока черновика или проверку доступа к заявке.
Эта миграция не реализует Application-сценарии, HTTP endpoints, workers, Redis-ключи и внешние адаптеры.

### Входящие обновления

- Типы обновлений: `MESSAGE`, `CALLBACK_QUERY`, `MY_CHAT_MEMBER`, `UNSUPPORTED`.
- Состояния обработки: `RECEIVED`, `PROCESSING`, `RETRY_SCHEDULED`, `PROCESSED`, `FAILED`, `IGNORED`.
- Повторное обновление одного бота отсекается уникальной парой `(bot_key, update_id)`.
- Связь с Telegram-профилем nullable: обновление можно принять до разрешения identity.
- Lease-поля обязательны только для `PROCESSING`; время следующей попытки — только для `RETRY_SCHEDULED`.
- В терминальных состояниях обязательно `processed_at`, а исходный payload уже отсутствует.
- Исходный JSON хранится только для продолжимых состояний, не дольше 30 дней с момента приёма.
- Хеш payload хранится как lowercase SHA-256; число попыток не может быть отрицательным.

Схема не реализует захват lease, повторные попытки, очистку payload или переходы между состояниями.
DAO и Application-handler реализуют только начальный durable приём. Обработчик принятых обновлений
и scheduler пока отсутствуют.

## Миграции

[Миграция таблицы сессий](../../../modules/telegram/infrastructure/migrations/m260913_090000_create_telegram_bot_sessions_table.php)
и [миграция inbox](../../../modules/telegram/infrastructure/migrations/m260913_172000_create_telegram_updates_table.php)
зарегистрированы в [общем списке namespace](../../../config/migration_namespaces.php).
Они применяются после миграции Telegram-профиля в общей истории Yii migrations.

В тестовом окружении явно переопределите `DB_DSN`: entrypoint `tests/bin/yii` использует console-конфигурацию.
Одного `TEST_DB_DSN` для команды миграций недостаточно.

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm php tests/bin/yii migrate/up --interactive=0
```

Пример предназначен для существующей локальной тестовой БД; DSN передаётся одним аргументом.
Перед применением проверьте фактическое имя БД и список новых миграций.
Откат удаляет таблицу соответствующего среза и её данные. Проверять rollback разрешается только на отдельной временной БД;
не используйте `fresh` или общий откат для рабочей базы.

## Проверки

[Domain-тесты](../../../tests/unit/modules/telegram/domain) проверяют создание, восстановление,
переходы, отказы, границы TTL и ревизии. [Архитектурный тест](../../../tests/unit/modules/telegram/TelegramArchitectureTest.php)
проверяет зависимости Domain и запрещённые обращения к окружению, часам, случайности и файловому I/O,
а также границы Application и отсутствие импортов Users/Core/Platform в mapper сессии.
После пересборки application image в настроенном тестовом окружении:

```bash
docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e DB_USERNAME=ideakit -e TEST_DB_USERNAME=ideakit -e DB_PASSWORD=local-development-only -e TEST_DB_PASSWORD=local-development-only php-fpm vendor/bin/codecept run unit tests/unit/modules/telegram/domain --no-colors
```

[Contract-тест](../../../tests/unit/modules/telegram/application/TelegramSessionPersistenceContractTest.php)
и [unit-тест mapper](../../../tests/unit/modules/telegram/infrastructure/mapper/TelegramBotSessionMapperTest.php)
проверяют версии, безопасные ошибки, строгое восстановление и наборы параметров записи.
[PostgreSQL mapping-тест](../../../tests/integration/modules/telegram/infrastructure/TelegramBotSessionMappingTest.php)
проверяет реальный round-trip, bigint, UTC-микросекунды, reserved states и очистку черновика
с сохранением исключённых из UPDATE полей. Синтетические строки и настройки `SET LOCAL`
откатываются тестовой транзакцией.

Репозиторий проверяют отдельные PostgreSQL-наборы: [операции и версии](../../../tests/integration/modules/telegram/infrastructure/DbTelegramBotSessionRepositoryTest.php),
[конкурентные процессы](../../../tests/integration/modules/telegram/infrastructure/DbTelegramBotSessionConcurrencyTest.php)
и [storage boundaries](../../../tests/integration/modules/telegram/infrastructure/TelegramSessionStorageBoundaryTest.php).
[Session DI-тест](../../../tests/integration/config/TelegramSessionContainerBindingsTest.php) проверяет
текущий application db, caller rollback и ленивые web/console bootstrap в отдельных процессах.
Архитектурный gate проверяет отсутствие импортов чужих модулей в session persistence-компонентах.

[Переходный PostgreSQL-набор](../../../tests/integration/modules/telegram/infrastructure/TelegramBotSessionTransitionsTest.php)
проверяет оформление между отдельными commit/reload, сохранение завершений и очистки черновика,
защиту нового черновика от старого подтверждения и rollback очистки. Проверки конкуренции,
storage boundaries и DI переиспользуются из существующих наборов. Эти тесты подтверждают модель
и хранилище сессии, но не обработку update или доставку Telegram-сообщений.

[Functional-тест](../../../tests/functional/modules/telegram/WebhookCest.php) использует настоящий web config
и fragment с test-only acceptor: проверяет HTTP gates, receipt, отсутствие побочных эффектов,
CSRF/JWT scope и отдельные Yii-процессы для безопасных ошибок и dev bootstrap.

[Durable HTTP-тест](../../../tests/functional/modules/telegram/DurableWebhookCest.php) проверяет реальный
commit/duplicate, JSONB-отказ, rollback и безопасные ответы/логи. [DI integration-тест](../../../tests/integration/config/TelegramAcceptanceContainerBindingsTest.php)
проверяет production-привязки web/console, общую транзакцию и ленивый bootstrap.
PostgreSQL-тесты [приёма](../../../tests/integration/modules/telegram/infrastructure/DbTelegramInboxAcceptanceTest.php),
[конкуренции](../../../tests/integration/modules/telegram/infrastructure/DbTelegramInboxConcurrencyTest.php)
и [transaction runner](../../../tests/integration/modules/telegram/infrastructure/DbTelegramInboxTransactionRunnerTest.php)
проверяют first-body-wins, terminal duplicates, lock timeout, rollback, чужую транзакцию и неопределённый commit.

После пересборки application image:

```bash
docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e DB_USERNAME=ideakit -e TEST_DB_USERNAME=ideakit -e DB_PASSWORD=local-development-only -e TEST_DB_PASSWORD=local-development-only php-fpm vendor/bin/codecept run functional tests/functional/modules/telegram --no-colors
docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e DB_USERNAME=ideakit -e TEST_DB_USERNAME=ideakit -e DB_PASSWORD=local-development-only -e TEST_DB_PASSWORD=local-development-only php-fpm vendor/bin/codecept run integration tests/integration/config/TelegramAcceptanceContainerBindingsTest.php --no-colors

docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e DB_USERNAME=ideakit -e TEST_DB_USERNAME=ideakit -e DB_PASSWORD=local-development-only -e TEST_DB_PASSWORD=local-development-only php-fpm vendor/bin/codecept run integration tests/integration/config/TelegramSessionContainerBindingsTest.php --no-colors
docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e DB_USERNAME=ideakit -e TEST_DB_USERNAME=ideakit -e DB_PASSWORD=local-development-only -e TEST_DB_PASSWORD=local-development-only php-fpm vendor/bin/codecept run integration tests/integration/modules/telegram/infrastructure/TelegramBotSessionTransitionsTest.php --no-colors
docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e DB_USERNAME=ideakit -e TEST_DB_USERNAME=ideakit -e DB_PASSWORD=local-development-only -e TEST_DB_PASSWORD=local-development-only php-fpm vendor/bin/codecept run integration tests/integration/modules/telegram/infrastructure --no-colors
docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e DB_USERNAME=ideakit -e TEST_DB_USERNAME=ideakit -e DB_PASSWORD=local-development-only -e TEST_DB_PASSWORD=local-development-only php-fpm vendor/bin/codecept run unit tests/unit/modules/telegram --no-colors
```

Команды используют опубликованные синтетические credentials отдельной `ideakit_test`; приём проверен
без запущенного RabbitMQ. Полный regression требует [явного тестового окружения Platform](../platform/README.md#явное-тестовое-окружение),
проверенных `ideakit_test` и `ideakit_transport_test`. Docker PHP 8.2.32 не подтверждает CI на PHP 8.1.

Unit-тесты транспортных моделей, parser и callback codec:

```bash
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/telegram/infrastructure/transport/update --no-colors
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/telegram/infrastructure/transport/callback/TelegramCallbackCodecTest.php --no-colors
```

[Schema integration-тест сессий](../../../tests/integration/modules/telegram/infrastructure/TelegramBotSessionSchemaTest.php),
[schema integration-тест inbox](../../../tests/integration/modules/telegram/infrastructure/TelegramUpdateSchemaTest.php)
и [lifecycle-тест migrations](../../../tests/integration/modules/telegram/infrastructure/TelegramMigrationsLifecycleTest.php)
проверяют колонки, defaults, FK, уникальность, `CHECK`, индексы, регистрацию namespace и безопасный цикл
отката с повторным применением на PostgreSQL.

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/modules/telegram/infrastructure/TelegramBotSessionSchemaTest.php
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/modules/telegram/infrastructure/TelegramUpdateSchemaTest.php
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/modules/telegram/infrastructure/TelegramMigrationsLifecycleTest.php
docker compose exec -T php-fpm composer lint
docker compose exec -T php-fpm composer unclestan:6
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm composer test
```

Эти тесты используют настроенную `ideakit_test`; её схема должна быть подготовлена.
Тест форматирования самого тестового файла запускается отдельно, поскольку общий formatter исключает `tests`:

```bash
docker compose exec -T php-fpm vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override tests/integration/modules/telegram/infrastructure/TelegramBotSessionSchemaTest.php
docker compose exec -T php-fpm vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override tests/integration/modules/telegram/infrastructure/TelegramUpdateSchemaTest.php
docker compose exec -T php-fpm vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override tests/integration/modules/telegram/infrastructure/TelegramMigrationsLifecycleTest.php
```

## Граница выделения модуля

Сейчас целостность с профилем обеспечивается FK общей PostgreSQL-базы.
При выделении Telegram в отдельный сервис этот FK потребует замены проверяемым публичным контрактом.
Текущая миграция не создаёт межсервисную инфраструктуру.
