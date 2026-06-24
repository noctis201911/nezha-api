<?php

namespace App\CentralLogics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒「反馈日报」生成器 (方案A)。
 *
 * 把一个时间窗口内的顾客反馈(评价/差评原文 + 退款原因 + 客服高频问题/工单)聚合,
 * 复用 AI 客服同一套 DeepSeek 配置(nezha_cs_ai_*) + 同样的 PII 脱敏, 生成一段
 * 「摘要 + 最该改进的点」, 供运营每日快速掌握。
 *
 * 设计原则:
 *  - 数字全部来自 DB(确定性), AI 只写文字; 数字与文字分离, 数字可独立对账。
 *  - 喂模型前一律 redactPii(), 与 NezhaCsAssistant 同口径; 只把脱敏+截断后的少量样本发出去。
 *  - 不存原始评价/聊天到本表(它们在原表, 按既有 purge 规则到期删); 本表只存 AI 产出的摘要。
 *  - AI 未配置/总开关关 → 降级: 只产出统计数字, summary 标注未启用, 绝不报错中断。
 *  - 完全独立于 NezhaCsAssistant, 不改动核心客服文件。
 */
class NezhaFeedbackDigest
{
    /** 生成一个窗口 [since, until) 的反馈摘要。返回数组, 不写库不发送(交给调用方)。 */
    public static function generate(Carbon $since, Carbon $until): array
    {
        $counts = [];
        $samples = [];   // 喂给模型的脱敏样本(分段)

        // —— 1) 评价 / 差评 ——
        if (Schema::hasTable('reviews')) {
            $reviewsQ = DB::table('reviews')->where('created_at', '>=', $since)->where('created_at', '<', $until);
            $counts['reviews_total'] = (int) (clone $reviewsQ)->count();
            $counts['reviews_bad'] = (int) (clone $reviewsQ)->whereBetween('rating', [1, 3])->count();
            $avg = (clone $reviewsQ)->where('rating', '>', 0)->avg('rating');
            $counts['reviews_avg'] = $avg ? round((float) $avg, 2) : null;
            $bad = (clone $reviewsQ)->whereBetween('rating', [1, 3])
                ->whereNotNull('comment')->where('comment', '!=', '')
                ->orderByDesc('id')->limit(25)->pluck('comment')->toArray();
            $bad = self::clean($bad);
            if ($bad) {
                $samples[] = "差评原文(评分<=3, 最多25条):\n- " . implode("\n- ", $bad);
            }
        }

        // —— 2) 退款原因 ——
        if (Schema::hasTable('nezha_refund_records')) {
            $refQ = DB::table('nezha_refund_records')->where('created_at', '>=', $since)->where('created_at', '<', $until);
            $counts['refunds_total'] = (int) (clone $refQ)->count();
            $byCat = (clone $refQ)->selectRaw('reason_category, count(*) c')->groupBy('reason_category')->pluck('c', 'reason_category')->toArray();
            $counts['refunds_by_category'] = $byCat;
            $notes = (clone $refQ)->whereNotNull('reason_note')->where('reason_note', '!=', '')
                ->orderByDesc('id')->limit(25)->pluck('reason_note')->toArray();
            $notes = self::clean($notes);
            if ($notes) {
                $samples[] = "退款原因说明(最多25条):\n- " . implode("\n- ", $notes);
            }
        }

        // —— 3) 客服: 分类计数 / 工单 / 好差评 / 高频问题 ——
        if (Schema::hasTable('nezha_cs_logs')) {
            $cats = DB::table('nezha_cs_logs')->where('created_at', '>=', $since)->where('created_at', '<', $until)
                ->selectRaw('category, count(*) c')->groupBy('category')->pluck('c', 'category')->toArray();
            $counts['cs_by_category'] = $cats;
        }
        if (Schema::hasTable('nezha_cs_tickets')) {
            $counts['cs_open_tickets'] = (int) DB::table('nezha_cs_tickets')->where('status', 'open')->count();
        }
        if (Schema::hasTable('nezha_cs_feedback')) {
            $counts['cs_fb_pos'] = (int) DB::table('nezha_cs_feedback')->where('sentiment', 'positive')->where('created_at', '>=', $since)->where('created_at', '<', $until)->count();
            $counts['cs_fb_neg'] = (int) DB::table('nezha_cs_feedback')->where('sentiment', 'negative')->where('created_at', '>=', $since)->where('created_at', '<', $until)->count();
        }
        // 顾客发给客服(admin)的消息 → 归纳高频问题
        if (Schema::hasTable('conversations') && Schema::hasTable('messages')) {
            $adminConvIds = DB::table('conversations')->where('receiver_type', 'admin')->pluck('id')->toArray();
            if ($adminConvIds) {
                $msgs = DB::table('messages')->whereIn('conversation_id', $adminConvIds)
                    ->where('created_at', '>=', $since)->where('created_at', '<', $until)
                    ->whereNotNull('message')->where('message', '!=', '')
                    ->orderByDesc('id')->limit(60)->pluck('message')->toArray();
                $msgs = self::clean($msgs);
                if ($msgs) {
                    $samples[] = "顾客发给客服的消息(最多60条, 供归纳高频/未解决问题):\n- " . implode("\n- ", $msgs);
                }
            }
        }

        // —— 4) (方案C预留) 搜索无结果 / 加购未下单 ——
        if (Schema::hasTable('nezha_search_misses')) {
            $miss = DB::table('nezha_search_misses')->where('last_seen_at', '>=', $since)->where('last_seen_at', '<', $until)
                ->orderByDesc('hit_count')->limit(20)->pluck('hit_count', 'keyword')->toArray();
            if ($miss) {
                $counts['search_miss_top'] = $miss;
                $lines = [];
                foreach ($miss as $kw => $n) { $lines[] = $kw . ': ' . $n; }
                $samples[] = "热门「搜了没结果」的词(词:次数):\n- " . implode("\n- ", $lines);
            }
        }
        if (Schema::hasTable('nezha_cart_events')) {
            $counts['cart_abandoned'] = (int) DB::table('nezha_cart_events')
                ->where('created_at', '>=', $since)->where('created_at', '<', $until)->where('converted', 0)->count();
        }

        // —— 5) 调 AI 生成摘要(降级安全) ——
        $status = (int) Helpers::get_business_settings('nezha_cs_ai_status');
        $key = Helpers::get_business_settings('nezha_cs_ai_api_key');
        if ($status !== 1 || !$key || !$samples) {
            return [
                'counts' => $counts,
                'summary' => $samples ? '(AI 未启用, 仅统计数字)' : '(本时段无文字反馈样本, 仅统计数字)',
                'top_issues' => [],
                'model' => null,
                'tokens' => 0,
                'degraded' => true,
            ];
        }

        [$summary, $model, $tokens, $err] = self::callModel($counts, $samples);
        if ($err) {
            Log::warning('NEZHA_FEEDBACK_DIGEST: AI 调用失败 ' . $err);
            return [
                'counts' => $counts,
                'summary' => '(AI 调用失败: ' . $err . ', 仅统计数字)',
                'top_issues' => [],
                'model' => $model,
                'tokens' => 0,
                'degraded' => true,
            ];
        }

        return [
            'counts' => $counts,
            'summary' => $summary,
            'top_issues' => [],
            'model' => $model,
            'tokens' => $tokens,
            'degraded' => false,
        ];
    }

