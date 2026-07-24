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

    /**
     * F-1 越权删购物车 · 回归守卫 (2026-07-23, release 4e64e7d9)
     *
     * add_to_cart_multiple 是注册用户专用端点(方法体内无条件解引用 $request->user->id),
     * 但它挂在 apiGuestCheck 中间件组下 —— 该中间件会放行任何带 body guest_id 的匿名请求。
     * 方法入口的 purgeExpiredCarts($user_id, 0) 硬编码 is_guest=0(清除的是注册用户购物车),
     * 且 $user_id 在无有效登录用户时取自攻击者可控的 body guest_id。因此若入口不 fail-closed,
     * 完全匿名的请求即可用 guest_id=<任意注册用户id> 越权清除他人 is_guest=0 的过期购物车
     * (生产 PoC 已坐实并修复)。本守卫钉住"purge 之前必须先挡掉无有效登录用户"。
     *
     * 纯静态检查(不连库),与本文件其余守卫同范式。仅在 F-1 原始漏洞面仍在(硬编码 is_guest=0 清除)
     * 时才要求 fail-closed; 若 purge 改为作用域安全写法, 漏洞面消失, 本守卫自动放行。
     */
    public function test_add_to_cart_multiple_fail_closed_before_purge(): void
    {
        $file = base_path('app/Http/Controllers/Api/V1/CartController.php');
        $this->assertFileExists($file, 'CartController 不见了, F-1 回归守卫失效');
        $code = file_get_contents($file);

        $target = null;
        foreach ($this->splitMethods($code) as $m) {
            if ($m['name'] === 'add_to_cart_multiple') {
                $target = $m;
                break;
            }
        }
        $this->assertNotNull(
            $target,
            'CartController::add_to_cart_multiple 不见了(重命名/删除?). 若端点已下线或改造, 请同步更新/移除本 F-1 回归守卫.'
        );

        $body = $target['body'];

        // 仅当仍保留 F-1 原始漏洞面(硬编码 is_guest=0 清除注册购物车)时才强制 fail-closed。
        // purge 改成作用域安全写法后漏洞面消失, 本守卫自动放行(避免绑死实现细节)。
        $purgePos = strpos($body, '$this->purgeExpiredCarts($user_id, 0)');
        if ($purgePos === false) {
            $this->addToAssertionCount(1);
            return;
        }

        $beforePurge = substr($body, 0, $purgePos);
        $failClosed =
            preg_match('/if\s*\(\s*!\s*\$request->user\b/', $beforePurge)
            || preg_match('/\$request->user\s*===?\s*null/', $beforePurge)
            || preg_match('/empty\s*\(\s*\$request->user\s*\)/', $beforePurge);

        $this->assertTrue(
            (bool) $failClosed,
            "F-1 越权删购物车回归: CartController::add_to_cart_multiple 在 purgeExpiredCarts(\$user_id, 0)"
            ." (硬编码 is_guest=0, 清除注册用户购物车) 之前缺少对 \$request->user 的 fail-closed 守卫。\n"
            ."后果: 端点挂在 apiGuestCheck 下会放行带 body guest_id 的匿名请求, 攻击者用 guest_id=<任意注册用户id>"
            ." 即可越权清他人 is_guest=0 的过期购物车(生产 PoC 坐实, release 4e64e7d9)。\n"
            ."修法: 方法入口加  if (!\$request->user) { return response()->json([...], 401); }  再 purge。\n"
            ."若已改用其它 fail-closed 方式(如移入 auth:api 组), 请更新本守卫的匹配逻辑。"
        );
    }
}
