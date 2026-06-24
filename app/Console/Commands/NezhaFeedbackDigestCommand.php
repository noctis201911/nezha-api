<?php

namespace App\Console\Commands;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaFeedbackDigest;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 反馈日报 (方案A) — 每日把顾客反馈聚合 → AI 摘要 → 存库 + 发超管 Telegram。
 *
 * 开关: business_settings.nezha_feedback_digest_status (默认 0 关; 关时跳过, 但 --dry-run 可强制预览)。
 * AI:   复用 nezha_cs_ai_*; AI 未开/无 key → 降级为仅统计数字, 不报错。
 * 用法: php artisan nezha:feedback-digest [--dry-run] [--days=1]
 */
class NezhaFeedbackDigestCommand extends Command
{
    protected $signature = 'nezha:feedback-digest {--dry-run : 只预览, 不写库不发TG} {--days=1 : 统计窗口天数(默认昨天1天)}';
    protected $description = '生成顾客反馈日报(评价/退款/客服聚合 → AI 摘要 → 存库+超管Telegram)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $status = (int) Helpers::get_business_settings('nezha_feedback_digest_status');

        if (!$dry && $status !== 1) {
            $this->info('开关 nezha_feedback_digest_status 关闭, 跳过。(--dry-run 可强制预览)');
            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $until = Carbon::today();                 // 今天 00:00
        $since = $until->copy()->subDays($days);  // days=1 → 昨天 00:00 ~ 今天 00:00 = 整个昨天
        $digestDate = $since->copy()->toDateString();

        $this->info("反馈日报 窗口: {$since->toDateTimeString()} ~ {$until->toDateTimeString()} ({$days}天)" . ($dry ? ' [DRY-RUN]' : ''));

        $r = NezhaFeedbackDigest::generate($since, $until);

        $this->line('---- 统计 ----');
        $this->line(json_encode($r['counts'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('---- 摘要 ----');
        $this->line($r['summary']);
        $this->line('---- model=' . ($r['model'] ?? '-') . ' tokens=' . $r['tokens'] . ' degraded=' . ($r['degraded'] ? 'yes' : 'no') . ' ----');

        if ($dry) {
            $this->info('[DRY-RUN] 未写库、未发送 Telegram。');
            return self::SUCCESS;
        }

        // 写库(只存 AI 产出的摘要 + 确定性统计, 不存原始评价/聊天)
        $id = DB::table('nezha_feedback_digests')->insertGetId([
            'digest_date' => $digestDate,
            'window_days' => $days,
            'summary' => $r['summary'],
            'top_issues' => json_encode($r['top_issues'], JSON_UNESCAPED_UNICODE),
            'counts' => json_encode($r['counts'], JSON_UNESCAPED_UNICODE),
            'model' => $r['model'],
            'tokens' => $r['tokens'],
            'degraded' => $r['degraded'] ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 发 Telegram(失败不影响主流程, Helpers 内部已 try-catch)
        $c = $r['counts'];
        $tg = "🧾 哪吒反馈日报 {$digestDate}\n"
            . '评价 ' . ($c['reviews_total'] ?? 0) . ' (差评 ' . ($c['reviews_bad'] ?? 0) . ', 均分 ' . ($c['reviews_avg'] ?? '-') . ')'
            . ' | 退款 ' . ($c['refunds_total'] ?? 0)
            . ' | 客服好/差评 ' . ($c['cs_fb_pos'] ?? 0) . '/' . ($c['cs_fb_neg'] ?? 0)
            . ' | 待跟进工单 ' . ($c['cs_open_tickets'] ?? 0) . "\n\n"
            . $r['summary'] . "\n\n(后台「AI客服」页可看历史日报)";
        Helpers::sendTelegramToAdmin($tg);

        Log::info("NEZHA_FEEDBACK_DIGEST: 已生成 #{$id} date={$digestDate} degraded=" . ($r['degraded'] ? '1' : '0'));
        $this->info("已写入 nezha_feedback_digests #{$id} 并发送 Telegram。");
        return self::SUCCESS;
    }
}
