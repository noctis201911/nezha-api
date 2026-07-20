<?php

namespace App\CentralLogics;

/**
 * 哪吒 公开联系方式规范化（本地生活商家 + 外卖挂牌店 共用单一实现）。
 *
 * 出处：原为 LocalLifeMerchant::normalizedContacts() 内联实现（2026-07-08 起线上）。
 * 2026-07-20 外卖 TG 化 Phase1 需要同一套 deep-link 规则，故逐字抽出为共享 helper——
 * 🔴 抽出时行为零变更；两处消费者共用此实现，避免 URL 规则在前后端/两业务线之间分叉
 *    （业主 0720 拍板：宁可动一次后端，也不要前端另写一套解析）。
 *
 * 输入：原始 JSON 数组 [{method, value, label}]（本地生活 local_life_merchants.contacts
 *       / 外卖 restaurants.nezha_contacts，两者同构）。
 * 输出：规范化数组 [{method, value, label, href, copy}]
 *   - method: wechat|phone|whatsapp|telegram（白名单外整条丢弃）
 *   - href:   tel: / https://wa.me/<digits> / https://t.me/<user>；微信无 href（走复制+二维码）
 *   - copy:   微信=号码文本供前端复制；其它为 null
 * 空/脏输入返回 []，前端据此降级（不渲染联系入口）。
 * L1-1：仅展示，不含任何支付/下单。
 */
class NezhaContacts
{
    /** 合法渠道白名单（前端 consumer、埋点 channel 白名单三处必须同值） */
    public const METHODS = ['wechat', 'phone', 'whatsapp', 'telegram'];

    public static function normalize($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($raw as $c) {
            if (!is_array($c)) {
                continue;
            }
            $method = strtolower(trim((string) ($c['method'] ?? '')));
            $value  = trim((string) ($c['value'] ?? ''));
            if ($value === '' || !in_array($method, self::METHODS, true)) {
                continue;
            }
            $label = trim((string) ($c['label'] ?? ''));
            $href  = null;
            $copy  = null;
            switch ($method) {
                case 'phone':
                    // tel: 保留原始拨号串（可含 +），仅去空格
                    $href = 'tel:' . preg_replace('/\s+/', '', $value);
                    break;
                case 'whatsapp':
                    // wa.me 需纯数字（去 +、空格、连字符、括号）
                    $digits = preg_replace('/\D+/', '', $value);
                    $href = $digits !== '' ? 'https://wa.me/' . $digits : null;
                    break;
                case 'telegram':
                    // t.me/<用户名>，容忍前导 @ 或整段链接
                    $user = $value;
                    if (preg_match('~t\.me/([^/?\s]+)~i', $value, $mm)) {
                        $user = $mm[1];
                    }
                    $user = ltrim($user, '@');
                    $href = $user !== '' ? 'https://t.me/' . $user : null;
                    break;
                case 'wechat':
                    // 微信=复制号 + 二维码（前端弹），无 href
                    $copy = $value;
                    break;
            }
            $out[] = [
                'method' => $method,
                'value'  => $value,
                'label'  => $label ?: null,
                'href'   => $href,
                'copy'   => $copy,
            ];
        }
        return $out;
    }
}
