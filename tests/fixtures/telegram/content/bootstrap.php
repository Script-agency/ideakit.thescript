<?php

declare(strict_types=1);

use modules\telegram\application\port\IIdeaContentProvider;
use modules\telegram\application\port\ITelegramCallbackEncoder;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\infrastructure\config\InvalidTelegramContentConfigException;
use modules\telegram\infrastructure\config\TelegramContentConfig;
use modules\telegram\infrastructure\content\ConfiguredDemoIdeaProvider;
use modules\telegram\infrastructure\transport\callback\CallbackAction;
use modules\telegram\infrastructure\transport\callback\CallbackSigningKeys;
use modules\telegram\infrastructure\transport\callback\CodecTelegramCallbackEncoder;
use modules\telegram\infrastructure\transport\callback\TelegramCallbackCodec;
use modules\telegram\presentation\keyboard\TelegramKeyboardFactory;
use modules\telegram\presentation\message\TelegramMessageRenderer;
use tests\fixtures\platform\PlatformTestEnvironment;
use yii\db\Connection;

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
define('YII_ENV', 'test');
define('YII_DEBUG', false);
require $root . '/vendor/yiisoft/yii2/Yii.php';

$_ENV = PlatformTestEnvironment::applicationEnvironment();
$_ENV['COOKIE_VALIDATION_KEY'] = 'synthetic-cookie-key';
// Отрицательные сценарии не должны получать режим и ключи из общего набора настроек.
foreach (['TELEGRAM_CONTENT_MODE', 'TELEGRAM_CALLBACK_CURRENT_KEY', 'TELEGRAM_CALLBACK_PREVIOUS_KEY'] as $name) {
    unset($_ENV[$name]);
}
$_SERVER = [
    'REQUEST_URI' => '/docs', 'REQUEST_METHOD' => 'GET', 'QUERY_STRING' => '',
    'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $root . '/web/index.php',
    'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'HTTP_HOST' => 'localhost',
];

try {
    $application = $argv[1] ?? '';
    $scenario = $argv[2] ?? '';
    if (!in_array($application, ['web', 'console'], true)
        || !in_array($scenario, ['basic', 'signed', 'null-mode', 'null-current'], true)) {
        throw new RuntimeException('content_probe_failed');
    }
    if ($scenario === 'null-mode') {
        $_ENV['TELEGRAM_CONTENT_MODE'] = null;
    }
    if ($scenario === 'null-current') {
        $_ENV['TELEGRAM_CALLBACK_CURRENT_KEY'] = null;
    }

    $configuration = require $root . '/config/' . $application . '.php';
    $app = $application === 'web'
        ? new yii\web\Application($configuration)
        : new yii\console\Application($configuration);
    $di = Yii::$container;
    $db = $app->get('db');
    if (!$db instanceof Connection || $db->isActive || $db->pdo !== null) {
        throw new RuntimeException('content_probe_failed');
    }

    $settings = $di->get(TelegramContentConfig::class);
    $provider = $di->get(IIdeaContentProvider::class);
    $renderer = $di->get(TelegramMessageRenderer::class);
    if ($settings->mode !== TelegramContentMode::DEMO || !$provider instanceof ConfiguredDemoIdeaProvider) {
        throw new RuntimeException('content_probe_failed');
    }
    foreach ([TelegramContentConfig::class, IIdeaContentProvider::class, TelegramMessageRenderer::class] as $class) {
        if ($di->get($class) !== $di->get($class)) {
            throw new RuntimeException('content_probe_failed');
        }
    }
    $card = $provider->first();
    if ($renderer->card($card)->text() === '') {
        throw new RuntimeException('content_probe_failed');
    }

    if ($scenario === 'signed' || $scenario === 'null-current') {
        $encoder = $di->get(ITelegramCallbackEncoder::class);
        $codec = $di->get(TelegramCallbackCodec::class);
        $di->get(CallbackSigningKeys::class);
        $factory = $di->get(TelegramKeyboardFactory::class);
        if (!$encoder instanceof CodecTelegramCallbackEncoder) {
            throw new RuntimeException('content_probe_failed');
        }
        foreach ([ITelegramCallbackEncoder::class, TelegramCallbackCodec::class,
            CallbackSigningKeys::class, TelegramKeyboardFactory::class] as $class) {
            if ($di->get($class) !== $di->get($class)) {
                throw new RuntimeException('content_probe_failed');
            }
        }

        $botKey = 'synthetic-bot';
        $profileId = '019d0000-0000-7000-8000-000000000101';
        $wire = $factory->forCard($card, $botKey, $profileId)
            ->toArray()['inline_keyboard'][0][0]['callback_data'];
        $current = getenv('TELEGRAM_CALLBACK_CURRENT_KEY');
        if (!is_string($current) || $current === '') {
            throw new RuntimeException('content_probe_failed');
        }
        $currentOnly = new TelegramCallbackCodec(CallbackSigningKeys::fromBase64($current));
        $verified = $currentOnly->decode($wire, $botKey, $profileId);
        if ($verified->action !== CallbackAction::DETAILS || $verified->subject !== $card->id()) {
            throw new RuntimeException('content_probe_failed');
        }

        $previous = getenv('TELEGRAM_CALLBACK_PREVIOUS_KEY');
        if (is_string($previous) && $previous !== '') {
            $previousOnly = new TelegramCallbackCodec(CallbackSigningKeys::fromBase64($previous));
            $oldWire = $previousOnly->encodeCard(CallbackAction::DETAILS, $card->id(), $botKey, $profileId);
            $oldVerified = $codec->decode($oldWire, $botKey, $profileId);
            if ($oldVerified->subject !== $card->id()) {
                throw new RuntimeException('content_probe_failed');
            }
        }
    }

    if ($db->getIsActive() || $db->pdo !== null) {
        throw new RuntimeException('content_probe_failed');
    }
    echo "content_probe_ok\n";
} catch (Throwable $failure) {
    $reason = 'content_probe_failed';
    if ($failure instanceof InvalidTelegramContentConfigException
        && !Yii::$container->has(IIdeaContentProvider::class)) {
        $reason = $failure->getMessage();
    } elseif ($failure instanceof InvalidArgumentException
        && $failure->getMessage() === 'invalid_callback_key_config') {
        $reason = 'invalid_callback_key_config';
    }
    fwrite(STDERR, $reason . "\n");
    exit(1);
}
