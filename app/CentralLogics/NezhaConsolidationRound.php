<?php

namespace App\CentralLogics;

use App\Models\BusinessSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 平台集运申报(阶段 B · 期次撮合)共享逻辑收口。
 * 总闸门 + 期次号生成 + 成团进度(同单位口径·绝不跨单位混加) + 品类字典(食品分级) + 逐字费用文案。
 * 平台只组织撮合、公示货代报价, 付款商家直付货代, 平台不碰钱。与 L1 红线无关。
 */
class NezhaConsolidationRound
{
    /** 期次撮合总闸(business_settings)。默认 0 dormant。登记于 config/nezha_switches.php + docs/PRELAUNCH_SWITCHES.md。 */
    public const SWITCH_KEY = 'nezha_consolidation_rounds_status';

    public const UNIT_LABELS = ['m3' => 'm³', 'kg' => 'kg', 'box' => '箱'];
    public const STATUS_LABELS = ['draft' => '草稿', 'open' => '报名中', 'closed' => '已截止', 'canceled' => '已取消'];

    /** 品类字典(沿用 v1 问卷品类)+ 食品分级(R2 裁决: 首柜非食品先行)。 */
    public const CATEGORIES = [
        ['label' => '干货 / 常温食材', 'is_food' => true],
        ['label' => '厨房用具 / 设备', 'is_food' => false],
        ['label' => '包装 / 一次性用品', 'is_food' => false],
        ['label' => '超市百货 / 日用', 'is_food' => false],
    ];

    /** 食品类清关提示(报名/详情界面食品品类旁显示)。 */
    public const FOOD_HINT = '食品类清关门槛较高，成行前平台将与货代逐项确认';

    /** 费用声明逐字文案(对外红线: 不做置身事外标榜, 也不下未来收费的反向承诺)。 */
    public const FEE_NOTE = '本期集运费用请与货代直接结算，联系方式见上。';

    /** 总闸。关=vendor 端期次/报名整体零透出; admin 端始终可用(运营先建期次备货)。 */
    public static function enabled(): bool
    {
        return (bool) (BusinessSetting::where('key', self::SWITCH_KEY)->first()?->value ?? 0);
    }

    /** 生成期次号 YYYYMM-N(当月递增序号)。 */
    public static function generateRoundNo(): string
    {
        $prefix = Carbon::now()->format('Ym');
        $max = 0;
        if (Schema::hasTable('nezha_consolidation_rounds')) {
            foreach (DB::table('nezha_consolidation_rounds')->where('round_no', 'like', $prefix . '-%')->pluck('round_no') as $no) {
                $n = (int) substr((string) $no, strlen($prefix) + 1);
                if ($n > $max) {
                    $max = $n;
                }
            }
        }
        return $prefix . '-' . ($max + 1);
    }

    /** 该品类是否食品类(按字典 is_food)。 */
    public static function isFoodCategory(string $label): bool
    {
        foreach (self::CATEGORIES as $c) {
            if ($c['label'] === $label) {
                return (bool) $c['is_food'];
            }
        }
        return false;
    }

    /**
     * 成团进度: 只统计与 round.min_volume_unit 同单位的报名量; 其它单位计入 other_count, 绝不跨单位换算相加。
     * 返回 ['unit','unit_label','sum','min','pct','other_count','enroll_count']。
     */
    public static function progress($round): array
    {
        $unit = $round->min_volume_unit ?? 'm3';
        $sum = 0.0;
        $other = 0;
        $cnt = 0;
        if (!empty($round->id) && Schema::hasTable('nezha_consolidation_enrollments')) {
            $rows = DB::table('nezha_consolidation_enrollments')
                ->where('round_id', $round->id)->where('status', 'enrolled')->get();
            $cnt = $rows->count();
            foreach ($rows as $e) {
                if ($e->est_volume_unit === $unit && $e->est_volume_value !== null) {
                    $sum += (float) $e->est_volume_value;
                } elseif ($e->est_volume_unit !== null && $e->est_volume_unit !== $unit) {
                    $other++;
                }
            }
        }
        $min = (float) ($round->min_volume_value ?? 0);
        $pct = $min > 0 ? min(100, (int) round($sum / $min * 100)) : 0;
        return [
            'unit'         => $unit,
            'unit_label'   => self::UNIT_LABELS[$unit] ?? $unit,
            'sum'          => $sum,
            'min'          => $min,
            'pct'          => $pct,
            'other_count'  => $other,
            'enroll_count' => $cnt,
        ];
    }
}
