<?php

namespace App\Console\Commands;

use App\Models\LocalLifePost;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 - 本地生活 UGC 帖「到期自动失效」sweep（HANDOFF_locallife_batch1 §C.9）。
 *
 * 到期(expires_at < now)且仍「在售」的用户帖 → listing_status=expired(已失效)。
 * 只处理 source=user 的 UGC 帖；平台(admin)帖 expires_at=null，永不命中，保持长期展示。
 * 幂等：只挑 listing_status=active 的，重复跑不会二次改写。
 *
 * PII 到期清(contact_info/图片)由既有 nezha:purge-locallife-pii 负责(同样 keyed 到 expires_at)，
 * 本命令只翻生命周期态、不碰 PII；两者同族，调度里本命令排在 PII 清之前(03:35 < 03:40)。
 *
 * ⚠️ 调度注册在 bootstrap/app.php withSchedule()（Laravel 12），不是 app/Console/Kernel.php。
 */
class ExpireLocalLifePosts extends Command
{
    protected $signature = 'nezha:expire-locallife-posts {--dry-run : 只报告将失效多少条, 不实际改写}';

    protected $description = '哪吒本地生活: 到期(expires_at<now)的在售 UGC 帖自动转 expired(已失效)。';

    public function handle()
    {
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $query = LocalLifePost::where('source', 'user')
            ->where('listing_status', LocalLifePost::LISTING_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now);

        $count = $query->count();
        $this->info('哪吒本地生活到期失效: 截止=' . $now->toDateTimeString()
            . ', 命中在售且已过期帖数: ' . $count . ', 模式=' . ($dry ? 'DRY-RUN' : '实改'));

        if ($dry || $count === 0) {
            return self::SUCCESS;
        }

        $n = $query->update([
            'listing_status' => LocalLifePost::LISTING_EXPIRED,
            'updated_at'     => now(),
        ]);

        $msg = '已将 ' . $n . ' 条到期在售帖转为 expired(已失效)。';
        $this->info($msg);
        Log::info('NEZHA_EXPIRE_LOCALLIFE_POSTS: ' . $msg);

        return self::SUCCESS;
    }
}
