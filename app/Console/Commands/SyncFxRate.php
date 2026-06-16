<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\CentralLogics\Helpers;
use Carbon\Carbon;

/**
 * 哪吒 - 每天自动对齐结算汇率 (nezha_rate_usd_to_amd / nezha_rate_cny_to_amd)。
 *
 * 背景: 平台 B 方案 — 商家按德拉姆(֏)标价, 顾客按此汇率折算成 ¥/USDT 直付商家。
 * 这两个值是【事实结算汇率】, 直接决定顾客实付、商家实收。手动维护容易放旧 →
 * 商家按过期价收钱。本命令每天从公开汇率源拉市场中间价自动对齐(用户已选: 贴市场、不加缓冲)。
 *
 * 合规等级: L2(业务参数, 已获用户批准自动化)。平台仍全程不碰资金(L1-1未变);
 * 只是把"顾客↔商家直付"用的公开换算率保持新鲜, 不引入任何换汇/提现入口(L1-3未变)。
 *
 * 护栏(任一触发则【不改、保留旧值、记录告警状态】供后台查看, 命令仍以成功退出避免反复告警):
 *   1) 取数失败 / 内容异常
 *   2) 数值越界(疑似坏数据): usd_to_amd∉[250,550] 或 cny_to_amd∉[35,80]
 *   3) 单次变动 > MAX_CHANGE_PCT(默认8%): 疑似坏数据或重大行情, 留给人工确认
 * 每次结果(ok/skipped/dry-run)写入 business_settings.nezha_fx_last_sync, 后台收款信息页展示。
 */
class SyncFxRate extends Command
{
    protected $signature = 'nezha:sync-fx-rate {--dry-run : 只报告将写入什么, 不实际更新} {--force : 跳过"单周突变"阈值护栏(越界/取数失败护栏仍生效)}';

    protected $description = '哪吒: 每天从公开汇率源对齐结算汇率(usd_to_amd/cny_to_amd), 带护栏(取数失败/越界/突变则不改+记录告警)。';

    // 合理区间护栏(防坏数据)。AMD/USD 历史约 380-420; CNY/AMD 约 50-60。给宽但能挡住明显坏值。
    const USD_AMD_MIN = 250.0;
    const USD_AMD_MAX = 550.0;
    const CNY_AMD_MIN = 35.0;
    const CNY_AMD_MAX = 80.0;

    // 单次更新最大允许变动百分比, 超过则不自动改、等人工确认。
    const MAX_CHANGE_PCT = 8.0;

    // 汇率源(免费、无需 key; 现有汇率当初即取自此源)。
    const SOURCE = 'https://open.er-api.com/v6/latest/USD';

    private $lastError = '';

