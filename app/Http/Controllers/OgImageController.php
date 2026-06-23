<?php

namespace App\Http\Controllers;

use App\CentralLogics\Helpers;
use App\Models\Food;
use App\Models\Restaurant;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;

class OgImageController extends Controller
{
    // 中文字体(服务器自带 WenQuanYi Zen Hei) + 海报缓存版本(改版式时 +1 即可整体失效重建)
    const CJK_FONT = '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc';
    const POSTER_VER = '1';

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
            $srcPath = $this->restaurantSrc($disk, $restaurant);
            if (!$srcPath) {
                return $this->brandFallback();
            }
            return $this->serveJpg($disk, $srcPath, 'r' . $id);
        } catch (\Throwable $e) {
            return $this->brandFallback();
        }
    }

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
     * 微信推广海报(餐厅): 一张可直接发到微信群/朋友圈的图 —— 店招图 + 店名 + 二维码。
     * 微信粘链接不显缩略图(微信规则), 但图片一定显示; 顾客长按识别二维码即可进店点餐。
     */
    public function restaurantPoster($id)
    {
        try {
            $restaurant = Restaurant::find($id);
            if (!$restaurant) {
                return $this->brandFallback();
            }
            $disk = Helpers::getDisk();
            $srcPath = $this->restaurantSrc($disk, $restaurant);
            if (!$srcPath) {
                return $this->brandFallback();
            }
            $slug = $restaurant->slug ?: $id;
            $url = 'https://nezha.am/restaurants/' . $slug;
            return $this->buildPoster($disk, $srcPath, $restaurant->name, '扫码进店 · 在哪吒外卖在线点餐', $url, 'pr' . $id);
        } catch (\Throwable $e) {
            return $this->brandFallback();
        }
    }

    /**
     * 微信推广海报(商品): 商品图 + 菜名 + 二维码, 扫码直达该菜加购。
     */
    public function foodPoster($id)
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
            $url = 'https://nezha.am/product/' . $id;
            return $this->buildPoster($disk, $srcPath, $food->name, '扫码进店 · 在哪吒外卖点这道菜', $url, 'pf' . $id);
        } catch (\Throwable $e) {
            return $this->brandFallback();
        }
    }

    // ---- 取图优先级: 商家分享图 -> 封面 -> logo ----
    private function restaurantSrc($disk, $restaurant)
    {
        $candidates = [
            ['restaurant/', $restaurant->meta_image],
            ['restaurant/cover/', $restaurant->cover_photo],
            ['restaurant/', $restaurant->logo],
        ];
        foreach ($candidates as [$dir, $file]) {
            if ($file && Storage::disk($disk)->exists($dir . $file)) {
                return $dir . $file;
            }
        }
        return null;
    }

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

    /**
     * 合成海报(纯 GD): 顶部店招图(裁切铺满) + 下方白底店名/副标题 + 二维码 + 提示 + 品牌脚注。
     * 配色保持中性(白底/深灰字/黑二维码), 不擅自用预设品牌色; 颜色由店招图本身提供。
     */
    private function buildPoster($disk, $srcPath, $title, $subtitle, $url, $keyPrefix)
    {
        $mtime = Storage::disk($disk)->lastModified($srcPath);
        $cacheRel = 'og-cache/poster-' . $keyPrefix . '-' . substr(md5($srcPath . $mtime . $url . self::POSTER_VER), 0, 16) . '.jpg';
        if (Storage::disk($disk)->exists($cacheRel)) {
            return response(Storage::disk($disk)->get($cacheRel), 200, [
                'Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        $W = 800; $photoH = 600; $H = 1040; $pad = 48;
        $font = self::CJK_FONT;

        $poster = imagecreatetruecolor($W, $H);
        $white = imagecolorallocate($poster, 255, 255, 255);
        $dark  = imagecolorallocate($poster, 34, 34, 34);
        $gray  = imagecolorallocate($poster, 130, 130, 130);
        $line  = imagecolorallocate($poster, 235, 235, 235);
        $black = imagecolorallocate($poster, 0, 0, 0);
        imagefilledrectangle($poster, 0, 0, $W, $H, $white);

        // 顶部店招图: cover 裁切铺满 W x photoH
        $photo = @imagecreatefromstring(Storage::disk($disk)->get($srcPath));
        if ($photo) {
            $sw = imagesx($photo); $sh = imagesy($photo);
            $scale = max($W / $sw, $photoH / $sh);
            $nw = (int) ceil($sw * $scale); $nh = (int) ceil($sh * $scale);
            $dx = (int) (($W - $nw) / 2); $dy = (int) (($photoH - $nh) / 2);
            imagecopyresampled($poster, $photo, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);
            imagedestroy($photo);
        }

        // 店名(最多两行, 超出省略)
        $lines = $this->wrapText($font, 34, $title, $W - 2 * $pad, 2);
        $ty = $photoH + 62;
        foreach ($lines as $ln) {
            imagettftext($poster, 34, 0, $pad, $ty, $dark, $font, $ln);
            $ty += 50;
        }
        // 副标题
        imagettftext($poster, 20, 0, $pad, $ty + 6, $gray, $font, $subtitle);

        // 分隔线
        $divY = $photoH + 196;
        imagefilledrectangle($poster, $pad, $divY, $W - $pad, $divY + 1, $line);

        // 二维码(自绘 GD, 不依赖 imagick)
        $qrPx = 188; $qx = $pad; $qy = $divY + 26;
        $this->drawQr($poster, $url, $qx, $qy, $qrPx, $black, $white);

        // 二维码右侧提示
        $cx = $qx + $qrPx + 34;
        imagettftext($poster, 26, 0, $cx, $qy + 70, $dark, $font, '长按图片');
        imagettftext($poster, 26, 0, $cx, $qy + 112, $dark, $font, '识别二维码进店');
        imagettftext($poster, 20, 0, $cx, $qy + 156, $gray, $font, '点餐 / 看菜单 / 下单');

        // 品牌脚注
        $foot = '哪吒外卖   nezha.am';
        $bb = imagettfbbox(22, 0, $font, $foot);
        $fw = $bb[2] - $bb[0];
        imagettftext($poster, 22, 0, (int) (($W - $fw) / 2), $H - 34, $gray, $font, $foot);

        ob_start();
        imagejpeg($poster, null, 88);
        $bytes = ob_get_clean();
        imagedestroy($poster);

        Storage::disk($disk)->put($cacheRel, $bytes);
        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    // 用 BaconQrCode 拿矩阵, 自己用 GD 画黑白格(无需 imagick)
    private function drawQr($poster, $url, $qx, $qy, $sizePx, $black, $white)
    {
        $qr = Encoder::encode($url, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ECODING);
        $m = $qr->getMatrix();
        $modules = $m->getWidth();
        $quiet = 4;
        $total = $modules + 2 * $quiet;
        $px = max(1, intdiv($sizePx, $total));
        $real = $px * $total;

        $qrim = imagecreatetruecolor($real, $real);
        $qw = imagecolorallocate($qrim, 255, 255, 255);
        $qb = imagecolorallocate($qrim, 0, 0, 0);
        imagefilledrectangle($qrim, 0, 0, $real, $real, $qw);
        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($m->get($x, $y) === 1) {
                    $ox = ($x + $quiet) * $px;
                    $oy = ($y + $quiet) * $px;
                    imagefilledrectangle($qrim, $ox, $oy, $ox + $px - 1, $oy + $px - 1, $qb);
                }
            }
        }
        imagecopyresampled($poster, $qrim, $qx, $qy, 0, 0, $sizePx, $sizePx, $real, $real);
        imagedestroy($qrim);
    }

    // 按像素宽度折行(CJK友好), 最多 $maxLines 行, 超出末行加省略号
    private function wrapText($font, $size, $text, $maxWidth, $maxLines)
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $cur = '';
        foreach ($chars as $ch) {
            $try = $cur . $ch;
            $bb = imagettfbbox($size, 0, $font, $try);
            if (($bb[2] - $bb[0]) > $maxWidth && $cur !== '') {
                $lines[] = $cur;
                $cur = $ch;
                if (count($lines) === $maxLines) {
                    break;
                }
            } else {
                $cur = $try;
            }
        }
        if (count($lines) < $maxLines && $cur !== '') {
            $lines[] = $cur;
        } elseif (count($lines) === $maxLines) {
            // 还有剩余 -> 末行加省略号
            $last = rtrim($lines[$maxLines - 1]);
            while (true) {
                $bb = imagettfbbox($size, 0, $font, $last . '…');
                if (($bb[2] - $bb[0]) <= $maxWidth || mb_strlen($last) <= 1) {
                    break;
                }
                $last = mb_substr($last, 0, mb_strlen($last) - 1);
            }
            $lines[$maxLines - 1] = $last . '…';
        }
        return $lines ?: [''];
    }

    private function brandFallback()
    {
        return redirect()->away('https://nezha.am/static/icon-512.png');
    }
}
