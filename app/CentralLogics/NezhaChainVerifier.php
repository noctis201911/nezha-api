<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒外卖 — USDT 链上到账核验（只读核验，不碰资金）。
 *
 * 定位（合规）：B 方案下平台不碰钱、顾客直付商家钱包。本类只是【拿顾客填的交易哈希去公链浏览器
 * 只读查询】，核对"这笔转账是不是真到了商家收款地址、币种 USDT、金额够、已确认"，给商家/后台一个
 * 可信判断依据。它【不移动任何资金、不触发自动放款、不改订单状态】，所以不属于代收/二清，L3 实现细节。
 *
 * 当前线上商家仅用 TRC20（Tron）。BEP20 留接口、需 BscScan key 才启用。
 *
 * 返回结构（统一）：
 *   status: 'verified' | 'mismatch' | 'unconfirmed' | 'not_found' | 'uncheckable' | 'invalid_hash'
 *   reason: 中文人话原因（给商家看）
 *   to_match: bool|null     收款地址是否=商家地址
 *   amount: float|null      链上实际到账 USDT 数额
 *   amount_enough: bool|null 是否 >= 应付（含容差）
 *   confirmed: bool|null    区块是否已确认
 *   to_address: string|null 链上实际收款地址
 *   explorer_url: string    人可点开自查的浏览器链接
 *   checked_at: ISO 时间
 */
class NezhaChainVerifier
{
    // 官方 USDT 合约地址（写死，防顾客拿山寨币哈希蒙混）
    const USDT_TRC20_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    const USDT_BEP20_CONTRACT = '0x55d398326f99059fF775485246999027B3197955';

    const TRONSCAN_BASE = 'https://apilist.tronscanapi.com';

    // 金额容差：链上实付 >= 应付 * (1 - 容差)。汇率有波动 + 手续费，留 3% 缓冲，避免误判真付款。
    const AMOUNT_TOLERANCE = 0.03;

    /**
     * 校验交易哈希格式（不查链，纯本地，给前端/后端快速兜底）。
     * Tron / EVM 交易哈希都是 64 位十六进制（可带 0x 前缀）。
     */
    public static function isValidHashFormat($hash): bool
    {
        if (!is_string($hash)) return false;
        $h = trim($hash);
        $h = preg_replace('/^0x/i', '', $h);
        return (bool) preg_match('/^[0-9a-fA-F]{64}$/', $h);
    }

