<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * 哪吒 - 顾客离线支付凭证 PII 留存期清除。
 *
 * 合规背景(L1: PII 到期删 / PDPA-GDPR 最小留存): 顾客提交的离线支付凭证
 * (转账截图、付款备注、交易信息) 属个人数据, 超过保留期应删除。
 *
 * 做法: 抹掉 offline_payments 行里的 PII 字段(payment_info/method_fields/customer_note)
 * 并删除其中指向 storage 的截图文件; 但【保留行本身 + order_id + status + 时间戳】供审计。
 * 【不触碰】orders / order_transactions / (未来的)链上记录 —— 这些按合规另行留存 >=5 年。
 *
 * 保留天数: business_settings.nezha_payment_proof_retention_days (默认 90)。
 */
class PurgePaymentProofs extends Command
{
    protected $signature = 'nezha:purge-payment-proofs {--dry-run : 只报告将清除什么, 不实际删除}';

    protected $description = '哪吒: 按保留期清除顾客离线支付凭证PII(payment_info/method_fields/customer_note + 关联截图), 保留行与状态供审计; 不动订单/交易/链上记录。';

    public function handle()
    {
        $days = (int) (DB::table('business_settings')
            ->where('key', 'nezha_payment_proof_retention_days')
            ->value('value') ?? 90);
        if ($days < 1) {
            $days = 90;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dry = (bool) $this->option('dry-run');

        $this->info('哪吒凭证清除: 保留期=' . $days . '天, 截止=' . $cutoff->toDateTimeString() . ', 模式=' . ($dry ? 'DRY-RUN(只报告)' : '实删'));

        // 只挑 created_at 早于截止、且还没被清除过(payment_info 非空)的行
        $rows = DB::table('offline_payments')
            ->whereNotNull('payment_info')
            ->where('created_at', '<', $cutoff)
            ->get(['id', 'order_id', 'payment_info', 'method_fields', 'note', 'created_at']);

        $this->info('命中待清除行数: ' . $rows->count());

        $purged = 0;
        $files = 0;

        foreach ($rows as $row) {
            // 1) 删除 payment_info 里任何指向 storage 的截图/凭证文件
            $info = json_decode($row->payment_info, true);
            if (is_array($info)) {
                foreach ($info as $value) {
                    if (is_string($value) && $this->looksLikeStoredFile($value)) {
                        if ($this->deleteStoredFile($value, $dry)) {
                            $files++;
                        }
                    }
                }
            }

            if ($dry) {
                $this->line('  [DRY] 将清除 offline_payments#' . $row->id . ' (order ' . $row->order_id . ', 建于 ' . $row->created_at . ')');
                $purged++;
                continue;
            }

            // 2) 抹掉 PII 字段; 保留行 / order_id / status / 时间戳供审计
            $marker = '[PII purged after ' . $days . 'd retention on ' . now()->toDateString() . ']';
            DB::table('offline_payments')->where('id', $row->id)->update([
                'payment_info'  => null,
                'method_fields' => null,
                'customer_note' => null,
                'note'          => trim(($row->note ? $row->note . ' | ' : '') . $marker),
                'updated_at'    => now(),
            ]);
            $purged++;
        }

        // V2 Alipay screenshots are untrusted manual PII evidence. The refund
        // case/audit facts remain for the financial retention period, while the
        // image follows the same configurable proof-retention clock.
        if (Schema::hasTable('nezha_refund_records')
            && Schema::hasColumn('nezha_refund_records', 'source_domain')) {
            $lateProofs = DB::table('nezha_refund_records')
                ->where('source_domain', 'direct_payment_late_v2')
                ->whereNotNull('refund_proof_image')
                ->where('created_at', '<', $cutoff)
                ->get(['id', 'order_id', 'refund_proof_image', 'created_at']);

            foreach ($lateProofs as $proof) {
                $path = (string) $proof->refund_proof_image;
                if ($this->looksLikeStoredFile($path) && $this->deleteStoredFile($path, $dry)) {
                    $files++;
                }
                if ($dry) {
                    $this->line('  [DRY] 将清除迟付案件截图 refund_record#'.$proof->id.' (order '.$proof->order_id.')');
                } else {
                    DB::table('nezha_refund_records')->where('id', $proof->id)->update([
                        'refund_proof_image' => null,
                        'updated_at' => now(),
                    ]);
                }
                $purged++;
            }
        }

        $msg = ($dry ? '[DRY-RUN] 将清除 ' : '已清除 ') . $purged . ' 条凭证PII, '
            . ($dry ? '将删 ' : '删除 ') . $files . ' 个凭证文件。审计/交易/链上记录未触碰。';
        $this->info($msg);
        Log::info('NEZHA_PURGE_PAYMENT_PROOFS: ' . $msg);

        return self::SUCCESS;
    }

    /**
     * 判断字符串是否像一个存放在 public 磁盘里的凭证文件路径。
     * 加双重守卫(扩展名白名单 + 文件真实存在)防止误删交易号等纯文本。
     */
    private function looksLikeStoredFile(string $value): bool
    {
        if ($value === '' || strlen($value) > 255) {
            return false;
        }
        if (! preg_match('/\.(png|jpe?g|webp|gif|pdf)$/i', $value)) {
            return false;
        }
        $disk = Storage::disk('public');
        return $disk->exists($value) || $disk->exists(ltrim($value, '/'));
    }

    private function deleteStoredFile(string $value, bool $dry): bool
    {
        $disk = Storage::disk('public');
        $path = $disk->exists($value) ? $value : ltrim($value, '/');
        if (! $disk->exists($path)) {
            return false;
        }
        if ($dry) {
            $this->line('  [DRY] 待删文件: ' . $path);
            return true;
        }
        $disk->delete($path);
        $this->line('  删除文件: ' . $path);
        return true;
    }
}
