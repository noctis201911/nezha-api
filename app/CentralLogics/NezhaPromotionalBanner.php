<?php

namespace App\CentralLogics;

use App\Models\DataSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class NezhaPromotionalBanner
{
    public static function hasPublishableTitle(?string $title): bool
    {
        $title = trim((string) $title);

        return $title !== '' && strcasecmp($title, 'Demo Banner') !== 0;
    }

    public static function imageExists(?string $image, ?string $storage): bool
    {
        $image = trim((string) $image);

        if ($image === '' || str_contains($image, '/') || str_contains($image, '\\')) {
            return false;
        }

        try {
            $disk = $storage === 's3' ? 's3' : 'public';

            return Storage::disk($disk)->exists('banner/'.$image);
        } catch (Throwable) {
            return false;
        }
    }

    public static function forgetLegacyCache(): void
    {
        Cache::forget('data_settings_promotional_banner');
        Cache::forget('data_settings_promotional_banner_storage');
    }

    public static function configuration(): ?array
    {
        $requiredKeys = [
            'promotional_banner_status',
            'promotional_banner_title',
            'promotional_banner_image',
        ];
        $settings = DataSetting::where('type', 'promotional_banner')
            ->whereIn('key', $requiredKeys)
            ->get();
        $settingsByKey = $settings->groupBy('key');

        foreach ($requiredKeys as $requiredKey) {
            if (($settingsByKey->get($requiredKey)?->count() ?? 0) !== 1) {
                return null;
            }
        }

        $settings = $settings->keyBy('key');
        $status = $settings->get('promotional_banner_status')?->getRawOriginal('value');

        if ($status !== '1') {
            return null;
        }

        $title = trim((string) $settings->get('promotional_banner_title')?->value);

        if (! self::hasPublishableTitle($title)) {
            return null;
        }

        $imageSetting = $settings->get('promotional_banner_image');
        $image = trim((string) $imageSetting?->value);

        if ($image === '') {
            return null;
        }

        $storage = $imageSetting?->storage[0]?->value ?? 'public';
        if (! self::imageExists($image, $storage)) {
            return null;
        }

        $imageUrl = Helpers::get_full_url(
            'banner',
            $image,
            $storage
        );

        if ($imageUrl === null) {
            return null;
        }

        return [
            'promotional_banner_title' => $title,
            'promotional_banner_image' => $image,
            'promotional_banner_image_full_url' => $imageUrl,
        ];
    }
}
