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
 *   - 路由锁定 lock_route(): USDT 只读付款前消费的顾客退款地址快照；tx.from 仅作来源证据.
 *   - 限额风控 check_limits(): 单笔/单日累计/单日笔数/退款窗口, 超限→over_limit(转 admin 审核, 不直接拒).
 *   - 链上校验 verify_refund_tx(): 精确校验网络/合约/目标/原子金额/终局性；未知态不能关闭退款.
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
     * USDT: ['channel'=>'usdt','chain'=>..,'original_tx_hash'=>..,'locked_to_address'=>顾客绑定快照|null,'note'=>..]
     * RMB : ['channel'=>'rmb','note'=>'退还原付款人(见付款截图), 禁止退第三方']
     */
    public static function lock_route($order): array
    {
        $channel = self::detect_channel($order);
        if ($channel === 'usdt') {
            $hash = self::extract_original_tx_hash($order);
            $credential = NezhaCustomerRefundAddressCredentialService::snapshotForOrder((int) $order->id);
            $paymentChain = self::detect_chain($hash);
            $paymentFrom = $credential?->payment_from_address;
            if (! $paymentFrom && $hash && $paymentChain) {
                // 只补来源证据。此值永远不能写入 locked_to_address。
                $paymentFrom = self::reverse_lookup_from_address($hash, $paymentChain);
            }

            if (! $credential) {
                return [
                    'channel' => 'usdt',
                    'chain' => $paymentChain,
                    'original_tx_hash' => $hash,
                    'payment_from_address' => $paymentFrom,
                    'locked_to_address' => null,
                    'route_status' => 'refund_destination_hold',
                    'hold_reason' => 'legacy_rebind_required',
                    'route_policy_version' => NezhaCustomerRefundAddressCredentialService::POLICY_VERSION,
                    'note' => '缺少顾客付款前绑定的退款地址快照，退款保持挂起；禁止使用 tx.from 或临时填写地址。',
                ];
            }

            $network = NezhaUsdtAddress::normalizeNetwork($credential->network);
            $chain = $network === NezhaUsdtAddress::BEP20 ? 'bsc' : 'trc20';
            $locked = NezhaUsdtAddress::normalize($credential->address_snapshot, $network);
            $holdReason = null;
            $destinationScreen = null;
            if ($locked === null) {
                $holdReason = 'refund_address_snapshot_invalid';
            } elseif (! $credential->paid_asset_amount_atomic
                || ! $credential->refundable_amd_snapshot
                || ! $credential->asset_contract
                || $credential->asset_decimals === null) {
                $holdReason = 'refund_amount_snapshot_missing';
            } elseif (NezhaCustomerRefundAddressCredentialService::mode()
                === NezhaCustomerRefundAddressCredentialService::MODE_CLOSED) {
                $holdReason = 'refund_mode_closed';
            }
            if (! $holdReason) {
                $destinationScreen = NezhaSanctionScreen::screen_refund_destination($locked);
                if (($destinationScreen['status'] ?? null) === 'matched') {
                    $holdReason = 'refund_destination_sanction_match';
                } elseif (($destinationScreen['status'] ?? null) !== 'cleared') {
                    $holdReason = 'refund_destination_sanction_unresolved';
                }
            }

            return [
                'channel' => 'usdt',
                'chain' => $chain,
                'asset_network' => $network,
                'asset_contract' => $credential->asset_contract,
                'asset_decimals' => $credential->asset_decimals,
                'paid_asset_amount_atomic' => $credential->paid_asset_amount_atomic !== null
                    ? (string) $credential->paid_asset_amount_atomic
                    : null,
                'refundable_amd_snapshot' => $credential->refundable_amd_snapshot !== null
                    ? (string) $credential->refundable_amd_snapshot
                    : null,
                'order_currency_snapshot' => $credential->order_currency_snapshot,
                'original_tx_hash' => $credential->payment_tx_hash ?: $hash,
                'payment_from_address' => $paymentFrom,
                'locked_to_address' => $locked,
                'address_fingerprint' => $credential->address_fingerprint,
                'verification_status' => 'customer_attested',
                'refund_address_credential_id' => (int) $credential->id,
                'route_policy_version' => (string) $credential->route_policy_version,
                'route_status' => $holdReason ? 'refund_destination_hold' : 'bound',
                'hold_reason' => $holdReason,
                'destination_screening' => $destinationScreen,
                'note' => $holdReason
                    ? '顾客付款前确认的退款地址快照当前不可执行，退款保持挂起；禁止使用 tx.from 或临时地址。'
                    : '仅退回顾客付款前确认绑定的本单退款地址；tx.from 只作来源证据，禁止作为退款目标。',
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
            $hits[] = ['rule' => 'single_limit', 'detail' => "单笔退款 ֏{$refundAmount} 超过上限 ֏{$single}"];
        }

        // 单商家今日已退累计 + 笔数(已 approved/recorded 的退款记录)
        $todayQ = NezhaRefundRecord::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', Carbon::today())
            ->whereIn('status', ['recorded', 'approved']);
        $todayTotal = (float) (clone $todayQ)->sum('refund_amount');
        $todayCount = (int) (clone $todayQ)->count();

        $dailyTotal = (float) self::cfg('nezha_refund_daily_total_limit', 300);
        if ($dailyTotal > 0 && ($todayTotal + $refundAmount) > $dailyTotal) {
            $hits[] = ['rule' => 'daily_total', 'detail' => '单日累计 ֏' . round($todayTotal + $refundAmount, 2) . " 超过 ֏{$dailyTotal}"];
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

    /** 反查原始付款 tx 的 from 地址，仅作来源证据；失败返回 null。 */
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
     * 校验 USDT tx 的网络、合约、目标、原子金额与终局性。
     *
     * 退款必须 amountMode=exact；付款核验可用 at_least。任何 manual /
     * verification_pending 都不能把退款转为完成态。
     */
    public static function verify_refund_tx(
        string $refundTxHash,
        ?string $chain,
        ?string $expectAddress,
        string $expectAtomic,
        ?string $expectContract = null,
        ?int $expectDecimals = null,
        string $amountMode = 'exact'
    ): array {
        if ((string) self::cfg('nezha_refund_usdt_verify_status', '1') !== '1') {
            return ['status' => 'manual', 'detail' => ['reason' => '链上自动校验已关闭, 待人工核']];
        }
        try {
            $expectAtomic = NezhaAtomicAmount::normalizeInteger($expectAtomic);
        } catch (\DomainException $e) {
            return ['status' => 'manual', 'detail' => ['reason' => '退款原子金额快照无效']];
        }
        if (! in_array($amountMode, ['exact', 'at_least'], true)) {
            return ['status' => 'manual', 'detail' => ['reason' => '金额匹配策略无效']];
        }
        try {
            if ($chain === 'bsc') {
                return self::verify_bsc(
                    $refundTxHash,
                    $expectAddress,
                    $expectAtomic,
                    $expectContract ?: self::BSC_USDT,
                    $expectDecimals ?? self::BSC_DEC,
                    $amountMode
                );
            }
            if ($chain === 'trc20') {
                return self::verify_trc(
                    $refundTxHash,
                    $expectAddress,
                    $expectAtomic,
                    $expectContract ?: self::TRC_USDT,
                    $expectDecimals ?? self::TRC_DEC,
                    $amountMode
                );
            }
            return ['status' => 'manual', 'detail' => ['reason' => '链未知, 待人工核']];
        } catch (\Throwable $e) {
            info(['nezha_refund verify err', $e->getMessage()]);
            return ['status' => 'manual', 'detail' => ['reason' => 'API异常待人工核', 'err' => $e->getMessage()]];
        }
    }

    /**
     * Vendor/Admin 唯一 USDT 完成入口：先链上核验，只有 verified 才原子转状态。
     */
    public static function verifyAndComplete(
        NezhaRefundRecord $record,
        string $refundTxHash,
        ?int $restaurantId = null,
        ?string $note = null
    ): array {
        $hash = trim($refundTxHash);
        if (! NezhaChainVerifier::isValidHashFormat($hash)) {
            return ['status' => 'failed', 'reason' => 'refund_tx_hash_invalid', 'record' => null];
        }
        if ($record->payment_channel !== 'usdt'
            || $record->status !== 'pending_merchant_refund'
            || ! $record->reconfirmed_at
            || ! $record->locked_to_address
            || $record->refund_asset_amount_atomic === null) {
            return ['status' => 'failed', 'reason' => 'refund_not_executable', 'record' => null];
        }
        if (NezhaCustomerRefundAddressCredentialService::mode()
            === NezhaCustomerRefundAddressCredentialService::MODE_CLOSED) {
            return ['status' => 'manual_hold', 'reason' => 'refund_mode_closed', 'record' => null];
        }

        // The list or destination can change after route creation/reconfirmation.
        // Re-screen at the actual execution edge; every non-cleared state is fail-closed.
        $destinationScreen = NezhaSanctionScreen::screen_refund_destination(
            (string) $record->locked_to_address
        );
        if (($destinationScreen['status'] ?? null) !== 'cleared') {
            $holdReason = ($destinationScreen['status'] ?? null) === 'matched'
                ? 'refund_destination_sanction_match'
                : 'refund_destination_sanction_unresolved';
            NezhaRefundRecord::whereKey($record->id)
                ->where('status', 'pending_merchant_refund')
                ->update([
                    'hold_reason' => $holdReason,
                    'risk_action' => 'review',
                    'chain_verify_status' => 'manual_hold',
                    'chain_verify_detail' => json_encode(
                        ['destination_screening' => $destinationScreen],
                        JSON_UNESCAPED_UNICODE
                    ),
                    'updated_at' => now(),
                ]);

            return ['status' => 'manual_hold', 'reason' => $holdReason, 'record' => null];
        }

        $cleanHash = strtolower(preg_replace('/^0x/i', '', $hash));
        $fingerprint = hash('sha256', (string) $record->chain.'|'.$cleanHash);
        if (NezhaRefundRecord::where('refund_tx_fingerprint', $fingerprint)
            ->where('id', '!=', $record->id)
            ->exists()) {
            return ['status' => 'failed', 'reason' => 'refund_tx_hash_reused', 'record' => null];
        }

        $result = self::verify_refund_tx(
            $record->chain === 'bsc' ? '0x'.$cleanHash : $cleanHash,
            $record->chain,
            $record->locked_to_address,
            (string) $record->refund_asset_amount_atomic,
            $record->asset_contract,
            $record->asset_decimals !== null ? (int) $record->asset_decimals : null,
            'exact'
        );
        $status = (string) ($result['status'] ?? 'manual');
        $detail = $result['detail'] ?? [];
        if ($status !== 'verified') {
            NezhaRefundRecord::whereKey($record->id)
                ->where('status', 'pending_merchant_refund')
                ->update([
                    'refund_tx_hash' => $hash,
                    'chain_verify_status' => $status,
                    'chain_verify_detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);

            return ['status' => $status, 'reason' => $detail['reason'] ?? null, 'record' => null];
        }

        try {
            $completed = NezhaRefundRecord::transitionPendingToMerchantRefunded(
                $record->id,
                [
                    'merchant_refund_note' => $note,
                    'refund_tx_hash' => $hash,
                    'refund_tx_fingerprint' => $fingerprint,
                    'chain_verify_status' => 'verified',
                    'chain_verify_detail' => $detail,
                    'hold_reason' => null,
                    'risk_action' => 'pass',
                ],
                $restaurantId
            );
        } catch (\Illuminate\Database\QueryException $e) {
            return ['status' => 'failed', 'reason' => 'refund_tx_hash_reused', 'record' => null];
        }

        return $completed
            ? ['status' => 'verified', 'reason' => null, 'record' => $completed]
            : ['status' => 'failed', 'reason' => 'refund_state_changed', 'record' => null];
    }

    protected static function verify_bsc(
        string $hash,
        ?string $expectAddr,
        string $expectAtomic,
        string $expectContract,
        int $decimals,
        string $amountMode
    ): array {
        $receipt = self::bsc_rpc('eth_getTransactionReceipt', [$hash]);
        if (!$receipt) return ['status' => 'manual', 'detail' => ['reason' => 'BSC回执不可达待人工核']];
        $status = $receipt['status'] ?? null;
        if ($status !== null && hexdec($status) !== 1) {
            return ['status' => 'failed', 'detail' => ['reason' => 'BSC交易失败(status!=1)']];
        }
        $confirmations = self::bscConfirmations($receipt['blockNumber'] ?? null);
        $requiredConfirmations = max(1, (int) self::cfg('nezha_refund_bsc_finality_blocks', 12));
        if ($confirmations === null) {
            return ['status' => 'manual', 'detail' => ['reason' => '无法确认BSC终局性']];
        }
        if ($confirmations < $requiredConfirmations) {
            return [
                'status' => 'verification_pending',
                'detail' => [
                    'reason' => 'BSC确认数不足',
                    'confirmations' => $confirmations,
                    'required_confirmations' => $requiredConfirmations,
                ],
            ];
        }

        $firstTransfer = null;
        foreach (($receipt['logs'] ?? []) as $log) {
            $addr = strtolower($log['address'] ?? '');
            $topics = $log['topics'] ?? [];
            if ($addr !== strtolower($expectContract)
                || ! isset($topics[0], $topics[2])
                || strtolower($topics[0]) !== self::TRANSFER_TOPIC) {
                continue;
            }
            $to = '0x'.strtolower(substr($topics[2], -40));
            $atomic = self::hex_to_dec_str((string) ($log['data'] ?? '0x0'));
            $detail = self::verificationDetail(
                'bsc',
                $to,
                $atomic,
                $expectAddr,
                $expectAtomic,
                strtolower($expectContract),
                $decimals,
                $confirmations,
                $requiredConfirmations
            );
            $firstTransfer = $firstTransfer ?: $detail;
            if (! $expectAddr) {
                return ['status' => 'manual', 'detail' => $detail + ['reason' => '无冻结退款地址']];
            }
            $addressMatches = NezhaUsdtAddress::equals($to, $expectAddr, NezhaUsdtAddress::BEP20);
            $amountMatches = self::amountMatches($atomic, $expectAtomic, $amountMode);
            if ($addressMatches && $amountMatches) {
                return ['status' => 'verified', 'detail' => $detail];
            }
        }

        return [
            'status' => 'failed',
            'detail' => $firstTransfer
                ? $firstTransfer + ['reason' => '退款目标或原子金额不匹配']
                : ['reason' => '未找到指定USDT合约转账事件'],
        ];
    }

    protected static function verify_trc(
        string $hash,
        ?string $expectAddr,
        string $expectAtomic,
        string $expectContract,
        int $decimals,
        string $amountMode
    ): array {
        $base = rtrim((string) self::cfg('nezha_refund_tron_api_base', 'https://api.trongrid.io'), '/');
        $resp = Http::timeout(12)->withHeaders(self::tron_headers())->get($base . '/v1/transactions/' . $hash . '/events');
        if (!$resp->ok()) return ['status' => 'manual', 'detail' => ['reason' => 'Tron事件不可达待人工核']];
        $firstTransfer = null;
        foreach (data_get($resp->json(), 'data', []) as $ev) {
            if (strtoupper((string) data_get($ev, 'event_name')) !== 'TRANSFER') continue;
            $contract = self::tron_hex_to_base58(data_get($ev, 'contract_address', ''));
            if ($contract !== $expectContract) continue;
            $to    = self::tron_any_to_base58((string) data_get($ev, 'result.to', ''));
            $raw = NezhaAtomicAmount::normalizeInteger((string) data_get($ev, 'result.value', '0'));
            $confirmations = self::tronConfirmations(
                $base,
                data_get($ev, 'block_number')
            );
            $requiredConfirmations = max(1, (int) self::cfg('nezha_refund_tron_finality_blocks', 20));
            if ($confirmations === null) {
                return ['status' => 'manual', 'detail' => ['reason' => '无法确认TRON终局性']];
            }
            if ($confirmations < $requiredConfirmations) {
                return [
                    'status' => 'verification_pending',
                    'detail' => [
                        'reason' => 'TRON确认数不足',
                        'confirmations' => $confirmations,
                        'required_confirmations' => $requiredConfirmations,
                    ],
                ];
            }

            $detail = self::verificationDetail(
                'trc20',
                $to,
                $raw,
                $expectAddr,
                $expectAtomic,
                $contract,
                $decimals,
                $confirmations,
                $requiredConfirmations
            );
            $firstTransfer = $firstTransfer ?: $detail;
            if (! $expectAddr) {
                return ['status' => 'manual', 'detail' => $detail + ['reason' => '无冻结退款地址']];
            }
            if (NezhaUsdtAddress::equals($to, $expectAddr, NezhaUsdtAddress::TRC20)
                && self::amountMatches($raw, $expectAtomic, $amountMode)) {
                return ['status' => 'verified', 'detail' => $detail];
            }
        }

        return [
            'status' => 'failed',
            'detail' => $firstTransfer
                ? $firstTransfer + ['reason' => '退款目标或原子金额不匹配']
                : ['reason' => '未找到指定USDT合约转账事件'],
        ];
    }

    protected static function verificationDetail(
        string $chain,
        string $to,
        string $atomic,
        ?string $expectAddress,
        string $expectAtomic,
        string $contract,
        int $decimals,
        int $confirmations,
        int $requiredConfirmations
    ): array {
        return [
            'chain' => $chain,
            'contract' => $contract,
            'decimals' => $decimals,
            'to' => $to,
            'amount_atomic' => NezhaAtomicAmount::normalizeInteger($atomic),
            'amount' => NezhaAtomicAmount::atomicToDecimal($atomic, $decimals),
            'expect_to' => $expectAddress,
            'expect_amount_atomic' => NezhaAtomicAmount::normalizeInteger($expectAtomic),
            'expect_amount' => NezhaAtomicAmount::atomicToDecimal($expectAtomic, $decimals),
            'confirmations' => $confirmations,
            'required_confirmations' => $requiredConfirmations,
        ];
    }

    protected static function amountMatches(string $actual, string $expected, string $mode): bool
    {
        $comparison = NezhaAtomicAmount::compare($actual, $expected);

        return $mode === 'at_least' ? $comparison >= 0 : $comparison === 0;
    }

    protected static function bscConfirmations(?string $receiptBlock): ?int
    {
        if (! $receiptBlock) {
            return null;
        }
        $latest = self::bsc_rpc('eth_blockNumber', []);
        if (! is_string($latest)) {
            return null;
        }
        $latestDecimal = self::hex_to_dec_str($latest);
        $receiptDecimal = self::hex_to_dec_str($receiptBlock);
        if (function_exists('bcsub') && function_exists('bccomp')) {
            if (bccomp($latestDecimal, $receiptDecimal, 0) < 0) {
                return null;
            }

            return (int) bcadd(bcsub($latestDecimal, $receiptDecimal, 0), '1', 0);
        }

        return max(0, hexdec($latest) - hexdec($receiptBlock) + 1);
    }

    protected static function tronConfirmations(string $base, $eventBlock): ?int
    {
        if (! is_numeric($eventBlock)) {
            return null;
        }
        $resp = Http::timeout(12)
            ->withHeaders(self::tron_headers())
            ->post($base.'/wallet/getnowblock');
        if (! $resp->ok()) {
            return null;
        }
        $latest = data_get($resp->json(), 'block_header.raw_data.number');
        if (! is_numeric($latest) || (int) $latest < (int) $eventBlock) {
            return null;
        }

        return (int) $latest - (int) $eventBlock + 1;
    }

    // 哪吒[BSC 多节点 failover]: 公共 bsc-dataseed 会限流/偶尔节点滞后 —— 单节点不可靠。
    // 依次尝试一组公共 BSC RPC, 返回首个非 null 结果; 全部不可达/都 null 才返回 null。
    // 让 BEP20 链上核验(退款/制裁/离线收款)稳定可用。后台可用 nezha_refund_chain_rpc_bsc_list(逗号分隔)整表覆盖。
    protected static function bscRpcEndpoints(): array
    {
        $primary = trim((string) self::cfg('nezha_refund_chain_rpc_bsc', 'https://bsc-dataseed.binance.org'));
        $custom = trim((string) self::cfg('nezha_refund_chain_rpc_bsc_list', ''));
        if ($custom !== '') {
            $list = array_map('trim', explode(',', $custom));
        } else {
            $list = [
                $primary,
                'https://bsc-dataseed.bnbchain.org',
                'https://bsc-rpc.publicnode.com',
                'https://bsc-dataseed1.defibit.io',
                'https://bsc.drpc.org',
                'https://1rpc.io/bnb',
            ];
        }
        return array_values(array_unique(array_filter($list)));
    }

    protected static function bsc_rpc(string $method, array $params)
    {
        foreach (self::bscRpcEndpoints() as $rpc) {
            try {
                $resp = Http::timeout(10)->post($rpc, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
                if (!$resp->ok()) continue;
                $j = $resp->json();
                if (!is_array($j) || isset($j['error'])) continue; // 限流(-32005)/节点错 → 换下一个
                if (array_key_exists('result', $j)) {
                    if ($j['result'] !== null) return $j['result'];
                    // result=null: 可能该节点滞后未同步到该交易, 继续探下一个节点。
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null; // 所有节点都不可达或都 null(真查无 / 未生成回执)
    }

    protected static function tron_headers(): array
    {
        $key = (string) self::cfg('nezha_refund_trongrid_api_key', '');
        return $key !== '' ? ['TRON-PRO-API-KEY' => $key] : [];
    }

    protected static function hex_to_dec_str(string $hex): string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', trim($hex)));
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }
        if (! ctype_xdigit($hex)) {
            throw new \DomainException('invalid_hex_amount');
        }
        if (function_exists('bcadd') && function_exists('bcmul')) {
            $decimal = '0';
            for ($i = 0, $length = strlen($hex); $i < $length; $i++) {
                $decimal = bcadd(bcmul($decimal, '16', 0), (string) hexdec($hex[$i]), 0);
            }

            return NezhaAtomicAmount::normalizeInteger($decimal);
        }

        if (strlen($hex) > 15) {
            throw new \DomainException('exact_hex_math_unavailable');
        }

        return NezhaAtomicAmount::normalizeInteger((string) hexdec($hex));
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