    /** 脱敏 + 截断 + 去空, 与 NezhaCsAssistant::redactPii 同口径。 */
    private static function clean(array $arr): array
    {
        $out = [];
        foreach ($arr as $x) {
            $s = trim((string) $x);
            if ($s === '') { continue; }
            $out[] = self::redactPii(mb_substr($s, 0, 120));
        }
        return array_values($out);
    }

    private static function redactPii(string $s): string
    {
        $s = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[邮箱]', $s);
        $s = preg_replace('/\bT[1-9A-HJ-NP-Za-km-z]{33}\b/', '[钱包地址]', $s);
        $s = preg_replace('/\b0x[a-fA-F0-9]{40}\b/', '[钱包地址]', $s);
        $s = preg_replace('/\+?\d[\d().\-]{6,}\d/u', '[电话]', $s);
        return $s;
    }

    /** @return array{0:string,1:?string,2:int,3:?string} [summary, model, tokens, error] */
    private static function callModel(array $counts, array $samples): array
    {
        $base = rtrim((string) (Helpers::get_business_settings('nezha_cs_ai_base_url') ?: 'https://api.deepseek.com'), '/');
        $model = Helpers::get_business_settings('nezha_cs_ai_model') ?: 'deepseek-chat';
        $key = Helpers::get_business_settings('nezha_cs_ai_api_key');

        $ctx = "【本时段统计】\n" . json_encode($counts, JSON_UNESCAPED_UNICODE) . "\n\n【文字反馈样本(已脱敏)】\n" . implode("\n\n", $samples);
        $system = "你是「哪吒外卖」平台的运营分析助手, 面向平台超级管理员。基于下面给定时段的真实顾客反馈数据, 输出一份简洁的「反馈日报」, 包含:\n"
            . "1) 一句话总体情况(评价/退款/客服是否正常, 有无异常波动);\n"
            . "2) 顾客集中反映的问题(按出现频次, 最多5条, 每条附大致条数或佐证);\n"
            . "3) 「最该改进的 3 件事」(具体、可执行, 面向产品/运营, 不要空话)。\n"
            . "只依据给定数据, 数据不足就如实说「样本少, 仅供参考」。用中文, 控制在 400 字内, 不要逐条复述原文, 不要编造数字。";

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $ctx],
            ],
            'temperature' => 0.4,
            'max_tokens' => 900,
            'stream' => false,
        ];
        $ch = curl_init($base . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code >= 400) {
            return ['', $model, 0, '接口错误 ' . $code . ($cerr ? " $cerr" : '')];
        }
        $json = json_decode($raw, true);
        $content = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
        $tokens = (int) ($json['usage']['total_tokens'] ?? 0);
        if ($content === '') {
            return ['', $model, 0, '空回复'];
        }
        return [$content, $model, $tokens, null];
    }
}
