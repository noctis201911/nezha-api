<?php

namespace App\CentralLogics;

use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\OfflinePayments;
use App\Models\NezhaRefundRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * 哪吒外卖 退款机制② 引擎.
 *
 * 职责:
 *   - 通道识别 detect_channel(): 从 offline_payments.payment_info 的 method_id 判 rmb/usdt.
 *   - 原路锁定 lock_route(): USDT 从原始 tx hash 反查 from 地址(=退款目标, 锁死); 法币锁"退还原付款人"政策.
 *   - 限额风控 check_limits(): 单笔/单日累计/单日笔数/退款窗口, 超限→over_limit(转 admin 审核, 不直接拒).
 *   - 链上校验 verify_refund_tx(): 校验退款 tx 收款方==锁定地址 且 金额≥退款额; API 挂→manual(不阻断).
 *
 * 所有阈值/开关来自 business_settings(后台可调). 总开关 nezha_refund_control_status 独立于下单风控.
 * 链上调用全程 try/catch + 超时, 失败安全回退(null/manual), 绝不抛错阻断退款.
 */
class NezhaRefundControl
{
    // USDT 合约 + ERC20 Transfer 事件 topic
    const BSC_USDT   = '0x55d398326f99059ff775485246999027b3197955'; // BEP20 USDT, 18 位小数
    const BSC_DEC    = 18;
    const TRC_USDT   = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';          // TRC20 USDT, 6 位小数
    const TRC_DEC    = 6;
    const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    protected static function cfg(string $key, $default = null)
    {
        $v = BusinessSetting::where('key', $key)->first()?->value;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function enabled(): bool
    {
        return (string) self::cfg('nezha_refund_control_status', '0') === '1';
    }

    /** 从订单的离线支付信息判付款通道. */
    public static function detect_channel($order): string
    {
        $info = OfflinePayments::where('order_id', $order->id)->first();
        if ($info) {
            $pi = json_decode($info->payment_info, true) ?: [];
            $mid = (int) ($pi['method_id'] ?? 0);
            if ($mid === 2) return 'usdt';
            if ($mid === 1) return 'rmb';
            $name = strtoupper((string) ($pi['method_name'] ?? ''));
            if (str_contains($name, 'USDT') || str_contains($name, 'TRC') || str_contains($name, 'BSC')) return 'usdt';
            if ($name !== '') return 'rmb';
        }
        return 'other';
    }

    /** 从 payment_info 提取原始付款 tx hash(USDT). 取形如 tx hash 的字段值. */
    public static function extract_original_tx_hash($order): ?string
    {
        $info = OfflinePayments::where('order_id', $order->id)->first();
        if (!$info) return null;
        $pi = json_decode($info->payment_info, true) ?: [];
        foreach ($pi as $k => $v) {
            if (!is_string($v)) continue;
            $val = trim($v);
            // BSC: 0x + 64 hex; TRC: 64 hex(无0x)
            if (preg_match('/^0x[0-9a-fA-F]{64}$/', $val) || preg_match('/^[0-9a-fA-F]{64}$/', $val)) {
                return $val;
            }
        }
        return null;
    }

    /** 链识别: 0x 前缀→bsc; 否则→trc20. */
    public static function detect_chain(?string $hash): ?string
    {
        if (!$hash) return null;
        return str_starts_with($hash, '0x') ? 'bsc' : 'trc20';
    }

    /**
     * 原路锁定: 返回退款目标信息.
     * USDT: ['channel'=>'usdt','chain'=>..,'original_tx_hash'=>..,'locked_to_address'=>反查地址|null,'note'=>..]
     * RMB : ['channel'=>'rmb','note'=>'退还原付款人(见付款截图), 禁止退第三方']
     */
    public static function lock_route($order): array
    {
        $channel = self::detect_channel($order);
        if ($channel === 'usdt') {
            $hash  = self::extract_original_tx_hash($order);
            $chain = self::detect_chain($hash);
            $from  = $hash ? self::reverse_lookup_from_address($hash, $chain) : null;
            $note  = $from
                ? "USDT 原路: 仅退回原付款地址 {$from}(由原始 tx 反查), 链={$chain}, 禁止退第三方"
                : "USDT 原路: 原始 tx 反查未果, 退款目标需人工核对原付款地址, 禁止退第三方";
            return [
                'channel' => 'usdt', 'chain' => $chain,
                'original_tx_hash' => $hash, 'locked_to_address' => $from, 'note' => $note,
            ];
        }
        if ($channel === 'rmb') {
            return ['channel' => 'rmb', 'note' => '人民币原路: 退还给原付款人(同一微信/支付宝/银行账户, 见付款截图), 禁止退第三方'];
        }
        return ['channel' => 'other', 'note' => '通道未知: 仅允许原路退回原付款人, 禁止退第三方'];
    }

    /**
     * 限额风控: 仅在总开关开启时生效. 返回 ['action'=>'pass'|'over_limit','hits'=>[...]].
     * 维度: 单笔上限 / 单商家单日退款累计 / 单商家单日退款笔数 / 退款窗口(交付后N天).
     */
    public static function check_limits($order, float $refundAmount): array
    {
        if (!self::enabled()) {
            return ['action' => 'pass', 'hits' => []];
        }
        // 超限审核放行: 该订单已有 admin 放行记录 → 本次豁免限额(对应后台「退款审核」放行)
        if (NezhaRefundRecord::where('order_id', $order->id)->where('status', 'approved')->exists()) {
            return ['action' => 'pass', 'hits' => [['rule' => 'admin_approved', 'detail' => '超限已经管理员审核放行']]];
        }
        $hits = [];
        $restaurantId = $order->restaurant_id;

        $single = (float) self::cfg('nezha_refund_single_limit', 100);
        if ($single > 0 && $refundAmount > $single) {
            $hits[] = ['rule' => 'single_limit', 'detail' => "单笔退款 \${$refundAmount} 超过上限 \${$single}"];
        }

        // 单商家今日已退累计 + 笔数(已 approved/recorded 的退款记录)
        $todayQ = NezhaRefundRecord::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', Carbon::today())
            ->whereIn('status', ['recorded', 'approved']);
        $todayTotal = (float) (clone $todayQ)->sum('refund_amount');
        $todayCount = (int) (clone $todayQ)->count();

        $dailyTotal = (float) self::cfg('nezha_refund_daily_total_limit', 300);
        if ($dailyTotal > 0 && ($todayTotal + $refundAmount) > $dailyTotal) {
            $hits[] = ['rule' => 'daily_total', 'detail' => '单日累计 $' . round($todayTotal + $refundAmount, 2) . " 超过 \${$dailyTotal}"];
        }
        $dailyCount = (int) self::cfg('nezha_refund_daily_count_limit', 5);
        if ($dailyCount > 0 && ($todayCount + 1) > $dailyCount) {
            $hits[] = ['rule' => 'daily_count', 'detail' => '单日第 ' . ($todayCount + 1) . " 笔 超过 {$dailyCount} 笔"];
        }

        // 退款窗口: 交付后 N 天
        $windowDays = (int) self::cfg('nezha_refund_window_days', 7);
        if ($windowDays > 0) {
            $deliveredAt = $order->delivered ?? $order->updated_at;
            if ($deliveredAt && Carbon::parse($deliveredAt)->addDays($windowDays)->isPast()) {
                $hits[] = ['rule' => 'window', 'detail' => "超出退款窗口({$windowDays}天)"];
            }
        }

        return ['action' => empty($hits) ? 'pass' : 'over_limit', 'hits' => $hits];
    }

    // ───────────────────────── 链上 ─────────────────────────

    /** 反查原始付款 tx 的 from 地址(= 退款目标). 失败返回 null(降级人工). */
    public static function reverse_lookup_from_address(string $txHash, ?string $chain): ?string
    {
        try {
            if ($chain === 'bsc') {
                $tx = self::bsc_rpc('eth_getTransactionByHash', [$txHash]);
                $from = $tx['from'] ?? null;
                return $from ? strtolower($from) : null;
            }
            if ($chain === 'trc20') {
                $base = rtrim((string) self::cfg('nezha_refund_tron_api_base', 'https://api.trongrid.io'), '/');
                $headers = self::tron_headers();
                $resp = Http::timeout(12)->withHeaders($headers)->post($base . '/wallet/gettransactionbyid', ['value' => $txHash]);
                if (!$resp->ok()) return null;
                $owner = data_get($resp->json(), 'raw_data.contract.0.parameter.value.owner_address');
                return $owner ? self::tron_hex_to_base58($owner) : null;
            }
        } catch (\Throwable $e) {
            info(['nezha_refund reverse_lookup err', $e->getMessage()]);
        }
        return null;
    }

    /**
     * 校验退款 tx: 收款方==期望地址 且 金额≥期望额.
     * 返回 ['status'=>'verified'|'failed'|'manual','detail'=>[...]].
     * API 不可达/无法解析 → manual(不阻断退款, 仅标记待人工核).
     */
    public static function verify_refund_tx(string $refundTxHash, ?string $chain, ?string $expectAddress, float $expectAmount): array
    {
        if ((string) self::cfg('nezha_refund_usdt_verify_status', '1') !== '1') {
            return ['status' => 'manual', 'detail' => ['reason' => '链上自动校验已关闭, 待人工核']];
        }
        try {
            if ($chain === 'bsc') {
                return self::verify_bsc($refundTxHash, $expectAddress, $expectAmount);
            }
            if ($chain === 'trc20') {
                return self::verify_trc($refundTxHash, $expectAddress, $expectAmount);
            }
            return ['status' => 'manual', 'detail' => ['reason' => '链未知, 待人工核']];
        } catch (\Throwable $e) {
            info(['nezha_refund verify err', $e->getMessage()]);
            return ['status' => 'manual', 'detail' => ['reason' => 'API异常待人工核', 'err' => $e->getMessage()]];
        }
    }

    protected static function verify_bsc(string $hash, ?string $expectAddr, float $expectAmount): array
    {
        $receipt = self::bsc_rpc('eth_getTransactionReceipt', [$hash]);
        if (!$receipt) return ['status' => 'manual', 'detail' => ['reason' => 'BSC回执不可达待人工核']];
        $status = $receipt['status'] ?? null;
        if ($status !== null && hexdec($status) !== 1) {
            return ['status' => 'failed', 'detail' => ['reason' => 'BSC交易失败(status!=1)']];
        }
        foreach (($receipt['logs'] ?? []) as $log) {
            $addr = strtolower($log['address'] ?? '');
            $topics = $log['topics'] ?? [];
            if ($addr === self::BSC_USDT && isset($topics[0]) && strtolower($topics[0]) === self::TRANSFER_TOPIC && isset($topics[2])) {
                $to = '0x' . substr($topics[2], -40);
                $amount = self::hex_to_amount($log['data'] ?? '0x0', self::BSC_DEC);
                $okAddr = $expectAddr ? (strtolower($to) === strtolower($expectAddr)) : false;
                $okAmt  = $amount + 1e-9 >= $expectAmount;
                $detail = ['chain' => 'bsc', 'to' => $to, 'amount' => $amount, 'expect_to' => $expectAddr, 'expect_amount' => $expectAmount];
                if (!$expectAddr) return ['status' => 'manual', 'detail' => $detail + ['reason' => '无锁定地址, 金额已读, 待人工核地址']];
                return ['status' => ($okAddr && $okAmt) ? 'verified' : 'failed', 'detail' => $detail];
            }
        }
        return ['status' => 'failed', 'detail' => ['reason' => '未找到USDT转账事件']];
    }

    protected static function verify_trc(string $hash, ?string $expectAddr, float $expectAmount): array
    {
        $base = rtrim((string) self::cfg('nezha_refund_tron_api_base', 'https://api.trongrid.io'), '/');
        $resp = Http::timeout(12)->withHeaders(self::tron_headers())->get($base . '/v1/transactions/' . $hash . '/events');
        if (!$resp->ok()) return ['status' => 'manual', 'detail' => ['reason' => 'Tron事件不可达待人工核']];
        foreach (data_get($resp->json(), 'data', []) as $ev) {
            if (strtoupper((string) data_get($ev, 'event_name')) !== 'TRANSFER') continue;
            $contract = self::tron_hex_to_base58(data_get($ev, 'contract_address', ''));
            if ($contract !== self::TRC_USDT) continue;
            $to    = self::tron_any_to_base58((string) data_get($ev, 'result.to', ''));
            $raw   = (string) data_get($ev, 'result.value', '0');
            $amount = (float) $raw / (10 ** self::TRC_DEC);
            $okAddr = $expectAddr ? ($to === $expectAddr) : false;
            $okAmt  = $amount + 1e-9 >= $expectAmount;
            $detail = ['chain' => 'trc20', 'to' => $to, 'amount' => $amount, 'expect_to' => $expectAddr, 'expect_amount' => $expectAmount];
            if (!$expectAddr) return ['status' => 'manual', 'detail' => $detail + ['reason' => '无锁定地址, 金额已读, 待人工核地址']];
            return ['status' => ($okAddr && $okAmt) ? 'verified' : 'failed', 'detail' => $detail];
        }
        return ['status' => 'failed', 'detail' => ['reason' => '未找到USDT转账事件']];
    }

    protected static function bsc_rpc(string $method, array $params)
    {
        $rpc = (string) self::cfg('nezha_refund_chain_rpc_bsc', 'https://bsc-dataseed.binance.org');
        $resp = Http::timeout(12)->post($rpc, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        return $resp->ok() ? ($resp->json()['result'] ?? null) : null;
    }

    protected static function tron_headers(): array
    {
        $key = (string) self::cfg('nezha_refund_trongrid_api_key', '');
        return $key !== '' ? ['TRON-PRO-API-KEY' => $key] : [];
    }

    protected static function hex_to_amount(string $hex, int $decimals): float
    {
        $hex = ltrim(str_replace('0x', '', $hex), '0');
        if ($hex === '') return 0.0;
        // 大数: 用 bcmath 转十进制再除小数位
        $dec = self::hex_to_dec_str($hex);
        if (function_exists('bcdiv')) {
            return (float) bcdiv($dec, bcpow('10', (string) $decimals), 8);
        }
        return (float) $dec / (10 ** $decimals);
    }

    protected static function hex_to_dec_str(string $hex): string
    {
        if (function_exists('bcadd')) {
            $dec = '0';
            $len = strlen($hex);
            for ($i = 0; $i < $len; $i++) {
                $dec = bcadd(bcmul($dec, '16'), (string) hexdec($hex[$i]));
            }
            return $dec;
        }
        return (string) hexdec($hex);
    }

    // Tron 地址转换: hex(41.. 或 0x..20字节) → base58check. 已是 base58(T 开头,大小写敏感) 则原样返回. 失败返回原值(便于人工核).
    // 注: 不可对入参无条件 strtolower —— base58 大小写敏感, 早前 bug 把 TronGrid 返回的 base58 合约地址 lowercase 后比对永不相等(致 TRC20 链上自动校验恒 failed).
    protected static function tron_hex_to_base58(string $addr): string
    {
        $addr = trim($addr);
        if ($addr === '') return '';
        $hex = str_starts_with($addr, '0x') ? substr($addr, 2) : $addr;
        if (!ctype_xdigit($hex)) return $addr;                // 非纯 hex → 已是 base58, 原样返回(保留大小写)
        $hex = strtolower($hex);
        if (strlen($hex) === 40) $hex = '41' . $hex;          // 补 Tron 前缀
        if (!preg_match('/^41[0-9a-f]{40}$/', $hex)) return $addr;
        $bin = hex2bin($hex);
        $hash = hash('sha256', hash('sha256', $bin, true), true);
        $checksum = substr($hash, 0, 4);
        return self::base58(bin2hex($bin . $checksum));
    }

    protected static function tron_any_to_base58(string $addr): string
    {
        $addr = trim($addr);
        if ($addr === '') return '';
        if (str_starts_with($addr, 'T')) return $addr;        // 已是 base58
        return self::tron_hex_to_base58($addr);
    }

    protected static function base58(string $hex): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num = self::hex_to_dec_str(ltrim($hex, '0') ?: '0');
        $out = '';
        if (function_exists('bcmod')) {
            while (bccomp($num, '0') > 0) {
                $rem = bcmod($num, '58');
                $num = bcdiv($num, '58', 0);
                $out = $alphabet[(int) $rem] . $out;
            }
        }
        // 前导零字节 → '1'
        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            if (substr($hex, $i, 2) === '00') $out = '1' . $out; else break;
        }
        return $out;
    }
}
