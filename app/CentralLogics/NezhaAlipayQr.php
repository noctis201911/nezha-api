<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\Log;

// 哪吒[B·打开支付宝直跳]: 解码商家上传的支付宝收款码图 → 提取 qr.alipay.com 收款链接。
// 前端「打开支付宝」深链此 URL 直跳收款页(通用链接); 解不出 / 非支付宝链接 → null → 前端回落「扫一扫」。
// 只接受 qr.alipay.com 主机, 防把无关二维码内容当收款链接深链(资金语境, 宁缺勿错)。
class NezhaAlipayQr
{
    public static function decodeUrl(?string $imageFilename): ?string
    {
        if (!$imageFilename) {
            return null;
        }
        try {
            $path = storage_path('app/public/restaurant/payment_qr/' . $imageFilename);
            if (!is_file($path)) {
                return null;
            }
            if (!class_exists('Zxing\\QrReader')) {
                return null;
            }
            $reader = new \Zxing\QrReader($path);
            $text = trim((string) $reader->text());
            if ($text === '') {
                return null;
            }
            if (preg_match('#^https?://qr\.alipay\.com/#i', $text)) {
                return $text;
            }
            return null;
        } catch (\Throwable $e) {
            Log::warning('nezha decode alipay qr failed: ' . $e->getMessage());
            return null;
        }
    }
}
