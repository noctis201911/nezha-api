<?php

namespace App\Http\Controllers;

use App\CentralLogics\Helpers;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;

class OgImageController extends Controller
{
    /**
     * 分享缩略图 (og:image) 统一输出 JPG。
     * 背景: 全站图片在上传时被统一重编码为 webp, 而微信等链接卡片爬虫对 webp 支持不稳定,
     * 导致分享出去没缩略图。这里按 商家分享图(meta_image) -> 封面 -> logo 的优先级取图,
     * 统一转成 jpg 并做磁盘缓存后返回, 保证微信/Telegram/WhatsApp/Facebook 各平台都能显示。
     * 任何异常都兜底回品牌图, 绝不 500 (爬虫拿不到图比难看更糟)。
     */
    public function restaurant($id)
    {
        try {
            $restaurant = Restaurant::find($id);
            if (!$restaurant) {
                return $this->brandFallback();
            }

            $disk = Helpers::getDisk();
            $candidates = [
                ['restaurant/', $restaurant->meta_image],
                ['restaurant/cover/', $restaurant->cover_photo],
                ['restaurant/', $restaurant->logo],
            ];
            $srcPath = null;
            foreach ($candidates as [$dir, $file]) {
                if ($file && Storage::disk($disk)->exists($dir . $file)) {
                    $srcPath = $dir . $file;
                    break;
                }
            }
            if (!$srcPath) {
                return $this->brandFallback();
            }

            // 缓存键带源文件修改时间, 商家换图后自动失效重建
            $mtime = Storage::disk($disk)->lastModified($srcPath);
            $cacheRel = 'og-cache/' . $id . '-' . substr(md5($srcPath . $mtime), 0, 16) . '.jpg';

            if (!Storage::disk($disk)->exists($cacheRel)) {
                $manager = new ImageManager(Driver::class);
                $img = $manager->read(Storage::disk($disk)->get($srcPath));
                if ($img->width() > 1200) {
                    $img->scaleDown(width: 1200);
                }
                $encoded = $img->encode(new JpegEncoder(quality: 85));
                Storage::disk($disk)->put($cacheRel, (string) $encoded);
            }

            return response(Storage::disk($disk)->get($cacheRel), 200, [
                'Content-Type'  => 'image/jpeg',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        } catch (\Throwable $e) {
            return $this->brandFallback();
        }
    }

    private function brandFallback()
    {
        return redirect()->away('https://nezha.am/static/icon-512.png');
    }
}
