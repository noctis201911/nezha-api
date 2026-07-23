<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 哪吒[异常串外泄 · 回归钉 2026-07-23]
 *
 * 由来：`/api/v1/auth/login`(social) 两处 catch 直接把 `$e->getMessage()` 塞进 403 响应。
 * 生产实测会原样吐回 `…/tokeninfo?id_token=<调用方传的token>` 加上游错误体；users.email 唯一索引
 * 上线后，并发注册撞车还会把 QueryException 内联的 bindings(顾客真名/temp_token/social_id/邮箱)
 * 与 Host/Port/Database 一并吐给未认证调用方。已修(af31c479)，本文件钉住不许回退。
 *
 * 🔴 这不是一道"墙"，是一颗**钉子**。诚实说明它现在的地位：
 *   截至 2026-07-23，`.git/hooks/pre-push` 只跑 NezhaCsGuidance / NezhaIdorGuard /
 *   NezhaRiskChannel / NezhaTieredCouponParity 四个；`composer test:redlines` 也不含本文件；
 *   仓库无 CI。**所以本文件目前只有人工执行 phpunit 时才跑，拦不住任何人 push。**
 *   要它真成为墙，得把它加进 pre-push 的清单 —— 那会影响所有窗口，须先在 CLAIMS.md 打招呼。
 *
 * 设计取舍（上一版的教训）：初版维护的是"还没修完的清单"，写完当天就被别窗的修复跑赢而失效，
 * 且过时的配额会把刚清干净的文件重新放开。现在只维护两个**不随别窗进度腐坏**的集合：
 *   - CLEANED_FILES：已经清干净的，必须保持 0。别窗修得越多这个集合只增不减。
 *   - AUDITED_SAFE：逐行读过、确认不是泄漏的，处数必须**恰好等于**审计时的值；
 *     多一处少一处都红，逼人重新审一遍（防止"白名单一挂就永久失明"）。
 *
 * 局限(诚实)：
 *  - 文本扫描，不是运行时验证。`$msg = $e->getMessage(); ... json([...$msg])` 这种绕一道变量的写法看不出来。
 *  - 只扫 app/Http/Controllers/Api/，不扫 Services / 中间件 / 全局异常 handler。
 *    全局 handler 仍会把 QueryException 原始串(含邮箱)写进明文 laravel.log —— 那是另一个洞，
 *    这颗钉子管不着，别因为它绿了就以为整类问题关闭了。
 */
class NezhaApiExceptionLeakContractTest extends TestCase
{
    /** 已清干净、必须保持 0 的文件。只增不减；后续别人修好一个就往这里加一行。 */
    private const CLEANED_FILES = [
        'app/Http/Controllers/Api/V1/Auth/CustomerAuthController.php',
        'app/Http/Controllers/Api/V1/CampaignController.php',
        'app/Http/Controllers/Api/V1/CategoryController.php',
        'app/Http/Controllers/Api/V1/ProductController.php',
    ];

    /**
     * 逐行读过、确认**不是**泄漏的位置，值 = 审计当时的处数，必须恰好相等。
     *  - TelegramAuthController / EmailAuthController：catch 的是应用自定义异常
     *    (TelegramLoginException / EmailLoginException)，message 全是 app 写死的字面量；
     *    两者的 Throwable 兜底分支都只记 $error::class、只返固定文案。
     *  - Refund 两个控制器：$e->getMessage() 是**领域错误码**('refund_record_not_found' 之类)，
     *    用来查 $messages[] 中文表；domainError(\DomainException $e) 是类型限定参数，
     *    调用点也都 catch (\DomainException)，QueryException 继承 PDOException 进不来。
     * 🔴 数字变了就是有人在这些文件里新增了 getMessage() 回客户端 —— 重新逐行审，别直接改数字。
     */
    private const AUDITED_SAFE = [
        'app/Http/Controllers/Api/V1/Auth/TelegramAuthController.php' => 1,
        'app/Http/Controllers/Api/V1/Auth/EmailAuthController.php' => 1,
        // 这两个扫描器本来就数不到(它们的 getMessage() 只出现在查表/三元判断行, 不是客户端出参行);
        // 期望值写 0 而不是把它们移出清单, 这样以后有人往 L1 退款路径新加客户端出参照样会红。
        'app/Http/Controllers/Api/V1/RefundAddressCredentialController.php' => 0,
        'app/Http/Controllers/Api/V1/RefundReconfirmationController.php' => 0,
    ];

    public function test_cleaned_files_stay_clean(): void
    {
        foreach (self::CLEANED_FILES as $file) {
            $path = $this->repoPath($file);
            $this->assertFileExists($path, "{$file} 不在了 —— 请同步更新 CLEANED_FILES");
            $this->assertSame(
                0,
                $this->countClientBoundLeaks(file_get_contents($path)),
                "{$file} 又出现把原始异常串回给客户端的写法（曾清干净过，这是回归）"
            );
        }
    }

    /** 修法本身也钉住：结构化日志字段还在，别被人改回记原始 message。 */
    public function test_social_login_keeps_structured_logging(): void
    {
        $src = file_get_contents($this->repoPath('app/Http/Controllers/Api/V1/Auth/CustomerAuthController.php'));

        $this->assertStringContainsString('nz_social_token_exchange_failed', $src);
        $this->assertStringContainsString('nz_social_login_user_save_failed', $src);
    }

    public function test_audited_safe_files_did_not_grow(): void
    {
        foreach (self::AUDITED_SAFE as $file => $expected) {
            $path = $this->repoPath($file);
            $this->assertFileExists($path, "{$file} 不在了 —— 请同步更新 AUDITED_SAFE");
            $this->assertSame(
                $expected,
                $this->countClientBoundLeaks(file_get_contents($path)),
                "{$file} 里 getMessage() 回客户端的处数变了（白名单是按「逐行审过」给的，"
                . '数量一变就得重新审，不要直接改这个数字）'
            );
        }
    }

    /**
     * 数“把异常对象的 message 送去客户端”的行。
     * 与上一版的区别：不再因为同一行出现 Log::/info() 就整行跳过 —— 本仓库大量使用单行 catch
     * (`catch(\Exception $e){ info($e->getMessage()); return response()->json([...],403); }`)，
     * 那样会漏数；改成"该行同时有 return/abort 就仍然算"。另外补上 abort(403, $e->getMessage())。
     */
    private function countClientBoundLeaks(string $src): int
    {
        $count = 0;

        foreach (explode("\n", $src) as $line) {
            if (! str_contains($line, 'getMessage()')) {
                continue;
            }

            $clientBound = str_contains($line, 'json(')
                || str_contains($line, 'abort(')
                || str_contains($line, "'message'")
                || str_contains($line, '"message"');

            if (! $clientBound) {
                continue;
            }

            $logOnly = preg_match('/\b(Log::|info\(|logger\(|report\()/', $line)
                && ! str_contains($line, 'return')
                && ! str_contains($line, 'abort(');

            if ($logOnly) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    private function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    }
}
