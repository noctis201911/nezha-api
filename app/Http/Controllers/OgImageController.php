<?php

namespace App\Http\Controllers;

use App\CentralLogics\Helpers;
use App\Models\Food;
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

            return $this->serveJpg($disk, $srcPath, 'r' . $id);
        } catch (\Throwable $e) {
            return $this->brandFallback();
        }
    }

    /**
     * 商品分享缩略图: 收信人在微信看到的就是这道菜的图。
     * 取商品主图 (product/ 目录), 与餐厅版同样转 jpg+缓存+兜底, 给商品分享落地页 /product/[id] 用。
     */
    public function food($id)
    {
        try {
            $food = Food::find($id);
            if (!$food || !$food->image) {
                return $this->brandFallback();
            }

            $disk = Helpers::getDisk();
            $srcPath = 'product/' . $food->image;
            if (!Storage::disk($disk)->exists($srcPath)) {
                return $this->brandFallback();
            }

            return $this->serveJpg($disk, $srcPath, 'f' . $id);
        } catch (\Throwable $e) {
            return $this->brandFallback();
        }
    }

    /**
     * 把源图转 jpg(<=1200宽, quality85) + 磁盘缓存后返回。
     * 缓存键带源文件修改时间, 商家换图后自动失效重建。
     */
    private function serveJpg($disk, $srcPath, $keyPrefix)
    {
        $mtime = Storage::disk($disk)->lastModified($srcPath);
        $cacheRel = 'og-cache/' . $keyPrefix . '-' . substr(md5($srcPath . $mtime), 0, 16) . '.jpg';

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
    }

    private function brandFallback()
    {
        return redirect()->away('https://nezha.am/static/icon-512.png');
    }
}