    public function handle()
    {
        $dry   = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            // 1) 取数(失败护栏)
            $data = $this->fetchJson(self::SOURCE);
            if ($data === null) {
                return $this->bail('取数失败: ' . $this->lastError);
            }
            if (($data['result'] ?? '') !== 'success'
                || empty($data['rates']['AMD']) || empty($data['rates']['CNY'])) {
                return $this->bail('取数内容异常(result/rates.AMD/rates.CNY 缺失)');
            }

            $amdPerUsd = (float) $data['rates']['AMD'];   // 1 USD = ? AMD
            $cnyPerUsd = (float) $data['rates']['CNY'];   // 1 USD = ? CNY
            if ($amdPerUsd <= 0 || $cnyPerUsd <= 0) {
                return $this->bail('取数数值非正');
            }

            $usdToAmd = round($amdPerUsd, 4);                 // 1 美元 = ? ֏
            $cnyToAmd = round($amdPerUsd / $cnyPerUsd, 4);    // 1 人民币 = ? ֏

            // 2) 区间护栏(防坏数据)
            if ($usdToAmd < self::USD_AMD_MIN || $usdToAmd > self::USD_AMD_MAX
                || $cnyToAmd < self::CNY_AMD_MIN || $cnyToAmd > self::CNY_AMD_MAX) {
                return $this->bail(sprintf('数值越界(疑似坏数据): usd_to_amd=%.4f cny_to_amd=%.4f', $usdToAmd, $cnyToAmd));
            }

            // 3) 与现值比对(突变护栏)
            $curUsd = (float) (DB::table('business_settings')->where('key', 'nezha_rate_usd_to_amd')->value('value') ?? 0);
            $curCny = (float) (DB::table('business_settings')->where('key', 'nezha_rate_cny_to_amd')->value('value') ?? 0);

            $usdChange = $curUsd > 0 ? abs($usdToAmd - $curUsd) / $curUsd * 100 : 0.0;
            $cnyChange = $curCny > 0 ? abs($cnyToAmd - $curCny) / $curCny * 100 : 0.0;
            $maxChange = max($usdChange, $cnyChange);

            if (!$force && ($usdChange > self::MAX_CHANGE_PCT || $cnyChange > self::MAX_CHANGE_PCT)) {
                return $this->bail(sprintf(
                    '单次变动超阈值%.0f%%(USD %.2f%%, CNY %.2f%%), 不自动改、保留旧值等人工确认。建议值 usd=%.4f cny=%.4f(现 usd=%.4f cny=%.4f)',
                    self::MAX_CHANGE_PCT, $usdChange, $cnyChange, $usdToAmd, $cnyToAmd, $curUsd, $curCny
                ), $usdToAmd, $cnyToAmd);
            }

            // 4) 写入(或 dry-run 仅报告)
            $msg = sprintf('usd_to_amd %.4f→%.4f, cny_to_amd %.4f→%.4f (变动%.2f%%)',
                $curUsd, $usdToAmd, $curCny, $cnyToAmd, $maxChange);

            if ($dry) {
                $this->info('[DRY-RUN] 将更新: ' . $msg);
                $this->recordStatus('dry-run', $msg, null, null);
                return self::SUCCESS;
            }

            Helpers::businessUpdateOrInsert(['key' => 'nezha_rate_usd_to_amd'], ['value' => (string) $usdToAmd]);
            Helpers::businessUpdateOrInsert(['key' => 'nezha_rate_cny_to_amd'], ['value' => (string) $cnyToAmd]);

            $this->info('已自动对齐: ' . $msg);
            Log::info('[nezha-fx] 自动对齐成功: ' . $msg);
            $this->recordStatus('ok', $msg, null, null);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // 任何意外都不让调度器抛错; 记录后以成功退出, 保留旧汇率。
            return $this->bail('异常: ' . $e->getMessage());
        }
    }

    /** 护栏触发: 不改汇率, 记录告警状态, 命令以成功退出(避免调度器反复报错告警)。 */
    private function bail(string $reason, $suggestUsd = null, $suggestCny = null)
    {
        $this->warn('[nezha-fx] 跳过更新: ' . $reason);
        Log::warning('[nezha-fx] 跳过更新: ' . $reason);
        $this->recordStatus('skipped', $reason, $suggestUsd, $suggestCny);
        return self::SUCCESS;
    }

    /** 把最近一次同步结果写进 business settings, 供后台收款信息页展示("告警等你看"的可见面)。 */
    private function recordStatus(string $status, string $detail, $suggestUsd, $suggestCny)
    {
        $payload = [
            'at'          => Carbon::now()->toDateTimeString(),
            'status'      => $status,        // ok / skipped / dry-run
            'detail'      => $detail,
            'suggest_usd' => $suggestUsd,    // 仅 skipped 时有意义(建议值, 供人工参考)
            'suggest_cny' => $suggestCny,
        ];
        Helpers::businessUpdateOrInsert(['key' => 'nezha_fx_last_sync'], ['value' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    /** 用 PHP 原生 curl 取 JSON(不依赖 guzzle/Http, 最稳)。失败返回 null 并填 lastError。 */
    private function fetchJson(string $url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'nezha-fx-sync/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            $this->lastError = $err !== '' ? $err : ('HTTP ' . $code);
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $this->lastError = 'JSON 解析失败';
            return null;
        }
        return $json;
    }
}
