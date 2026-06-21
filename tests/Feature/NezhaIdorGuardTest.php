<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 哪吒[IDOR 结构化守卫 · 子项A.4] — CI 防回归门
 *
 * 目标: 让"新写的顾客端 controller 默认就安全"。扫顾客 API 命名空间
 * (app/Http/Controllers/Api/V1/, 排除 Vendor/ 子目录), 凡对"顾客/会话归属模型"
 * 做裸 by-id 查询(::find / ::findOrFail / ::where('id') / ::with(...)->find())
 * 而同方法体内**没有任何归属过滤**的, 一律判 fail。
 *
 * 归属过滤 token(命中其一即认为该方法已圈定归属):
 *   user_id / guest_id / forCustomer / forVendor / delivery_man_id / deliveryman_id /
 *   vendor_id / sender_id / receiver_id / auth_token / whereHas( / 或 行内注释 // idor-ok:
 *
 * 局限(诚实): 方法级粒度——若方法体里别处恰好出现归属 token, 这里的裸查询不会被拦
 *   (false negative)。它拦的是"整段方法毫无归属概念"的新代码。语义级精确鉴权仍需
 *   人工审(SECURITY_QA_PLAYBOOK 轴A)。本守卫是结构兜底, 不是越权鉴权的全部。
 *
 * 不连库、不写库, 纯静态扫描。
 */
class NezhaIdorGuardTest extends TestCase
{
    /** 顾客/会话归属模型(对这些模型的裸 by-id 查询需要归属过滤) */
    private const OWNER_MODELS = [
        'Order', 'CustomerAddress', 'Cart', 'OfflinePayments', 'NezhaRefundRecord',
        'Conversation', 'Message', 'Subscription', 'Wishlist', 'RecentSearch',
        'LoyaltyPointTransaction', 'WalletTransaction', 'CashBackHistory',
        'Review', 'DMReview', 'Refund', 'NezhaDeliveryAppeal',
    ];

    /** 命中其一即认为方法已圈定归属 */
    private const OWNERSHIP_TOKENS = [
        'user_id', 'guest_id', 'forCustomer', 'forVendor',
        'delivery_man_id', 'deliveryman_id', 'vendor_id',
        'sender_id', 'receiver_id', 'auth_token', 'whereHas(',
        'idor-ok',
    ];

    private function customerControllerFiles(): array
    {
        $base = base_path('app/Http/Controllers/Api/V1');
        $files = [];
        foreach (['/*.php', '/Auth/*.php'] as $glob) {
            foreach (glob($base.$glob) as $f) {
                // glob('*.php') 天然排除 .bak.<时间戳> 文件(它们不以 .php 结尾)
                $files[] = $f;
            }
        }
        return $files;
    }

    /** 把文件按"方法体"切片: 返回 [['name'=>fn, 'body'=>code], ...] */
    private function splitMethods(string $code): array
    {
        $lines = preg_split('/\R/', $code);
        $starts = [];
        foreach ($lines as $i => $ln) {
            if (preg_match('/function\s+\w+\s*\(/', $ln)) {
                $starts[] = $i;
            }
        }
        $methods = [];
        $n = count($starts);
        for ($k = 0; $k < $n; $k++) {
            $from = $starts[$k];
            $to = ($k + 1 < $n) ? $starts[$k + 1] : count($lines);
            $body = implode("\n", array_slice($lines, $from, $to - $from));
            preg_match('/function\s+(\w+)\s*\(/', $lines[$from], $m);
            $methods[] = ['name' => $m[1] ?? '?', 'body' => $body, 'line' => $from + 1];
        }
        return $methods;
    }

    /** 方法体内对某归属模型是否存在"裸 by-id 查询" */
    private function hasRiskyByIdQuery(string $body, string $model): bool
    {
        $m = preg_quote($model, '/');
        $patterns = [
            '/\b'.$m.'::\s*find\s*\(/',
            '/\b'.$m.'::\s*findOrFail\s*\(/',
            '/\b'.$m.'::\s*where\s*\(\s*[\'"]id[\'"]/',
            // ::with(...)->find(  链式(允许跨行)
            '/\b'.$m.'::\s*with\s*\(.*?->\s*find\s*\(/s',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $body)) {
                return true;
            }
        }
        return false;
    }

    private function methodHasOwnershipToken(string $body): bool
    {
        foreach (self::OWNERSHIP_TOKENS as $t) {
            if (strpos($body, $t) !== false) {
                return true;
            }
        }
        return false;
    }

    public function test_customer_controllers_have_no_unscoped_by_id_owner_queries(): void
    {
        $files = $this->customerControllerFiles();
        $this->assertNotEmpty($files, '未扫到任何顾客控制器文件, 路径可能变了, 守卫失效');

        $violations = [];
        foreach ($files as $file) {
            $code = file_get_contents($file);
            $rel = str_replace(base_path().'/', '', $file);
            foreach ($this->splitMethods($code) as $method) {
                if ($this->methodHasOwnershipToken($method['body'])) {
                    continue; // 该方法已有归属概念, 放行
                }
                foreach (self::OWNER_MODELS as $model) {
                    if ($this->hasRiskyByIdQuery($method['body'], $model)) {
                        $violations[] = sprintf(
                            '%s::%s() (~L%d) 对 %s 做裸 by-id 查询且方法内无任何归属过滤',
                            $rel, $method['name'], $method['line'], $model
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "发现未圈定归属的 by-id 查询(IDOR 风险, 子项A.4):\n  - "
            .implode("\n  - ", $violations)
            ."\n修法: 用 ->forCustomer(\$id) 或补 ->where('user_id', ...)/参与者校验; "
            ."确属合法跨查请在该行加注释 // idor-ok: <理由>"
        );
    }
}
