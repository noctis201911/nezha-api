<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

final class PublicHtmlSanitizer
{
    private const SETTING_KEYS = [
        'about_us',
        'cancellation_policy',
        'privacy_policy',
        'refund_policy',
        'shipping_policy',
        'terms_and_conditions',
    ];

    private static ?HTMLPurifier $purifier = null;

    public static function cleanSetting(string $key, mixed $value): mixed
    {
        if (! in_array($key, self::SETTING_KEYS, true)) {
            return $value;
        }

        return self::purifier()->purify((string) ($value ?? ''));
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.DefinitionImpl', null);
        $config->set(
            'HTML.Allowed',
            'p[style],h3,strong,ul,li'
        );
        $config->set('CSS.AllowedProperties', [
            'color',
            'font-size',
            'margin',
        ]);
        $config->set('URI.AllowedSchemes', []);

        self::$purifier = new HTMLPurifier($config);

        return self::$purifier;
    }
}
