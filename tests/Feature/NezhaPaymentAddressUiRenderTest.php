<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\NezhaPaymentAddressChange;
use App\Models\Restaurant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NezhaPaymentAddressUiRenderTest extends TestCase
{
    public function test_reviewer_queue_renders_from_the_formal_page_source(): void
    {
        $restaurant = new Restaurant();
        $restaurant->forceFill(['id' => 12, 'name' => '亚美零食槟榔']);
        $requester = new Admin();
        $requester->forceFill([
            'id' => 41,
            'f_name' => '林',
            'l_name' => '管理员',
            'email' => 'manager@example.test',
        ]);
        $change = new NezhaPaymentAddressChange();
        $change->forceFill([
            'id' => 18,
            'public_id' => '24071401-8000-4000-8000-000000000018',
            'restaurant_id' => 12,
            'network' => 'TRC20',
            'state' => 'pending_distinct_admin',
            'new_fingerprint' => str_repeat('b', 64),
            'requested_by_admin_id' => 41,
            'merchant_confirmed_at' => Carbon::parse('2026-07-14 12:10:00'),
            'expires_at' => Carbon::parse('2026-07-15 12:00:00'),
        ]);
        $change->setRelation('restaurant', $restaurant);
        $change->setRelation('requestedByAdmin', $requester);

        $source = file_get_contents(resource_path(
            'views/admin-views/payment-address-review/index.blade.php'
        ));
        preg_match("/@push\('css_or_js'\)(.*?)@endpush/s", $source, $cssMatch);
        preg_match("/@section\('content'\)(.*?)@endsection/s", $source, $contentMatch);
        preg_match("/@push\('script_2'\)(.*?)@endpush/s", $source, $scriptMatch);
        $this->assertCount(2, $cssMatch);
        $this->assertCount(2, $contentMatch);
        $this->assertCount(2, $scriptMatch);

        $html = Blade::render($contentMatch[1], ['changes' => collect([$change])]);
        $this->assertStringContainsString('data-payment-address-review="reviewer-v2"', $html);
        $this->assertStringContainsString('亚美零食槟榔', $html);
        $this->assertStringContainsString('驳回原因', $html);
        $this->assertStringContainsString('（选填）', $html);
        $this->assertStringContainsString('之后的新付款立即使用新地址', $html);
        $this->assertStringContainsString('已签发的旧地址凭据只保留到各自到期', $html);
        $this->assertStringNotContainsString('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', $html);

        if ($previewDir = getenv('NEZHA_REVIEW_UI_PREVIEW_DIR')) {
            if (! is_dir($previewDir)) {
                mkdir($previewDir, 0700, true);
            }
            file_put_contents(
                $previewDir.'/reviewer-queue.html',
                $this->reviewerPreviewDocument($cssMatch[1], $html, $scriptMatch[1])
            );
        }
    }

    public function test_confirmed_admin_and_merchant_partials_render_against_named_routes(): void
    {
        foreach ([
            'admin.restaurant.view',
            'admin.restaurant.payment-address-change.store',
            'admin.restaurant.payment-address-change.approve',
            'admin.restaurant.payment-address-change.reject',
            'admin.restaurant.payment-address-change.cancel',
            'admin.restaurant.payment-address-change.pause',
            'vendor.payment-address-change.confirm',
            'vendor.payment-address-change.reject',
        ] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }

        $restaurant = (object) ['id' => 12];
        $state = (object) [
            'state' => 'active',
            'active_version' => 12,
            'pending_change_id' => 18,
        ];
        $change = (object) [
            'id' => 18,
            'public_id' => '24071401-8000-4000-8000-000000000018',
            'restaurant_id' => 12,
            'network' => 'TRC20',
            'state' => 'pending_distinct_admin',
            'old_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'new_address' => 'TJnwC8FcWiJQCQFzTYHxCj4DSW2iGwESVf',
            'old_fingerprint' => str_repeat('a', 64),
            'new_fingerprint' => str_repeat('b', 64),
            'expected_version' => 12,
            'requested_by_admin_id' => 1,
            'merchant_confirmed_at' => Carbon::parse('2026-07-14 12:10:00'),
            'approved_at' => null,
            'drain_until' => null,
            'expires_at' => Carbon::parse('2026-07-15 12:00:00'),
        ];
        $security = [
            'enabled' => true,
            'storage_ready' => true,
            'networks' => collect([
                'TRC20' => [
                    'network' => 'TRC20',
                    'address' => $change->old_address,
                    'configured' => true,
                    'valid' => true,
                    'validation_error' => null,
                    'fingerprint' => $change->old_fingerprint,
                    'state' => $state,
                    'pending' => $change,
                    'requestable' => false,
                ],
                'BEP20' => [
                    'network' => 'BEP20',
                    'address' => '',
                    'configured' => false,
                    'valid' => false,
                    'validation_error' => 'empty_address',
                    'fingerprint' => null,
                    'state' => null,
                    'pending' => null,
                    'requestable' => false,
                ],
            ]),
            'open_changes' => collect([$change]),
            'review' => $change,
            'totp_admin_count' => 1,
            'reviewer_can_approve' => false,
            'current_admin_id' => 1,
        ];

        $adminHtml = view(
            'admin-views.vendor.view.partials._payment-address-security',
            compact('restaurant', 'security')
        )->render();
        $this->assertStringContainsString('data-payment-address-security="admin-a"', $adminHtml);
        $this->assertStringContainsString('data-payment-address-review-drawer="admin-c"', $adminHtml);
        $this->assertStringContainsString('当前账号是申请人，不能自批', $adminHtml);
        $this->assertStringContainsString('批准后立即切换新付款地址', $adminHtml);
        $this->assertStringContainsString('普通换址不暂停新付款，也不延长旧凭据', $adminHtml);
        $this->assertStringContainsString($change->old_address, $adminHtml);
        $this->assertStringContainsString($change->new_address, $adminHtml);
        $adminMainSecurity = $security;
        $adminMainSecurity['review'] = null;
        $adminMainHtml = view(
            'admin-views.vendor.view.partials._payment-address-security',
            ['restaurant' => $restaurant, 'security' => $adminMainSecurity]
        )->render();

        $notification = (object) [
            'data' => [
                'type' => 'nezha_payment_address_security',
                'data_id' => 'resolved-change',
                'title' => '新收款地址已生效',
                'description' => '请登录商家后台核对当前完整地址。',
            ],
            'created_at' => '2026-07-14 12:20:00',
        ];
        $merchantChange = clone $change;
        $merchantChange->state = 'pending_merchant';
        $merchantChange->merchant_confirmed_at = null;
        $security = [
            'enabled' => true,
            'storage_ready' => true,
            'is_owner' => true,
            'open_changes' => collect([$merchantChange]),
            'notifications' => collect([$notification]),
        ];
        $viewedSecurityNotifications = 1;
        $merchantHtml = view(
            'vendor-views.wallet-method.partials._payment-address-change',
            compact('security', 'viewedSecurityNotifications')
        )->render();
        $this->assertStringContainsString('data-payment-address-security="merchant-a"', $merchantHtml);
        $this->assertStringContainsString('完整地址属于我，确认并继续', $merchantHtml);
        $this->assertStringContainsString('新收款地址已生效', $merchantHtml);
        $this->assertStringContainsString('本页已查看', $merchantHtml);

        if ($previewDir = getenv('NEZHA_UI_PREVIEW_DIR')) {
            if (! is_dir($previewDir)) {
                mkdir($previewDir, 0700, true);
            }
            file_put_contents(
                $previewDir.'/admin-main.html',
                $this->previewDocument('管理员 · A 主页面', $adminMainHtml, 'admin')
            );
            file_put_contents(
                $previewDir.'/admin-drawer.html',
                $this->previewDocument('管理员 · C 复核抽屉', $adminHtml, 'admin')
            );
            file_put_contents(
                $previewDir.'/merchant.html',
                $this->previewDocument('商家 owner · 地址核对', $merchantHtml, 'merchant')
            );
        }
    }

    private function previewDocument(string $title, string $body, string $surface): string
    {
        $side = $surface === 'admin'
            ? '今天<br>数据看板<br>订单<br>风控中心<br><strong>商家列表</strong><br>商家反馈<br>菜系<br>分类<br>商品'
            : '控制面板<br>订单管理<br>钱包<br>消息<br>员工<br>业务配置<br><strong>我的收款方式</strong><br>通知设置';
        $heading = $surface === 'admin' ? '亚美零食槟榔 · 收款信息' : '我的收款方式';

        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title>'
            .'<link rel="stylesheet" href="/assets/admin/css/vendor.min.css">'
            .'<link rel="stylesheet" href="/assets/admin/vendor/icon-set/style.css">'
            .'<link rel="stylesheet" href="/assets/admin/css/bootstrap.min.css">'
            .'<link rel="stylesheet" href="/assets/admin/css/theme.minc619.css?v=1.0">'
            .'<link rel="stylesheet" href="/assets/admin/css/style.css">'
            .'<style>body{background:#f7f8fa}.nz-preview-shell{display:grid;grid-template-columns:230px 1fr;min-height:100vh}'
            .'.nz-preview-side{background:#fff;border-right:1px solid #e7eaf3;padding:24px;line-height:2.7;color:#667085}'
            .'.nz-preview-main{min-width:0}.nz-preview-top{height:64px;background:#fff;border-bottom:1px solid #e7eaf3;padding:18px 28px;font-weight:700}'
            .'.nz-preview-content{max-width:1180px;margin:0 auto;padding:26px}.nz-preview-tabs{background:#fff;padding:12px 16px;border-radius:8px;margin-bottom:18px;color:#667085}'
            .'@media(max-width:767px){.nz-preview-shell{display:block}.nz-preview-side{display:none}.nz-preview-content{padding:14px}.nz-preview-top{padding:18px}}</style>'
            .'</head><body><div class="nz-preview-shell"><aside class="nz-preview-side"><h4>哪吒外卖</h4>'.$side.'</aside>'
            .'<main class="nz-preview-main"><header class="nz-preview-top">'.$heading.'</header><div class="nz-preview-content">'
            .'<div class="nz-preview-tabs">总览　订单　商品　设置　<strong>收款信息</strong>　通知　结算记录</div>'
            .'<div class="alert alert-info"><strong>哪吒外卖收款方式：</strong>顾客付款直接进入商家本人账户，平台不经手资金。</div>'
            .$body.'</div></main></div></body></html>';
    }

    private function reviewerPreviewDocument(string $css, string $body, string $script): string
    {
        $detail = json_encode([
            'change_id' => '24071401-8000-4000-8000-000000000018',
            'restaurant_id' => 12,
            'restaurant_name' => '亚美零食槟榔',
            'network' => 'TRC20',
            'state' => 'pending_distinct_admin',
            'old_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'new_address' => 'TJnwC8FcWiJQCQFzTYHxCj4DSW2iGwESVf',
            'old_fingerprint' => str_repeat('a', 64),
            'new_fingerprint' => str_repeat('b', 64),
            'requested_by_admin_id' => 41,
            'requested_by_admin_name' => '林 管理员',
            'merchant_confirmed_at' => '2026-07-14T12:10:00+02:00',
            'expires_at' => '2026-07-15T12:00:00+02:00',
            'approve_url' => '#preview-approved',
            'reject_url' => '#preview-rejected',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>哪吒总后台 · 收款地址复核 V2</title>'
            .'<link rel="stylesheet" href="https://api.nezha.am/assets/admin/css/vendor.min.css">'
            .'<link rel="stylesheet" href="https://api.nezha.am/assets/admin/vendor/icon-set/style.css">'
            .'<link rel="stylesheet" href="https://api.nezha.am/assets/admin/css/custom.css">'
            .'<link rel="stylesheet" href="https://api.nezha.am/assets/admin/css/bootstrap.min.css">'
            .'<link rel="stylesheet" href="https://api.nezha.am/assets/admin/css/theme.minc619.css?v=1.0">'
            .'<link rel="stylesheet" href="https://api.nezha.am/assets/admin/css/style.css">'
            .$css
            .'<style>body{background:#f7f8fa;color:#1a2233}.nz-preview-side{position:fixed;inset:0 auto 0 0;width:260px;background:#334257;color:#fff;z-index:1030}'
            .'.nz-preview-brand{height:68px;background:#fff;color:#102a4c;display:flex;align-items:center;padding:0 24px;font-size:20px;font-weight:800}'
            .'.nz-preview-nav{padding:24px 18px}.nz-preview-nav small{color:#aeb8c6;display:block;padding:0 12px 10px}.nz-preview-nav a{display:flex;gap:12px;align-items:center;background:#102a4c;color:#fff;border-radius:7px;padding:12px 14px;text-decoration:none}'
            .'.nz-preview-top{position:fixed;left:260px;right:0;top:0;height:68px;background:#fff;border-bottom:1px solid #e7eaf0;z-index:1020;display:flex;align-items:center;justify-content:flex-end;padding:0 32px;gap:18px}'
            .'.nz-preview-main{margin-left:260px;padding-top:68px;min-height:100vh}.nz-preview-env{background:#102a4c;color:#fff;border-radius:6px;padding:5px 11px;font-size:12px;font-weight:600}'
            .'@media(max-width:767px){.nz-preview-side{display:none}.nz-preview-top{left:0;padding:0 14px}.nz-preview-main{margin-left:0}}</style>'
            .'</head><body><aside class="nz-preview-side"><div class="nz-preview-brand">哪吒外卖</div><nav class="nz-preview-nav"><small>钱·风控</small><a href="#"><i class="tio-shield"></i><span>收款地址复核</span></a></nav></aside>'
            .'<header class="nz-preview-top"><span class="nz-preview-env">STAGING</span><span class="badge badge-soft-info">独立复核</span><strong>林 复核员</strong></header>'
            .'<main class="nz-preview-main">'.$body.'</main>'
            .'<script src="https://api.nezha.am/assets/admin/js/vendor.min.js"></script>'
            .'<script src="https://api.nezha.am/assets/admin/js/sweet_alert.js"></script>'
            .$script
            .'<script>window.fetch=function(){return Promise.resolve({ok:true,json:function(){return Promise.resolve('.$detail.');}})};'
            .'window.Swal=window.Swal||{};window.Swal.fire=function(){return Promise.resolve({value:false})};</script>'
            .'</body></html>';
    }
}
