<?php

namespace App\Http\Controllers;

use App\CentralLogics\Helpers;
use App\Models\Food;
use App\Models\Guide;
use App\Models\LocalLifeMerchant;
use App\Models\Restaurant;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;

class OgImageController extends Controller
{
    // 中文字体(服务器自带 WenQuanYi Zen Hei) + 海报缓存版本(改版式时 +1 即可整体失效重建)
    const CJK_FONT = '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc';
    const POSTER_VER = '5';
    // 链接预览"品牌卡"版本(改卡片版式 +1 整体重建)
    const CARD_VER = '1';
    // 本地生活批2 品牌卡版本(攻略/商家分享卡, 改版式 +1 整体重建)
    const GUIDE_CARD_VER = '1';
    const MERCHANT_CARD_VER = '1';
    // 德拉姆符号 ֏(U+058F) 在 wqy 中文字体里无字形(显示成方框), 价格行改用含该符号的 FreeSans
    const PRICE_FONT = '/usr/share/fonts/truetype/freefont/FreeSans.ttf';

    /**
     * 分享缩略图 (og:image)。
     * 商品: 直接出商品图(jpg)。
     * 餐厅: 出"品牌卡"—— 食物封面打底 + 底部渐变 + 店名 + 菜系·评分·配送 + 哪吒外卖标,
     *       让链接分享出去一眼就是"一家店"(而非光一道菜 / 光秃 logo)。
     * 全站图存 webp, 微信等爬虫对 webp 支持不稳定, 统一转 jpg + 磁盘缓存。
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
            [$cuisine, $rating, $cnt, $delivery] = $this->restaurantShareData($restaurant, $id);
            return $this->buildRestaurantCard($disk, $srcPath, $restaurant->name, $rating, $cuisine, $delivery, 'ogr' . $id);
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
     * 本地生活「生活攻略」分享品牌卡 (og:image · 1200x630 · 附页⑧上)。
     * 有封面: 封面裁切铺满 + 底部深色渐变 + 白题; 无封面: 米金渐变底 + 深墨题。
     * 恒: 左上「哪吒·生活攻略」品牌标 + 左下 题(2 行) + 时效「信息截至 YYYY-MM · 平台整理」。
     * 攻略开关关 / 不存在 / 未上架 → 兜底品牌图, 绝不 500。
     */
    public function guide($id)
    {
        try {
            if (!$this->guidesEnabled()) {
                return $this->brandFallback();
            }
            $g = Guide::where('status', 1)->find($id);
            if (!$g) {
                return $this->brandFallback();
            }
            $disk = Helpers::getDisk();
            $coverPath = null;
            if ($g->cover_url && Storage::disk($disk)->exists('guide/' . $g->cover_url)) {
                $coverPath = 'guide/' . $g->cover_url;
            }
            $asof = $g->info_as_of ? ('信息截至 ' . $g->info_as_of . ' · 平台整理') : '平台整理';
            return $this->buildGuideCard($disk, $coverPath, $g->title, $asof, 'ogg' . $id);
        } catch (\Throwable $e) {
            return $this->brandFallback();
        }
    }

    /**
     * 本地生活商家分享品牌卡 (og:image · 1200x630 · 附页⑧下)。
     * 暖白底 + logo + 店名 + 类目·区域 + 诚实评分行 + 底行「埃里温华人生活平台 · nezha.am」。
     * 诚实评分规则(与页内同): 有 Google 显「Google X.X」; 无 Google 有商家分显「★ X.X 评分由商家提供」; 都无整行不出。
     */
    public function merchant($id)
    {
        try {
            $m = LocalLifeMerchant::where('status', true)->find($id);
            if (!$m) {
                return $this->brandFallback();
            }
            $disk = Helpers::getDisk();
            $logoPath = ($m->logo && Storage::disk($disk)->exists('local-life-merchant/' . $m->logo)) ? 'local-life-merchant/' . $m->logo : null;
            $sub = implode(' · ', array_filter([$m->category, $m->area]));
            // 诚实评分
            $ratingVal = null; $ratingSrc = null;
            if ($m->google_rating !== null) {
                $ratingVal = number_format((float) $m->google_rating, 1); $ratingSrc = 'google';
            } elseif ($m->rating) {
                $ratingVal = number_format((float) $m->rating, 1); $ratingSrc = 'merchant';
            }
            return $this->buildMerchantCard($disk, $logoPath, $m->name, $sub, $ratingVal, $ratingSrc, 'ogm' . $id);
        } catch (\Throwable $e) {
            return $this->brandFallback();
        }
    }

