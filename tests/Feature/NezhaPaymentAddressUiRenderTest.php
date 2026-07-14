<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NezhaPaymentAddressUiRenderTest extends TestCase
{
    public function test_confirmed_admin_and_merchant_partials_render_against_named_routes(): void
    {
        foreach ([
            'admin.restaurant.view',
            'admin.restaurant.payment-address-change.store',
            'admin.restaurant.payment-address-change.approve',
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
}
