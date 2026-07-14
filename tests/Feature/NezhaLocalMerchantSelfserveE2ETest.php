<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\LocalLifeMerchant;
use App\Models\LocalLifeMerchantAccount;
use App\Models\LocalLifeMerchantChange;
use App\Notifications\LocalMerchantResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * 哪吒[本地生活商户轻管理面] — 端到端全链验证（0709 接手窗口，隔离 e2e 证）
 *
 * 🔴 安全墙: tests/bootstrap.php 在 Laravel 启动前强制 sqlite :memory:；生产 config cache 会被直接拒绝。
 * DatabaseTransactions 只负责隔离 fixture；同时保持零外呼:
 *   - MAIL_MAILER=array(phpunit.xml) + Notification::fake() → 全程零真实邮件发出
 *   - 只改 intro(不改 address) → 不触发 approve 的 geocode 外部 HTTP
 *   - 商户从顾客列表 API 取(保证可见); 改动全在事务内, 断言后整体 rollback → 顾客端零残留
 *
 * 覆盖链路: 建号(admin语义·无密码) → 设密邮件派发(broker) → 令牌设密(/m/reset) →
 *   邮箱+密码登录(/m/login·真会话) → 面板可见(/m/·/m/edit) → 提交编辑(/m/edit·全复审只写待审快照status=0) →
 *   超管过审(admin.local-life.merchant-changes.approve·应用到线上merchant) → 顾客端 API 反映新值。
 *
 * 总闸: 用 config('..._conf') 进程内开闸(get_business_settings 优先读 Config), 不碰 DB 闸行/共享缓存。
 */