    /**
     * 微信推广海报(餐厅): 一张可直接发到微信群/朋友圈的图 —— 食物封面 + 店名 + 菜系·评分·配送 + 二维码。
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
            [$cuisine, $rating, $cnt, $delivery] = $this->restaurantShareData($restaurant, $id);
            return $this->buildPoster($disk, $srcPath, $restaurant->name, '扫码进店 · 看菜单点餐', $url, 'pr' . $id, null, $rating, $cuisine, $delivery);
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
            // 售价(扣折扣后)用于海报价格行, 失败不影响出图
            $priceText = null;
            try {
                $disc = Helpers::product_discount_calculate($food, $food->price, $food->restaurant);
                $final = $food->price - (is_numeric($disc) ? $disc : 0);
                $priceText = Helpers::format_currency($final);
            } catch (\Throwable $e) {
                $priceText = null;
            }
            return $this->buildPoster($disk, $srcPath, $food->name, '扫码进店 · 在哪吒外卖点这道菜', $url, 'pf' . $id, $priceText);
        } catch (\Throwable $e) {
            return $this->brandFallback();
        }
    }

    // ---- 餐厅分享取图: 封面 -> logo(均为平台精修食物图)。 ----
    // 有意不取 meta_image: 该字段自由度高, 曾被塞入 EMS 快递 logo 之类无关图毁掉分享;
    // 封面/主图是平台统一精修的品牌 KV, 几乎永远是最适合分享的图。
    private function restaurantSrc($disk, $restaurant)
    {
        $candidates = [
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

    // 餐厅分享用元信息(自包含, 与前端 API 同源): [菜系, 评分数值|null, 评价数, 配送文案|null]
    private function restaurantShareData($restaurant, $id)
    {
        try { app()->setLocale('zh'); } catch (\Throwable $e) {}
        $cuisine = null;
        try { $cuisine = $restaurant->cuisine->pluck('name')->first(); } catch (\Throwable $e) {}
        $rating = null; $cnt = 0;
        try {
            $q = DB::table('reviews')->join('food', 'food.id', '=', 'reviews.food_id')->where('food.restaurant_id', $id);
            $cnt = (clone $q)->count();
            if ($cnt > 0) {
                $rating = round((float) (clone $q)->avg('reviews.rating'), 1);
            }
        } catch (\Throwable $e) {}
        $delivery = null;
        try {
            $dt = $restaurant->delivery_time; // "30-60-min" / "1-2-hour"
            if ($dt) {
                if (stripos($dt, 'hour') !== false) {
                    $delivery = trim(preg_replace('/-?\s*hour.*/i', '', $dt)) . '小时送达';
                } else {
                    $delivery = trim(preg_replace('/-?\s*min.*/i', '', $dt)) . '分钟送达';
                }
            }
        } catch (\Throwable $e) {}
        return [$cuisine, $rating, $cnt, $delivery];
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
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * 链接预览"品牌卡"(1200x630 og 标准比例, 纯 GD):
     * 食物封面 cover 裁切铺满 → 底部深色渐变(保证文字可读) → 店名 + 菜系·评分·配送 + 哪吒外卖标。
     */
    private function buildRestaurantCard($disk, $srcPath, $title, $rating, $cuisine, $delivery, $keyPrefix)
    {
        $mtime = Storage::disk($disk)->lastModified($srcPath);
        $cacheRel = 'og-cache/card-' . $keyPrefix . '-' . substr(md5($srcPath . $mtime . $title . $rating . $cuisine . $delivery . self::CARD_VER), 0, 16) . '.jpg';
        if (Storage::disk($disk)->exists($cacheRel)) {
            return response(Storage::disk($disk)->get($cacheRel), 200, [
                'Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400', 'Access-Control-Allow-Origin' => '*',
            ]);
        }

        $W = 1200; $H = 630; $pad = 56;
        $font = self::CJK_FONT;

        $card = imagecreatetruecolor($W, $H);
        $white = imagecolorallocate($card, 255, 255, 255);
        $soft  = imagecolorallocate($card, 232, 232, 232);
        $amber = imagecolorallocate($card, 243, 164, 41);
        $red   = imagecolorallocate($card, 196, 25, 62);
        $dk    = imagecolorallocate($card, 24, 24, 26);
        imagefilledrectangle($card, 0, 0, $W, $H, $dk);

        // 背景: 食物封面 cover 裁切铺满 1200x630
        $photo = @imagecreatefromstring(Storage::disk($disk)->get($srcPath));
        if ($photo) {
            $sw = imagesx($photo); $sh = imagesy($photo);
            $scale = max($W / $sw, $H / $sh);
            $srcW = (int) round($W / $scale); $srcH = (int) round($H / $scale);
            $srcX = (int) (($sw - $srcW) / 2); $srcY = (int) (($sh - $srcH) / 2);
            imagecopyresampled($card, $photo, 0, 0, $srcX, $srcY, $W, $H, $srcW, $srcH);
            imagedestroy($photo);
        }

        // 底部深色渐变(透明→黑), 保证文字可读
        imagealphablending($card, true);
        $gTop = (int) ($H * 0.40); $gH = $H - $gTop;
        for ($i = 0; $i < $gH; $i++) {
            $t = $i / $gH;
            $al = (int) round(120 - 108 * $t); // 顶部透明(120) → 底部近黑(12)
            if ($al < 0) { $al = 0; }
            $c = imagecolorallocatealpha($card, 0, 0, 0, $al);
            imagefilledrectangle($card, 0, $gTop + $i, $W, $gTop + $i, $c);
        }

        // 品牌标(左上): 哪吒红 pill + 白字"哪吒外卖"
        $btxt = '哪吒外卖';
        $bb = imagettfbbox(24, 0, $font, $btxt);
        $bw = $bb[2] - $bb[0];
        imagefilledrectangle($card, $pad, 40, $pad + $bw + 34, 92, $red);
        imagettftext($card, 24, 0, $pad + 17, 76, $white, $font, $btxt);

        // 店名(左下, 白色大字, 单行超出省略)
        $nameLines = $this->wrapText($font, 52, $title, $W - 2 * $pad, 1);
        imagettftext($card, 52, 0, $pad, $H - 82, $white, $font, $nameLines[0]);

        // 菜系·评分·配送(店名下方)
        $this->drawMetaLine($card, $pad, $H - 36, 30, $font, $rating, $cuisine, $delivery, $amber, $soft);

        ob_start();
        imagejpeg($card, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($card);

        Storage::disk($disk)->put($cacheRel, $bytes);
        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400', 'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * 合成海报(纯 GD): 顶部图(裁切铺满) + 下方白底标题/(价格|菜系评分配送)/副标题 + 二维码 + 提示 + 品牌脚注。
     * 配色保持中性(白底/深灰字/黑二维码), 颜色由图本身提供; 评分用琥珀色小星。
     */
    private function buildPoster($disk, $srcPath, $title, $subtitle, $url, $keyPrefix, $priceText = null, $metaRating = null, $metaCuisine = null, $metaDelivery = null)
    {
        $mtime = Storage::disk($disk)->lastModified($srcPath);
        $metaKey = $priceText . '|' . $metaRating . '|' . $metaCuisine . '|' . $metaDelivery;
        $cacheRel = 'og-cache/poster-' . $keyPrefix . '-' . substr(md5($srcPath . $mtime . $url . $metaKey . self::POSTER_VER), 0, 16) . '.jpg';
        if (Storage::disk($disk)->exists($cacheRel)) {
            return response(Storage::disk($disk)->get($cacheRel), 200, [
                'Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400', 'Access-Control-Allow-Origin' => '*',
            ]);
        }

        $W = 800; $photoH = 600; $H = 1080; $pad = 48;
        $font = self::CJK_FONT;

        $poster = imagecreatetruecolor($W, $H);
        $white = imagecolorallocate($poster, 255, 255, 255);
        $dark  = imagecolorallocate($poster, 34, 34, 34);
        $gray  = imagecolorallocate($poster, 130, 130, 130);
        $amber = imagecolorallocate($poster, 243, 164, 41);
        $line  = imagecolorallocate($poster, 235, 235, 235);
        $black = imagecolorallocate($poster, 0, 0, 0);
        imagefilledrectangle($poster, 0, 0, $W, $H, $white);

        // 顶部图: cover 裁切铺满 W x photoH
        $photo = @imagecreatefromstring(Storage::disk($disk)->get($srcPath));
        if ($photo) {
            $sw = imagesx($photo); $sh = imagesy($photo);
            $scale = max($W / $sw, $photoH / $sh);
            $srcW = (int) round($W / $scale); $srcH = (int) round($photoH / $scale);
            $srcX = (int) (($sw - $srcW) / 2); $srcY = (int) (($sh - $srcH) / 2);
            imagecopyresampled($poster, $photo, 0, 0, $srcX, $srcY, $W, $photoH, $srcW, $srcH);
            imagedestroy($photo);
        }

        // 文字块(标题 -> 价格|菜系评分配送 -> 副标题)
        $ty = $photoH + 58;
        foreach ($this->wrapText($font, 34, $title, $W - 2 * $pad, 2) as $ln) {
            imagettftext($poster, 34, 0, $pad, $ty, $dark, $font, $ln);
            $ty += 48;
        }
        if ($priceText) {
            imagettftext($poster, 34, 0, $pad, $ty + 10, $dark, self::PRICE_FONT, $priceText);
            $ty += 52;
        } elseif ($metaRating !== null || $metaCuisine || $metaDelivery) {
            $this->drawMetaLine($poster, $pad, $ty + 8, 24, $font, $metaRating, $metaCuisine, $metaDelivery, $amber, $gray);
            $ty += 46;
        }
        imagettftext($poster, 20, 0, $pad, $ty + 6, $gray, $font, $subtitle);

        // 分隔线
        $divY = 820;
        imagefilledrectangle($poster, $pad, $divY, $W - $pad, $divY + 1, $line);

        // 二维码(自绘 GD, 不依赖 imagick)
        $qrPx = 180; $qx = $pad; $qy = $divY + 24;
        $this->drawQr($poster, $url, $qx, $qy, $qrPx, $black, $white);

        // 二维码右侧提示
        $cx = $qx + $qrPx + 34;
        imagettftext($poster, 26, 0, $cx, $qy + 64, $dark, $font, '长按图片');
        imagettftext($poster, 26, 0, $cx, $qy + 106, $dark, $font, '识别二维码进店');
        imagettftext($poster, 20, 0, $cx, $qy + 148, $gray, $font, '点餐 / 看菜单 / 下单');

        // 品牌脚注
        $foot = '哪吒外卖   nezha.am';
        $bb = imagettfbbox(22, 0, $font, $foot);
        $fw = $bb[2] - $bb[0];
        imagettftext($poster, 22, 0, (int) (($W - $fw) / 2), $H - 32, $gray, $font, $foot);

        ob_start();
        imagejpeg($poster, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($poster);

        Storage::disk($disk)->put($cacheRel, $bytes);
        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400', 'Access-Control-Allow-Origin' => '*',
        ]);
    }

    // 一行"[★琥珀星] 评分 · 菜系 · 配送": 星用 GD 画(不依赖字体星形字), 文字用中文字体
    private function drawMetaLine($img, $x, $baselineY, $size, $font, $rating, $cuisine, $delivery, $amber, $textColor)
    {
        $parts = [];
        if ($rating !== null) { $parts[] = number_format((float) $rating, 1); }
        if ($cuisine) { $parts[] = $cuisine; }
        if ($delivery) { $parts[] = $delivery; }
        if (!$parts) { return; }
        $line = implode('  ·  ', $parts);
        $tx = $x;
        if ($rating !== null) {
            $sr = $size * 0.52;
            $this->drawStar($img, (int) ($x + $sr), (int) ($baselineY - $size * 0.42), $sr, $amber);
            $tx = (int) ($x + $sr * 2 + 12);
        }
        imagettftext($img, $size, 0, (int) $tx, $baselineY, $textColor, $font, $line);
    }

    // 画一个五角星(实心), 中心 (cx,cy), 外接半径 r
    private function drawStar($img, $cx, $cy, $r, $color)
    {
        $pts = [];
        for ($i = 0; $i < 10; $i++) {
            $a = -M_PI / 2 + $i * (M_PI / 5);
            $rr = ($i % 2 === 0) ? $r : $r * 0.42;
            $pts[] = (int) round($cx + $rr * cos($a));
            $pts[] = (int) round($cy + $rr * sin($a));
        }
        imagefilledpolygon($img, $pts, $color);
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

    /** 攻略总开关（business_settings.nezha_guides_status，默认 0 封印） */
    private function guidesEnabled(): bool
    {
        $v = DB::table('business_settings')->where('key', 'nezha_guides_status')->value('value');
        return (bool) ($v ?? 0);
    }

    // 竖直渐变填充（顶 rgb1 → 底 rgb2）
    private function fillVerticalGradient($img, $W, $H, $r1, $g1, $b1, $r2, $g2, $b2)
    {
        for ($y = 0; $y < $H; $y++) {
            $t = $H > 1 ? $y / ($H - 1) : 0;
            $c = imagecolorallocate(
                $img,
                (int) round($r1 + ($r2 - $r1) * $t),
                (int) round($g1 + ($g2 - $g1) * $t),
                (int) round($b1 + ($b2 - $b1) * $t)
            );
            imagefilledrectangle($img, 0, $y, $W, $y, $c);
        }
    }

    // 品牌标: 红「哪」圆角块 + 标签文字。$onDark=true 时先垫半透明白底保证可读。
    private function drawBrandMark($img, $x, $y, $font, $label, $onDark, $red, $white, $ink)
    {
        $box = 40; // 「哪」方块边长
        $labSize = 26;
        $lb = imagettfbbox($labSize, 0, $font, $label);
        $labW = $lb[2] - $lb[0];
        $totalW = $box + 12 + $labW;
        if ($onDark) {
            $bg = imagecolorallocatealpha($img, 255, 255, 255, 55);
            $this->fillRoundedRect($img, $x - 10, $y - 8, $x + $totalW + 12, $y + $box + 8, 12, $bg);
        }
        // 红「哪」块
        $this->fillRoundedRect($img, $x, $y, $x + $box, $y + $box, 9, $red);
        $nb = imagettfbbox(22, 0, $font, '哪');
        $nw = $nb[2] - $nb[0];
        imagettftext($img, 22, 0, (int) ($x + ($box - $nw) / 2), (int) ($y + $box - 10), $white, $font, '哪');
        // 标签文字（红色，垂直居中于块）
        imagettftext($img, $labSize, 0, $x + $box + 12, (int) ($y + $box - 10), $red, $font, $label);
    }

    // 实心圆角矩形（GD 无原生，四角画圆 + 中间两矩形拼）
    private function fillRoundedRect($img, $x1, $y1, $x2, $y2, $r, $color)
    {
        $r = (int) $r;
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledarc($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $color, IMG_ARC_PIE);
        imagefilledarc($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, 270, 360, $color, IMG_ARC_PIE);
        imagefilledarc($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, 90, 180, $color, IMG_ARC_PIE);
        imagefilledarc($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, 0, 90, $color, IMG_ARC_PIE);
    }

    /**
     * 攻略品牌卡 1200x630（附页⑧上）。有封面=封面铺满+底部渐变+白题；无封面=米金渐变+深墨题。
     */
    private function buildGuideCard($disk, $coverPath, $title, $asofText, $keyPrefix)
    {
        $mtime = $coverPath ? Storage::disk($disk)->lastModified($coverPath) : 'nocover';
        $cacheRel = 'og-cache/guidecard-' . $keyPrefix . '-' . substr(md5($coverPath . $mtime . $title . $asofText . self::GUIDE_CARD_VER), 0, 16) . '.jpg';
        if (Storage::disk($disk)->exists($cacheRel)) {
            return $this->cachedJpg($disk, $cacheRel);
        }

        $W = 1200; $H = 630; $pad = 56;
        $font = self::CJK_FONT;
        $hasCover = (bool) $coverPath;

        $card = imagecreatetruecolor($W, $H);
        $white  = imagecolorallocate($card, 255, 255, 255);
        $red    = imagecolorallocate($card, 196, 25, 62);
        $ink    = imagecolorallocate($card, 31, 35, 41);      // #1F2329
        $asofDk = imagecolorallocate($card, 138, 109, 59);    // #8A6D3B (无封面时效)
        $asofLt = imagecolorallocate($card, 234, 223, 206);   // 封面态时效浅色

        if ($hasCover) {
            $photo = @imagecreatefromstring(Storage::disk($disk)->get($coverPath));
            if ($photo) {
                $sw = imagesx($photo); $sh = imagesy($photo);
                $scale = max($W / $sw, $H / $sh);
                $srcW = (int) round($W / $scale); $srcH = (int) round($H / $scale);
                $srcX = (int) (($sw - $srcW) / 2); $srcY = (int) (($sh - $srcH) / 2);
                imagecopyresampled($card, $photo, 0, 0, $srcX, $srcY, $W, $H, $srcW, $srcH);
                imagedestroy($photo);
            } else {
                $this->fillVerticalGradient($card, $W, $H, 255, 232, 207, 246, 200, 155);
            }
            // 底部深色渐变 scrim（透明→黑）保证白字可读
            imagealphablending($card, true);
            $gTop = (int) ($H * 0.42); $gH = $H - $gTop;
            for ($i = 0; $i < $gH; $i++) {
                $t = $i / $gH;
                $al = (int) round(118 - 106 * $t);
                if ($al < 0) { $al = 0; }
                $c = imagecolorallocatealpha($card, 0, 0, 0, $al);
                imagefilledrectangle($card, 0, $gTop + $i, $W, $gTop + $i, $c);
            }
        } else {
            $this->fillVerticalGradient($card, $W, $H, 255, 232, 207, 246, 200, 155); // 米金
        }

        // 品牌标（左上）
        $this->drawBrandMark($card, $pad, 40, $font, '哪吒 · 生活攻略', $hasCover, $red, $white, $ink);

        // 题（左下，2 行）；封面态白字、无封面深墨
        $titleColor = $hasCover ? $white : $ink;
        $lines = $this->wrapText($font, 46, $title, $W - 2 * $pad, 2);
        $lineH = 62;
        $blockH = count($lines) * $lineH;
        $startY = $H - 78 - $blockH + $lineH - 12; // 底部对齐（时效上方）
        $ty = $startY;
        foreach ($lines as $ln) {
            imagettftext($card, 46, 0, $pad, $ty, $titleColor, $font, $ln);
            $ty += $lineH;
        }

        // 时效行（底）
        imagettftext($card, 26, 0, $pad, $H - 34, $hasCover ? $asofLt : $asofDk, $font, $asofText);

        return $this->outputCard($disk, $card, $cacheRel);
    }

    /**
     * 商家品牌卡 1200x630（附页⑧下）。暖白底 + logo + 店名 + 类目·区域 + 诚实评分 + 底行。
     */
    private function buildMerchantCard($disk, $logoPath, $name, $sub, $ratingVal, $ratingSrc, $keyPrefix)
    {
        $mtime = $logoPath ? Storage::disk($disk)->lastModified($logoPath) : 'nologo';
        $metaKey = $name . '|' . $sub . '|' . $ratingVal . '|' . $ratingSrc;
        $cacheRel = 'og-cache/merchantcard-' . $keyPrefix . '-' . substr(md5($logoPath . $mtime . $metaKey . self::MERCHANT_CARD_VER), 0, 16) . '.jpg';
        if (Storage::disk($disk)->exists($cacheRel)) {
            return $this->cachedJpg($disk, $cacheRel);
        }

        $W = 1200; $H = 630; $pad = 56;
        $font = self::CJK_FONT;

        $card = imagecreatetruecolor($W, $H);
        $white = imagecolorallocate($card, 255, 255, 255);
        $red   = imagecolorallocate($card, 196, 25, 62);
        $ink   = imagecolorallocate($card, 31, 35, 41);
        $gray  = imagecolorallocate($card, 154, 160, 168);   // #9AA0A8
        $amber = imagecolorallocate($card, 243, 164, 41);    // #F3A429
        $gblue = imagecolorallocate($card, 66, 133, 244);    // Google 蓝
        $gold  = imagecolorallocate($card, 233, 200, 120);
        $dkbox = imagecolorallocate($card, 24, 24, 26);
        $bord  = imagecolorallocate($card, 236, 231, 223);

        $this->fillVerticalGradient($card, $W, $H, 255, 253, 250, 244, 237, 226); // 暖白

        // 品牌标（左上）
        $this->drawBrandMark($card, $pad, 40, $font, '哪吒 · 本地生活', false, $red, $white, $ink);

        // logo（左，160×160）
        $logoBox = 160; $lx = $pad; $ly = 190;
        $loaded = false;
        if ($logoPath) {
            $limg = @imagecreatefromstring(Storage::disk($disk)->get($logoPath));
            if ($limg) {
                $sw = imagesx($limg); $sh = imagesy($limg);
                $scale = max($logoBox / $sw, $logoBox / $sh);
                $srcW = (int) round($logoBox / $scale); $srcH = (int) round($logoBox / $scale);
                $srcX = (int) (($sw - $srcW) / 2); $srcY = (int) (($sh - $srcH) / 2);
                imagecopyresampled($card, $limg, $lx, $ly, $srcX, $srcY, $logoBox, $logoBox, $srcW, $srcH);
                imagedestroy($limg);
                $loaded = true;
            }
        }
        if (!$loaded) {
            // 无 logo 兜底：深底 + 金字首二字
            imagefilledrectangle($card, $lx, $ly, $lx + $logoBox, $ly + $logoBox, $dkbox);
            $ini = mb_substr(preg_replace('/\s+/u', '', (string) $name), 0, 2);
            $ib = imagettfbbox(40, 0, $font, $ini);
            $iw = $ib[2] - $ib[0];
            imagettftext($card, 40, 0, (int) ($lx + ($logoBox - $iw) / 2), (int) ($ly + $logoBox / 2 + 18), $gold, $font, $ini);
        }
        // logo 细边
        imagerectangle($card, $lx, $ly, $lx + $logoBox, $ly + $logoBox, $bord);

        // 文本列
        $tx = $lx + $logoBox + 34;
        // 店名（单行省略）
        $nameLines = $this->wrapText($font, 50, $name, $W - $tx - $pad, 1);
        imagettftext($card, 50, 0, $tx, $ly + 52, $ink, $font, $nameLines[0]);
        // 类目·区域
        if ($sub !== '') {
            imagettftext($card, 28, 0, $tx, $ly + 104, $gray, $font, $sub);
        }
        // 诚实评分行
        if ($ratingVal !== null) {
            $ry = $ly + 156;
            if ($ratingSrc === 'google') {
                imagettftext($card, 30, 0, $tx, $ry, $gblue, $font, 'Google ' . $ratingVal);
            } else {
                $sr = 16;
                $this->drawStar($card, $tx + $sr, $ry - $sr + 2, $sr, $amber);
                imagettftext($card, 30, 0, $tx + $sr * 2 + 12, $ry, $ink, $font, $ratingVal);
                $vb = imagettfbbox(30, 0, $font, $ratingVal);
                $vw = $vb[2] - $vb[0];
                imagettftext($card, 22, 0, $tx + $sr * 2 + 12 + $vw + 16, $ry, $gray, $font, '评分由商家提供');
            }
        }

        // 底行品牌脚注
        imagettftext($card, 26, 0, $pad, $H - 40, $gray, $font, '埃里温华人生活平台 · nezha.am');

        return $this->outputCard($disk, $card, $cacheRel);
    }

    // 编码 + 缓存 + 响应（品牌卡共用）
    private function outputCard($disk, $card, $cacheRel)
    {
        ob_start();
        imagejpeg($card, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($card);
        Storage::disk($disk)->put($cacheRel, $bytes);
        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400', 'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function cachedJpg($disk, $cacheRel)
    {
        return response(Storage::disk($disk)->get($cacheRel), 200, [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400', 'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function brandFallback()
    {
        return redirect()->away('https://nezha.am/static/icon-512.png');
    }
}
