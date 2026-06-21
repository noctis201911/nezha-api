<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 哪吒 - 本地生活 UGC 帖 PII 留存期清除（L1-7: PII 到期删 / PDPA-GDPR 最小留存）。
 *
 * 用户发帖里的 contact_info（电话/微信等联系方式）+ 上传图片属个人数据，
 * 过期(expires_at < now)后应清除。做法：
 *   - 抹掉 contact_info（置 null）
 *   - 删除 images 指向 storage 的图片文件，并清空 images
 *   - 保留帖子行 / 状态 / 标题等供审计与展示层判断
 *   - 仅处理 source='user' 的 UGC 帖；平台(admin)帖不在此列
 *
 * 另：本地生活顾客举报(local_life_reports)的 detail（"补充说明"，"其他"理由必填，
 * 可能含联系方式等 PII）也按保留期到期清理 —— 只清 detail（置 null），
 * 保留 行/reason/status 供运营审计。该表无 expires_at，用 created_at 判过期，
 * 保留期 business_settings.locallife_report_retention_days（默认 180 天）。
 *
 * 注：本库已全表静态加密(at-rest)，本命令是"到期主动删"这层(超出加密)的持续义务。
 */
class PurgeLocalLifePii extends Command
{
    protected $signature = 'nezha:purge-locallife-pii {--dry-run : 只报告将清除什么, 不实际删除}';

    protected $description = '哪吒: 清除已过期的本地生活UGC帖PII(contact_info + 上传图片)与举报detail, 保留帖子行/举报行/状态供审计。';

    private const IMG_DIR = 'local-life';

    public function handle()
    {
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $this->info('哪吒本地生活PII清除: 截止=' . $now->toDateTimeString() . ', 模式=' . ($dry ? 'DRY-RUN(只报告)' : '实删'));

        // 命中: source=user、已过期、且 contact_info 还没清过
        $rows = DB::table('local_life_posts')
            ->where('source', 'user')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->whereNotNull('contact_info')
            ->where('legal_hold', false) // 证据冻结的违规帖豁免到期清除(L1-7有限例外): contact_info+图全留供留证
            ->get(['id', 'images', 'expires_at']);

        $this->info('命中待清除帖数: ' . $rows->count());

        $purged = 0;
        $files = 0;

        foreach ($rows as $row) {
            // 删图片文件
            $images = json_decode($row->images, true);
            if (is_array($images)) {
                foreach ($images as $name) {
                    if (is_string($name) && $name !== '' && $this->deleteImage($name, $dry)) {
                        $files++;
                    }
                }
            }

            if ($dry) {
                $this->line('  [DRY] 将清除 local_life_posts#' . $row->id . ' (过期于 ' . $row->expires_at . ')');
                $purged++;
                continue;
            }

            DB::table('local_life_posts')->where('id', $row->id)->update([
                'contact_info' => null,
                'images'       => null,
                'updated_at'   => now(),
            ]);
            $purged++;
        }

        $msg = ($dry ? '[DRY-RUN] 将清除 ' : '已清除 ') . $purged . ' 条UGC帖PII(contact_info), '
            . ($dry ? '将删 ' : '删除 ') . $files . ' 个图片文件。帖子行/状态保留供审计。';
        $this->info($msg);
        Log::info('NEZHA_PURGE_LOCALLIFE_PII: ' . $msg);

        // ── 本地生活顾客举报 detail 的 PII 到期清理（L1-7）─────────────────
        // local_life_reports.detail 是举报"补充说明"("其他"理由必填), 可能含联系方式等 PII。
        // 表无 expires_at, 用 created_at 判过期; 保留期 business_settings.locallife_report_retention_days(默认180天)。
        // 只清 detail(置 null), 保留 行/reason/status 供运营审计。
        $reportDays = (int) (DB::table('business_settings')
            ->where('key', 'locallife_report_retention_days')
            ->value('value') ?? 180);
        if ($reportDays < 1) {
            $reportDays = 180;
        }
        $reportCutoff = Carbon::now()->subDays($reportDays);

        // 命中: created_at 早于截止、且 detail 还没清过(非空)
        $reportRows = DB::table('local_life_reports')
            ->whereNotNull('detail')
            ->where('detail', '!=', '')
            ->where('created_at', '<', $reportCutoff)
            ->get(['id', 'created_at']);

        $this->info('举报detail清理: 保留期=' . $reportDays . '天, 截止=' . $reportCutoff->toDateTimeString()
            . ', 命中待清理举报数: ' . $reportRows->count());

        $reportsPurged = 0;

        foreach ($reportRows as $r) {
            if ($dry) {
                $this->line('  [DRY] 将清除 local_life_reports#' . $r->id . '.detail (建于 ' . $r->created_at . ')');
                $reportsPurged++;
                continue;
            }

            DB::table('local_life_reports')->where('id', $r->id)->update([
                'detail'     => null,
                'updated_at' => now(),
            ]);
            $reportsPurged++;
        }

        $reportMsg = ($dry ? '[DRY-RUN] 将清除 ' : '已清除 ') . $reportsPurged
            . ' 条举报的 detail(补充说明 PII), 保留 行/reason/status 供审计。';
        $this->info($reportMsg);
        Log::info('NEZHA_PURGE_LOCALLIFE_REPORTS_PII: ' . $reportMsg);

        return self::SUCCESS;
    }

    private function deleteImage(string $name, bool $dry): bool
    {
        $disk = Storage::disk('public');
        // images 存的是文件名(或含目录)，统一定位到 local-life/<basename>
        $path = self::IMG_DIR . '/' . basename($name);
        if (!$disk->exists($path)) {
            // 兜底：原样路径
            if ($disk->exists($name)) {
                $path = $name;
            } else {
                return false;
            }
        }
        if ($dry) {
            $this->line('  [DRY] 待删图片: ' . $path);
            return true;
        }
        $disk->delete($path);
        $this->line('  删除图片: ' . $path);
        return true;
    }
}
