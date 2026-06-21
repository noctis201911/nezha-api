<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\DB;

/**
 * 哪吒内容合规筛查（违禁/硬禁业务词）。
 *
 * 单一词库来源，供「本地生活 UGC 发帖」与「本地生活商家录入」共用，避免两套词库漂移。
 * 运行时词库优先取后台 business_settings.locallife_banned_words（换行/逗号分隔），
 * 未配置时回退本类 DEFAULT_BANNED。子串匹配、不区分大小写（mb_stripos）。
 *
 * ⚠️ 这是「命中即拒」的硬过滤，只放最不该出现在哪吒上的业务/内容关键词；
 *   需牌照但可信息展示的类目（如正规移民/签证/按摩/美发）走类目 compliance_level=1「人工审」，不进这里。
 *   词条尽量用具体多字短语，避免误伤正常餐饮/租房/二手帖（如不收「代购」「兑换券」「USDT」单字）。
 */
class NezhaContentScreen
{
    /**
     * 默认违禁/硬禁词库（后台 locallife_banned_words 未配置时兜底）。
     * 分组仅为可读性，运行时拍平为一维子串匹配。
     */
    public const DEFAULT_BANNED = [
        // —— 涉黄 / 性交易 ——
        '约炮', '卖淫', '嫖娼', '一夜情', '援交', '特殊服务', '上门保健', 'escort', 'sex service',
        '大保健', '莞式', '楼凤', '一条龙服务', '性服务', '裸聊', '裸体按摩',
        // —— 赌博 ——
        '赌博', '博彩', '网赌', '百家乐', '时时彩', '菠菜平台', 'casino', 'betting',
        // —— 诈骗 / 灰产 / 伪造 ——
        '刷单', '兼职刷信誉', '跑分', '洗钱', '代收款', '黑卡', '四件套', '贷款无抵押', '办证', '代开发票', 'fake document',
        // —— 毒品 / 违禁品 ——
        '大麻', '冰毒', '代孕', '枪支', '仿真枪', '迷药',
        // —— 外站强引流 ——
        '加微信群', '引流到Telegram', '私域导流',
        // —— 签证 / 移民诈骗（正规移民签证走类目人工审，这里只拦「包过/假材料」） ——
        '包过签', '保证过签', '100%过签', '百分百过签', '拒签全退', '拒签退全款', '包入籍', '包拿绿卡', '包拿身份',
        '黑户洗白', '假学历证', '假资产证明', '假银行流水',
        // —— 换汇 / 资金代收代付（无牌支付结算·外汇违规·撞 L1-1/L1-3） ——
        '换汇', '货币兑换', '外币兑换', '人民币兑换', '现金兑换', '美元兑换', '卢布兑换', '地下钱庄',
        '代收代付', '代付转账', '资金代付',
        // —— 加密货币买卖服务（无牌经营·洗钱风险；与平台自身用 USDT 收餐费无关） ——
        '买卖usdt', 'usdt承兑', 'usdt代收', '收u出u', '代买usdt', '代卖usdt', '承兑usdt',
        '矿机出售', '交易所代理', '加密货币交易', '虚拟币交易', '炒币代操作',
        // —— 医美注射 / 药品（需医疗·药品牌照，平台无法核验） ——
        '医美注射', '肉毒', '瘦脸针', '玻尿酸', '水光针', '线雕', '埋线', '处方药', '管制药', '减肥药',
        // —— 制裁规避（亚美尼亚特有：帮俄转口/采购/转账） ——
        '帮俄罗斯采购', '代俄采购', '转口俄罗斯', '规避制裁', '制裁规避', '绕过制裁',
        // —— 高息放贷 / 套现 ——
        '高利贷', '放高利贷', '无抵押放款', '套现',
    ];

    /**
     * 当前生效词库：后台 locallife_banned_words 优先，未配置回退 DEFAULT_BANNED。
     *
     * @return string[]
     */
    public static function words(): array
    {
        $raw = DB::table('business_settings')->where('key', 'locallife_banned_words')->value('value');
        if ($raw === null || trim($raw) === '') {
            return self::DEFAULT_BANNED;
        }
        $parts = preg_split('/[\r\n,，]+/u', $raw);
        $words = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $words[] = $p;
            }
        }
        return $words ?: self::DEFAULT_BANNED;
    }

    /**
     * 文本是否命中违禁词（子串、不区分大小写）。不回显命中词，避免被试探绕过。
     */
    public static function hits(?string $text): bool
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }
        foreach (self::words() as $w) {
            if ($w !== '' && mb_stripos($text, $w) !== false) {
                return true;
            }
        }
        return false;
    }
}
