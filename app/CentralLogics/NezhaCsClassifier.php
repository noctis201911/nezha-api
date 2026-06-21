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

    // 翻译诉求：中文顾客↔本地骑手沟通。最高优先（招牌功能），不被敏感/转商家抢走。
    protected static array $translate = [
        '翻译', '翻成', '翻一下', '帮我翻', '帮翻', '这句话什么意思', '这是什么意思', '什么意思啊', '啥意思',
        '骑手说', '司机说', '配送员说', '送货的说', '送餐员说', '骑手发来', '骑手发的',
        '怎么跟骑手说', '帮我跟骑手说', '帮我回复骑手', '帮我告诉骑手', '转告骑手', '跟骑手说', '给骑手说', '回复骑手',
        '用亚美尼亚语', '用俄语', '用英语', '亚美尼亚语怎么', '俄语怎么', '英语怎么', '当地语言怎么', 'translate',
    ];

    // 进入翻译模式（之后每条都互译，直到退出）。
    protected static array $enterXlate = [
        '需要翻译', '要翻译', '进入翻译', '翻译模式', '开启翻译', '和骑手对话', '跟骑手对话', '跟骑手聊',
        '和骑手沟通', '帮我和骑手', '帮我跟骑手沟通', '和配送员沟通',
    ];
    protected static array $exitXlate = [
        '退出翻译', '不用翻译', '不翻译了', '结束翻译', '关闭翻译', '退出翻译模式', '不用翻了', '翻译结束',
    ];

    public static function isEnterTranslateMode(?string $text): bool
    {
        if (!$text) {
            return false;
        }
        $t = mb_strtolower($text);
        foreach (self::$enterXlate as $kw) {
            if (mb_stripos($t, mb_strtolower($kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function isExitTranslateMode(?string $text): bool
    {
        if (!$text) {
            return false;
        }
        $t = mb_strtolower($text);
        foreach (self::$exitXlate as $kw) {
            if (mb_stripos($t, mb_strtolower($kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function isTranslationRequest(?string $text): bool
    {
        if (!$text) {
            return false;
        }
        // 含亚美尼亚字母 / 西里尔(俄语)字母 → 几乎必是中文顾客粘贴骑手的外语消息求翻译。
        if (preg_match('/[\x{0530}-\x{058F}\x{0400}-\x{04FF}]/u', $text)) {
            return true;
        }
        $t = mb_strtolower($text);
        foreach (self::$translate as $kw) {
            if (mb_stripos($t, mb_strtolower($kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    // 顾客对「客服服务」的评价。先判负面(防 不满意 被 满意 误命中)。限服务语境，避免抓订单投诉。
    public static function feedbackSentiment(?string $text): ?string
    {
        if (!$text) {
            return null;
        }
        $t = mb_strtolower($text);
        $neg = [
            '👎', '不满意', '没帮到', '没帮上', '没解决', '答非所问', '客服没用', '客服太差', '客服差',
            '客服态度', '你们客服', '小哪没用', '小哪太差', '服务太差', '服务差', '很失望', '太失望',
            '一点用都没有', '一点用没有', '没什么用', '太差劲', '垃圾客服',
        ];
        foreach ($neg as $w) {
            if (mb_stripos($t, $w) !== false) {
                return 'negative';
            }
        }
        $pos = [
            '👍', '好评', '满意', '谢谢小哪', '小哪谢谢', '客服很好', '客服不错', '很有用', '太有用',
            '帮大忙', '解决了', '点赞', '服务很好', '服务真好', '服务态度好', '服务不错', '你真好', '客服真好', '太棒了', '很棒',
        ];
        foreach ($pos as $w) {
            if (mb_stripos($t, $w) !== false) {
                return 'positive';
            }
        }
        return null;
    }

    // 顾客表示「联系不上商家」→ 走升级处理（给电话+催商家邮件+工单），先于普通敏感判断。
    protected static array $cantReach = [
        '联系不上', '联系不到', '联系不了', '找不到商家', '商家不回', '商家没回', '商家不理',
        '商家不接', '没人回我', '没人理我', '打不通', '打不进', '商家失联', '无法联系', '不回我消息',
        "can't reach", 'cannot reach', 'no response from', 'merchant not responding', 'no reply from',
    ];

    public static function isCantReachMerchant(?string $text): bool
    {
        if (!$text) {
            return false;
        }
        $t = mb_strtolower($text);
        foreach (self::$cantReach as $kw) {
            if (mb_stripos($t, mb_strtolower($kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    // 粗判是否中文（含 CJK）。用于确定性兜底话术的中/英选择。
    public static function isChinese(?string $text): bool
    {
        return $text ? (bool) preg_match('/\p{Han}/u', $text) : true;
    }

    // 粗判外语种类(用于翻译模式记住骑手用的语言)：hy=亚美尼亚 ru=俄/西里尔 en=拉丁字母; 中文/无法判=null。
    public static function dominantForeignLang(?string $text): ?string
    {
        if (!$text) {
            return null;
        }
        if (preg_match('/[\x{0530}-\x{058F}]/u', $text)) {
            return 'hy';
        }
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $text)) {
            return 'ru';
        }
        if (preg_match('/\p{Han}/u', $text)) {
            return null; // 中文，不是骑手外语
        }
        if (preg_match('/[A-Za-z]/', $text)) {
            return 'en';
        }
        return null;
    }

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