class NezhaLocalMerchantSelfserveE2ETest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // 真实 HTTP 入口 public/index.php 定义此常量; CLI 测试环境未加载 index.php, 补上以调顾客端 API
        if (!defined('DOMAIN_POINTED_DIRECTORY')) {
            define('DOMAIN_POINTED_DIRECTORY', 'public');
        }
    }

    /** get_business_settings 先查 Config::get($key.'_conf'); 设它=进程内开闸, 零 DB/cache 触碰 */
    private function openGate(): void
    {
        config(['nezha_local_merchant_selfserve_status_conf' => ['value' => '1']]);
    }

    /** 从顾客列表 API 递归找出第一个 merchant id(保证该商户对顾客端可见) */
    private function firstVisibleMerchantId(): int
    {
        $resp = $this->getJson('/api/v1/local-life/merchants');
        $resp->assertOk();
        $ids = [];
        $data = $resp->json();
        array_walk_recursive($data, function ($v, $k) use (&$ids) {
            if ($k === 'id' && is_numeric($v)) {
                $ids[] = (int) $v;
            }
        });
        $this->assertNotEmpty($ids, '顾客端本地生活商户列表应至少返回 1 个商户');
        return $ids[0];
    }

    public function test_gate_closed_returns_404(): void
    {
        // 不开闸 → dormant → 整个 /m 面板 404
        $this->get('/m/login')->assertNotFound();
        $this->get('/m/forgot')->assertNotFound();
    }

    public function test_full_selfserve_chain_end_to_end(): void
    {
        Notification::fake();
        $this->openGate();

        $merchantId = $this->firstVisibleMerchantId();
        $merchant = LocalLifeMerchant::findOrFail($merchantId);
        $originalIntro = $merchant->intro;
        $newIntro = '【E2E自动验证·勿理会】本店主营家常小炒，环境整洁，欢迎光临。#' . substr(md5((string) $merchant->id), 0, 8);
        $this->assertNotSame($originalIntro, $newIntro, '新简介须与原值不同(否则断言无意义)');

        // ---- 1. 建号(admin 语义: 无密码账号, 只能走设密邮件) ----
        $email = 'e2e-selfserve-' . $merchant->id . '@example.test';
        LocalLifeMerchantAccount::where('email', $email)->delete(); // 幂等(事务内)
        $account = LocalLifeMerchantAccount::create([
            'merchant_id'  => $merchant->id,
            'email'        => $email,
            'contact_name' => 'E2E 测试联系人',
            'status'       => 1,
            'password'     => null,
        ]);
        $this->assertFalse($account->hasPassword(), '新建账号应无密码');

        // ---- 2. 设密邮件派发(= admin 建号后 sendResetLink 的动作) ----
        $sendStatus = Password::broker('local_merchants')->sendResetLink(['email' => $email]);
        $this->assertSame(Password::RESET_LINK_SENT, $sendStatus, '设密邮件应成功派发');
        Notification::assertSentTo($account, LocalMerchantResetPassword::class);

        // ---- 3. 令牌设密(模拟点开邮件里的 /m/reset 链接) ----
        $token = Password::broker('local_merchants')->createToken($account);
        $password = 'E2ePass!2026';
        $this->post('/m/reset', [
            'token'                 => $token,
            'email'                 => $email,
            'password'              => $password,
            'password_confirmation' => $password,
        ])->assertRedirect(route('local-merchant.login'));
        $this->assertTrue($account->fresh()->hasPassword(), '设密后账号应有密码');

        // ---- 4. 邮箱+密码登录(真会话·非 actingAs) ----
        $this->post('/m/login', ['email' => $email, 'password' => $password])
            ->assertRedirect(route('local-merchant.home'))
            ->assertSessionHasNoErrors();
        // 同一会话继续访问登录门内页面 → 证明会话鉴权真的通(EnsureLocalMerchant 放行)
        $this->get('/m/')->assertOk();
        $this->get('/m/edit')->assertOk();

        // ---- 5. 提交编辑(全复审: 只写待审快照, 不碰线上) ----
        $this->post('/m/edit', [
            'name'      => $merchant->name, // 店名不变
            'intro'     => $newIntro,       // 只改简介(避免 address→geocode 外呼)
            'has_offer' => 0,
        ])->assertRedirect(route('local-merchant.home'));

        $change = LocalLifeMerchantChange::where('merchant_id', $merchant->id)
            ->where('status', LocalLifeMerchantChange::STATUS_PENDING)
            ->latest('id')->first();
        $this->assertNotNull($change, '提交后应生成一条待审快照');
        $this->assertSame($newIntro, $change->payload['intro'] ?? null, '待审快照 payload 应含新简介');
        $this->assertSame($originalIntro, $merchant->fresh()->intro, '全复审: 提交后线上应仍为旧值(待审不立即生效)');

        // ---- 6. 超管过审 → 应用到线上 merchant ----
        // 跳过全站通用 admin 网关(license 激活门 array 缓存态失效 / 2FA 门)——与本功能逻辑无关;
        // approve 控制器本身仍走真路由分发 + 真 auth('admin') 解析执行。
        $admin = Admin::where('role_id', 1)->first();
        $this->assertNotNull($admin, '需超管账号(role_id=1)');
        $this->withoutMiddleware([
            \App\Http\Middleware\AdminMiddleware::class,
            \App\Http\Middleware\ActivationCheckMiddleware::class,
        ]);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.local-life.merchant-changes.approve', $change->id))
            ->assertRedirect();
        $this->assertSame(LocalLifeMerchantChange::STATUS_APPROVED, (int) $change->fresh()->status, '审核后快照应为已通过');
        $this->assertSame($newIntro, $merchant->fresh()->intro, '过审后线上 merchant.intro 应更新为新简介');

        // ---- 7. 顾客端 API 反映新值 ----
        $api = $this->getJson('/api/v1/local-life/merchants/' . $merchant->id);
        $api->assertOk();
        $this->assertStringContainsString(
            $newIntro,
            json_encode($api->json(), JSON_UNESCAPED_UNICODE),
            '顾客端商户详情应返回过审后的新简介'
        );

        // 事务在 tearDown 自动回滚 → 顾客端零残留
    }

    public function test_content_screen_blocks_banned_business(): void
    {
        Notification::fake();
        $this->openGate();

        $merchantId = $this->firstVisibleMerchantId();
        $merchant = LocalLifeMerchant::findOrFail($merchantId);

        $email = 'e2e-banned-' . $merchant->id . '@example.test';
        LocalLifeMerchantAccount::where('email', $email)->delete();
        $account = LocalLifeMerchantAccount::create([
            'merchant_id' => $merchant->id, 'email' => $email,
            'contact_name' => 'E2E', 'status' => 1, 'password' => \Illuminate\Support\Facades\Hash::make('E2ePass!2026'),
        ]);

        // 提交命中硬禁业务词 → 应被拦(422), 不生成待审快照
        $before = LocalLifeMerchantChange::where('merchant_id', $merchant->id)->count();
        $this->actingAs($account, 'local_merchant')->post('/m/edit', [
            'name'      => $merchant->name,
            'intro'     => '本店专业换汇 USDT 现金兑换人民币，汇率优惠。',
            'has_offer' => 0,
        ])->assertSessionHasErrors();
        $after = LocalLifeMerchantChange::where('merchant_id', $merchant->id)->count();
        $this->assertSame($before, $after, '命中禁业务词的提交不应生成待审快照');
    }
}