    /**
     * 核验入口：按 network 分流。
     *
     * @param string $hash         顾客填的交易哈希
     * @param string $expectedTo   商家 USDT 收款地址
     * @param float  $expectedUsdt 本单应付 USDT 数额（由订单 AMD 总额 / 汇率算出）
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

        $net = strtoupper($network);
        try {
            if ($net === 'TRC20') {
                return self::verifyTrc20($hash, $expectedTo, $expectedUsdt, $base);
            }
            // BEP20 / 其它链暂不接（无 key），返回不可核验，商家手动核对。
            return array_merge($base, ['status' => 'uncheckable', 'reason' => '该链暂不支持自动核验，请商家在自己钱包核对到账']);
        } catch (\Throwable $e) {
            Log::info('NezhaChainVerifier error: '.$e->getMessage());
            return array_merge($base, ['status' => 'uncheckable', 'reason' => '链上查询暂时不可用，请商家在自己钱包核对到账']);
        }
    }

    protected static function verifyTrc20($hash, $expectedTo, $expectedUsdt, array $base): array
    {
        $cleanHash = preg_replace('/^0x/i', '', trim($hash));
        $headers = [];
        $key = config('nezha.tronscan_key') ?: env('NEZHA_TRONSCAN_KEY');
        if ($key) $headers['TRON-PRO-API-KEY'] = $key;

        $resp = Http::withHeaders($headers)
            ->timeout(15)
            ->get(self::TRONSCAN_BASE.'/api/transaction-info', ['hash' => $cleanHash]);

        if (!$resp->ok()) {
            return array_merge($base, ['status' => 'uncheckable', 'reason' => '链上查询暂时不可用，请商家在自己钱包核对到账']);
        }
        $data = $resp->json();

        // 空对象 = 链上查无此交易（哈希错/编造/还没广播）
        if (empty($data) || (!isset($data['trc20TransferInfo']) && !isset($data['contractRet']))) {
            return array_merge($base, ['status' => 'not_found', 'reason' => '链上查不到这笔交易，请核对交易哈希是否填错']);
        }

        // 交易本身是否成功上链
        $contractRet = $data['contractRet'] ?? null;
        if ($contractRet !== null && $contractRet !== 'SUCCESS') {
            return array_merge($base, ['status' => 'not_found', 'reason' => '这笔交易在链上不是成功状态（'.$contractRet.'），请确认转账是否成功']);
        }

        $confirmed = (bool) ($data['confirmed'] ?? false);

        // 找到一笔【官方 USDT 合约】的转账明细
        $transfers = $data['trc20TransferInfo'] ?? [];
        $usdtLeg = null;
        foreach ($transfers as $t) {
            $contract = $t['contract_address'] ?? '';
            $symbol = strtoupper($t['symbol'] ?? '');
            if (strcasecmp($contract, self::USDT_TRC20_CONTRACT) === 0 && $symbol === 'USDT') {
                $usdtLeg = $t;
                break;
            }
        }

        if (!$usdtLeg) {
            return array_merge($base, [
                'status' => 'mismatch',
                'reason' => '这笔交易里没有官方 USDT 转账（可能是别的币/山寨合约），请核对',
                'confirmed' => $confirmed,
            ]);
        }

        $toAddress = $usdtLeg['to_address'] ?? null;
        $decimals = (int) ($usdtLeg['decimals'] ?? 6);
        $amountRaw = $usdtLeg['amount_str'] ?? '0';
        $amount = $decimals > 0 ? ((float) $amountRaw) / pow(10, $decimals) : (float) $amountRaw;

        $toMatch = $expectedTo && $toAddress && (strcasecmp(trim($toAddress), trim($expectedTo)) === 0);
        $minNeeded = (float) $expectedUsdt * (1 - self::AMOUNT_TOLERANCE);
        $amountEnough = $expectedUsdt > 0 ? ($amount + 1e-9 >= $minNeeded) : null;

        $result = array_merge($base, [
            'to_match' => $toMatch,
            'amount' => round($amount, 6),
            'amount_enough' => $amountEnough,
            'confirmed' => $confirmed,
            'to_address' => $toAddress,
        ]);

        if (!$toMatch) {
            return array_merge($result, ['status' => 'mismatch', 'reason' => '收款地址不是本店地址（链上收款方：'.self::mask($toAddress).'），请核对']);
        }
        if ($amountEnough === false) {
            return array_merge($result, ['status' => 'mismatch', 'reason' => '链上到账金额 '.rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.').' USDT 少于应付 '.$expectedUsdt.' USDT，请核对']);
        }
        if (!$confirmed) {
            return array_merge($result, ['status' => 'unconfirmed', 'reason' => '已查到这笔转账（金额、地址相符），但区块尚未最终确认，请稍后再看或商家自行核对']);
        }
        return array_merge($result, ['status' => 'verified', 'reason' => '链上已核验：已到账 '.rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.').' USDT 到本店地址']);
    }

    public static function explorerUrl($hash, $network = 'TRC20'): string
    {
        $cleanHash = preg_replace('/^0x/i', '', trim((string) $hash));
        $net = strtoupper($network);
        if ($net === 'BEP20') return 'https://bscscan.com/tx/0x'.$cleanHash;
        return 'https://tronscan.org/#/transaction/'.$cleanHash;
    }

    protected static function mask($addr): string
    {
        if (!$addr || strlen($addr) < 12) return (string) $addr;
        return substr($addr, 0, 6).'...'.substr($addr, -6);
    }
}
