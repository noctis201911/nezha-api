<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 哪吒[NZSEC 补充渗透复测 · 回归守卫] — 2026-07-23
 *
 * 对四处访问控制修复做结构化回归断言, 防将来被改回 (与 NezhaImageProxyRetirementTest 同风格,
 * 静态读源文件, 不连库). 语义级"修复真的生效"由实机 before/after PoC + 独立 GATE 审计承担;
 * 本守卫只拦"修复代码被删/改回"这一种回归. 断言为修复原文精确子串, 故在含修复的代码上恒过.
 *
 *   H-1 Vendor/ConversationController::messages()  — conversation_id 分支按 $vendor->id 圈定参与者
 *   H-2 Vendor/OrderController::download()          — 路径白名单 + 当前餐厅订单凭证归属
 *   M-1 Api/V1/OrderController::getPendingReviews() — user_id/is_guest 归属门
 *   M-2 DeliveryManReviewController::submit_review() — attachment.* 含 mimes 白名单
 */
class NezhaNzsecSupplementalHardeningTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = base_path($rel);
        $this->assertFileExists($path, "源文件缺失: $rel");
        return file_get_contents($path);
    }

    /** H-1: 商家会话 messages() 的 conversation_id 分支必须按 vendor 参与者圈定 */
    public function test_h1_vendor_conversation_scopes_by_vendor(): void
    {
        $code = $this->src('app/Http/Controllers/Api/V1/Vendor/ConversationController.php');
        $this->assertStringContainsString(
            "->where('sender_id', \$vendor->id)->orWhere('receiver_id', \$vendor->id)",
            $code,
            'H-1 回归: messages() 的 conversation_id 分支缺少 $vendor->id 参与者圈定 (原越权读全站会话+PII 已复活)'
        );
    }

    /** H-2: download() 必须有路径白名单并按当前餐厅订单及凭证精确圈定 */
    public function test_h2_vendor_download_path_allowlist(): void
    {
        $code = $this->src('app/Http/Controllers/Vendor/OrderController.php');
        $this->assertStringContainsString("str_contains(\$path, '..')", $code, 'H-2 回归: download() 缺少 .. 穿越拦截');
        $this->assertStringContainsString("str_starts_with(\$path, 'public/order/')", $code, 'H-2 回归: download() 缺少 public/order/ 前缀白名单 (原任意路径读他店/系统文件已复活)');
        $this->assertStringContainsString("where('restaurant_id', Helpers::get_restaurant_id())", $code, 'H-2 回归: download() 缺少当前餐厅订单归属门');
        $this->assertStringContainsString('abort_unless($belongsToOrder, 404)', $code, 'H-2 回归: download() 未确认文件属于目标订单');
    }

    /** M-1: getPendingReviews 必须有 user_id + is_guest 归属门 */
    public function test_m1_get_pending_reviews_ownership_gate(): void
    {
        $code = $this->src('app/Http/Controllers/Api/V1/OrderController.php');
        $this->assertStringContainsString('NZSEC M-1', $code, 'M-1 回归: getPendingReviews 归属门标记丢失');
        $this->assertStringContainsString(
            "Order::where(['id' => \$request->order_id, 'user_id' => \$user_id])",
            $code,
            'M-1 回归: getPendingReviews 缺少 user_id 归属查询 (原枚举 order_id 越权读他人订单已复活)'
        );
    }

    /** M-2: 骑手评价上传 attachment.* 必须含 mimes 白名单 */
    public function test_m2_dm_review_upload_mimes(): void
    {
        $code = $this->src('app/Http/Controllers/Api/V1/DeliveryManReviewController.php');
        $this->assertStringContainsString(
            "'attachment.*' => 'nullable|image|mimes:png,jpg,jpeg,webp,gif|max:2048'",
            $code,
            'M-2 回归: submit_review 的 attachment.* 缺少 mimes 白名单 (原绕 validateFile 致存储型 XSS 已复活)'
        );
    }
}
