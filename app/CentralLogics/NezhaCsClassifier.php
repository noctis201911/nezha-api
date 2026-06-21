<?php

namespace App\CentralLogics;

/**
 * 哪吒 AI 客服 — 分类闸 + 输出护栏。
 * 纯确定性规则，先于模型运行，不可被话术越狱。
 */
class NezhaCsClassifier
{
    // 敏感词：命中即转人工，绝不喂 AI 自动答（L1：退款/钱/纠纷一律由人处理）。
    protected static array $sensitive = [
        // —— 中文：钱 / 退款 / 纠纷 / 投诉 ——
        '退款', '退钱', '退货', '返钱', '返款', '返还', '退还', '赔', '补偿', '理赔',
        '投诉', '差评', '举报', '报警', '律师', '起诉', '曝光', '维权', '纠纷', '骗', '欺诈', '诈骗', '假的',
        '没收到', '没送到', '少了', '漏了', '发错', '送错', '做错', '少给', '少送',
        '多扣', '乱扣', '扣错', '重复扣', '扣款', '扣多', '没到账', '钱没退', '钱不对', '金额不对',
        '余额', '提现', '钱包', '支付失败', '付款失败', '重复支付', '多付', '付了钱', '付款了', '已付款',
        '人工客服', '转人工', '客服电话', '打电话', '电话联系',
        // —— 英文 ——
        'refund', 'money back', 'chargeback', 'complaint', 'scam', 'fraud', 'lawyer', 'sue', 'police',
        'wrong order', 'missing', "didn't receive", 'did not receive', 'not received',
        'overcharge', 'over charged', 'double charge', 'charged twice', 'compensation', 'human agent',
    ];

    public static function isSensitive(?string $text): bool
    {
        if (!$text) {
            return false;
        }
        $t = mb_strtolower($text);
        foreach (self::$sensitive as $kw) {
            if (mb_stripos($t, mb_strtolower($kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    // 输出含疑似密钥 / 密码 → 整条拦截（不发给顾客，改走转人工）。
    public static function leaksSecret(?string $text): bool
    {
        if (!$text) {
            return false;
        }
        if (preg_match('/sk-[a-zA-Z0-9]{16,}/', $text)) {
            return true;
        }
        if (preg_match('/(密码|口令|password|passwd|secret|api[_\s-]?key)\s*[:：=]\s*\S+/iu', $text)) {
            return true;
        }
        return false;
    }

    // 输出自曝 AI 身份 → 触发改写成安全话术（不再露馅）。
    public static function revealsAi(?string $text): bool
    {
        if (!$text) {
            return false;
        }
        $t = mb_strtolower($text);
        $aiWords = [
            '人工智能', '大模型', '语言模型', 'ai助手', 'ai 助手', '智能助手', '机器人', '聊天机器人', '程序',
            'deepseek', 'openai', 'chatgpt', 'gpt', '我是一个ai', '我是ai', '作为ai', '作为一个ai', '我是人工智能',
            'i am an ai', "i'm an ai", 'language model', 'as an ai', 'artificial intelligence', 'chatbot', 'a bot',
        ];
        foreach ($aiWords as $w) {
            if (mb_stripos($t, $w) !== false) {
                return true;
            }
        }
        return false;
    }
}
