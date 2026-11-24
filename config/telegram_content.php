<?php

declare(strict_types=1);

use modules\telegram\application\port\IIdeaContentProvider;
use modules\telegram\application\port\ITelegramCallbackEncoder;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\infrastructure\config\InvalidTelegramContentConfigException;
use modules\telegram\infrastructure\config\TelegramContentConfig;
use modules\telegram\infrastructure\content\ConfiguredDemoIdeaProvider;
use modules\telegram\infrastructure\transport\callback\CallbackSigningKeys;
use modules\telegram\infrastructure\transport\callback\CodecTelegramCallbackEncoder;
use modules\telegram\infrastructure\transport\callback\TelegramCallbackCodec;
use modules\telegram\presentation\keyboard\TelegramKeyboardFactory;
use modules\telegram\presentation\message\TelegramMessageRenderer;
use yii\di\Container;

/** @return Closure(Container): void */
return static function (Container $container): void {
    $readEnvironment = static function (string $name): mixed {
        if (array_key_exists($name, $_ENV)) {
            return $_ENV[$name];
        }
        $value = getenv($name);

        return $value === false ? null : $value;
    };

    $settings = TelegramContentConfig::fromEnvironment([
        'TELEGRAM_CONTENT_MODE' => $readEnvironment('TELEGRAM_CONTENT_MODE'),
    ]);
    if ($settings->mode === TelegramContentMode::CATALOG) {
        throw InvalidTelegramContentConfigException::catalogUnavailable();
    }

    $container->setSingleton(TelegramContentConfig::class, $settings);
    $container->setSingleton(IIdeaContentProvider::class, ConfiguredDemoIdeaProvider::class);
    $container->setSingleton(TelegramMessageRenderer::class, TelegramMessageRenderer::class);

    $container->setSingleton(CallbackSigningKeys::class, static function () use ($readEnvironment): CallbackSigningKeys {
        $current = $readEnvironment('TELEGRAM_CALLBACK_CURRENT_KEY');
        $previous = $readEnvironment('TELEGRAM_CALLBACK_PREVIOUS_KEY');
        if (!is_string($current) || $current === '' || ($previous !== null && !is_string($previous))) {
            throw new InvalidArgumentException('invalid_callback_key_config');
        }

        return CallbackSigningKeys::fromBase64($current, $previous === '' ? null : $previous);
    });
    $container->setSingleton(TelegramCallbackCodec::class, static function (Container $di): TelegramCallbackCodec {
        return new TelegramCallbackCodec($di->get(CallbackSigningKeys::class));
    });
    $container->setSingleton(ITelegramCallbackEncoder::class, static function (Container $di): ITelegramCallbackEncoder {
        return new CodecTelegramCallbackEncoder($di->get(TelegramCallbackCodec::class));
    });
    $container->setSingleton(TelegramKeyboardFactory::class, static function (Container $di): TelegramKeyboardFactory {
        return new TelegramKeyboardFactory($di->get(ITelegramCallbackEncoder::class));
    });
};
