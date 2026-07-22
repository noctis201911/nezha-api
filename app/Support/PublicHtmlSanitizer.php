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
            'p[style],br,strong,b,em,i,u,ul,ol,li,h1,h2,h3,h4,h5,h6,blockquote,a[href|title],div,span[style],hr'
        );
        $config->set('CSS.AllowedProperties', [
            'color',
            'font-size',
            'font-weight',
            'line-height',
            'margin',
            'margin-bottom',
            'margin-left',
            'margin-right',
            'margin-top',
            'text-align',
        ]);
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
            'tel' => true,
        ]);

        self::$purifier = new HTMLPurifier($config);

        return self::$purifier;
    }
}
