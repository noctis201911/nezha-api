<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\Log;

/**
 * 哪吒外卖 — USDT 链上到账核验（只读核验，不碰资金）。
 *
 * 定位（合规）：B 方案下平台不碰钱、顾客直付商家钱包。本类只是【拿顾客填的交易哈希去公链
 * 只读查询】，核对"这笔转账是不是真到了商家收款地址、币种 USDT、金额够"，给商家/后台一个
 * 可信判断依据。它【不移动任何资金、不触发自动放款、不改订单状态】，不属于代收/二清，L3。
 *
 * 🔁 复用：链上设施（TronGrid /v1 events + BSC RPC + 已配 TRON-PRO-API-KEY）全部复用
 *    NezhaRefundControl —— 与退款反查/制裁筛查走同一套端点与 key，不重复造链上设施、不另办 key。
 *    本类只做"顾客付款核验"语义层：网络映射 + 容差判定 + 给商家看的中文状态/原因。
 *
 * 返回结构（商家订单页 + offline_payments.nezha_auto_check 消费）：
 *   status: 'verified' | 'mismatch' | 'not_found' | 'uncheckable' | 'invalid_hash'
 *   reason: 中文人话原因（给商家看）
 *   to_match/amount/amount_enough/confirmed/to_address/expected_to/expected_usdt/network/explorer_url/checked_at
 */
class NezhaChainVerifier
{
    // 金额容差：链上实付 >= 应付 * (1 - 容差)。汇率波动 + 手续费，留 3% 缓冲，避免误判真付款。
    const AMOUNT_TOLERANCE = 0.03;

    /**
     * 校验交易哈希格式（不查链，纯本地）。Tron/EVM 哈希都是 64 位十六进制（可带 0x 前缀）。
     */
    public static function isValidHashFormat($hash): bool
    {
        if (!is_string($hash)) return false;
        $h = preg_replace('/^0x/i', '', trim($hash));
        return (bool) preg_match('/^[0-9a-fA-F]{64}$/', $h);
    }

    /**
     * 核验入口。
     *
     * @param string $hash         顾客填的交易哈希
     * @param string $expectedTo   商家 USDT 收款地址
     * @param float  $expectedUsdt 本单应付 USDT 数额
     * @param string $network      TRC20 | BEP20
     */
    public static function verifyUsdt($hash, $expectedTo, $expectedUsdt, $network = 'TRC20'): array
    {
        $base = [
            'status' => 'uncheckable',
            'reason' => '',
            'to_match' => null,
            'amount' => null,
            'amount_enough' => null,
            'confirmed' => null,
            'to_address' => null,
            'expected_to' => $expectedTo,
            'expected_usdt' => $expectedUsdt,
            'network' => $network,
            'explorer_url' => self::explorerUrl($hash, $network),
            'checked_at' => now()->toIso8601String(),
        ];

        if (!self::isValidHashFormat($hash)) {
            return array_merge($base, ['status' => 'invalid_hash', 'reason' => '交易哈希格式不对（应为 64 位十六进制），请重新填写']);
        }

        $chain = self::networkToChain($network);
        if (!$chain) {
            return array_merge($base, ['status' => 'uncheckable', 'reason' => '该链暂不支持自动核验，请商家在自己钱包核对到账']);
        }

        try {
            $cleanHash = preg_replace('/^0x/i', '', trim($hash));
            // 🔁 复用退款/制裁同一套链上设施（TronGrid/BSC RPC + 已配 key），不重复造轮子。
            $r = NezhaRefundControl::verify_refund_tx($cleanHash, $chain, $expectedTo, (float) $expectedUsdt);
            $st = $r['status'] ?? 'manual';
            $d = $r['detail'] ?? [];

            // API 不可达 / 链上自动校验关闭 → 不可核验（商家手动核对）
            if ($st === 'manual') {
                return array_merge($base, ['status' => 'uncheckable', 'reason' => $d['reason'] ?? '链上查询暂时不可用，请商家在自己钱包核对到账']);
            }

            // 读到 to/amount → 本类按容差自行判定（给商家更细的中文原因）
            if (isset($d['to']) && isset($d['amount'])) {
                $to = $d['to'];
                $amount = (float) $d['amount'];
                $toMatch = $expectedTo && (strcasecmp(trim($to), trim($expectedTo)) === 0);
                $minNeeded = (float) $expectedUsdt * (1 - self::AMOUNT_TOLERANCE);
                $amountEnough = $expectedUsdt > 0 ? ($amount + 1e-9 >= $minNeeded) : null;

                $res = array_merge($base, [
                    'to_match' => $toMatch,
                    'amount' => round($amount, 6),
                    'amount_enough' => $amountEnough,
                    'confirmed' => true, // 链上 events/回执已确认才会返回转账明细
                    'to_address' => $to,
                ]);

                if (!$toMatch) {
                    return array_merge($res, ['status' => 'mismatch', 'reason' => '收款地址不是本店地址（链上收款方：'.self::mask($to).'），请核对']);
                }
                if ($amountEnough === false) {
                    return array_merge($res, ['status' => 'mismatch', 'reason' => '链上到账金额 '.self::fmt($amount).' USDT 少于应付 '.$expectedUsdt.' USDT，请核对']);
                }
                return array_merge($res, ['status' => 'verified', 'reason' => '链上已核验：已到账 '.self::fmt($amount).' USDT 到本店地址']);
            }

            // failed 但无 to/amount → 这笔交易里没有到官方 USDT 合约的转账 / 查不到该交易
            return array_merge($base, ['status' => 'not_found', 'reason' => $d['reason'] ?? '链上查不到这笔 USDT 转账，请核对交易哈希是否填错']);
        } catch (\Throwable $e) {
            Log::info('NezhaChainVerifier error: '.$e->getMessage());
            return array_merge($base, ['status' => 'uncheckable', 'reason' => '链上查询暂时不可用，请商家在自己钱包核对到账']);
        }
    }

    /** 商家收款网络 → NezhaRefundControl 的 chain 标识。 */
    protected static function networkToChain($network): ?string
    {
        $n = strtoupper(trim((string) $network));
        if (strpos($n, 'TRC') !== false || strpos($n, 'TRON') !== false) return 'trc20';
        if (strpos($n, 'BEP') !== false || strpos($n, 'BSC') !== false || strpos($n, 'BNB') !== false) return 'bsc';
        return null;
    }

    public static function explorerUrl($hash, $network = 'TRC20'): string
    {
        $cleanHash = preg_replace('/^0x/i', '', trim((string) $hash));
        if (self::networkToChain($network) === 'bsc') return 'https://bscscan.com/tx/0x'.$cleanHash;
        return 'https://tronscan.org/#/transaction/'.$cleanHash;
    }

    protected static function fmt($amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 6, '.', ''), '0'), '.');
    }

    protected static function mask($addr): string
    {
        if (!$addr || strlen($addr) < 12) return (string) $addr;
        return substr($addr, 0, 6).'...'.substr($addr, -6);
    }
}
