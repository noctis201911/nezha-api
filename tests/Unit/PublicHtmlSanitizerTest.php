<?php

namespace Tests\Unit;

use App\Support\PublicHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicHtmlSanitizerTest extends TestCase
{
    #[DataProvider('policySettingKeys')]
    public function test_it_preserves_only_the_formatting_used_by_each_current_public_policy(string $key): void
    {
        $html = '<p style="color:#9a9a9a;font-size:13px;margin:0 0 18px;">Intro</p><h3>Title</h3><strong>Text</strong><ul><li>Item</li></ul>';

        $clean = PublicHtmlSanitizer::cleanSetting($key, $html);

        $this->assertStringContainsString('color:#9a9a9a', $clean);
        $this->assertStringContainsString('font-size:13px', $clean);
        $this->assertStringContainsString('margin:0 0 18px', $clean);
        $this->assertStringContainsString('<p', $clean);
        $this->assertStringContainsString('<h3>Title</h3>', $clean);
        $this->assertStringContainsString('<strong>Text</strong>', $clean);
        $this->assertStringContainsString('<ul>', $clean);
        $this->assertStringContainsString('<li>Item</li>', $clean);
    }

    #[DataProvider('policySettingKeys')]
    public function test_it_removes_all_scriptable_markup_from_each_public_policy(string $key): void
    {
        $html = <<<'HTML'
<p onclick="alert(1)" onload="alert(2)" style="color:#333;background-image:url(https://attacker.test/x);position:fixed">Safe</p>
<script>alert(3)</script><img src=x onerror="alert(4)"><svg onload="alert(5)"><circle /></svg>
<iframe src="https://attacker.test"></iframe><object data="https://attacker.test/x"></object><embed src="https://attacker.test/x">
<a href="javascript:alert(6)">javascript link</a><a href="data:text/html,&lt;script&gt;alert(7)&lt;/script&gt;">data link</a>
HTML;

        $clean = PublicHtmlSanitizer::cleanSetting($key, $html);
        $normalized = strtolower($clean);

        $this->assertStringContainsString('Safe', $clean);
        foreach ([
            '<script',
            '<img',
            '<svg',
            '<iframe',
            '<object',
            '<embed',
            'onclick',
            'onerror',
            'onload',
            'javascript:',
            'data:text/html',
            'background-image',
            'position:',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $normalized, $key);
        }
    }

    #[DataProvider('policySettingKeys')]
    public function test_it_rejects_markup_outside_the_minimal_allowlist_for_each_public_policy(string $key): void
    {
        $html = '<a href="https://nezha.am">link</a><table><tr><td>cell</td></tr></table><div><span>span</span><br><b>bold</b><em>emphasis</em></div><h3 style="color:#fff">Heading</h3>';

        $clean = strtolower(PublicHtmlSanitizer::cleanSetting($key, $html));

        foreach (['<a', '<table', '<tr', '<td', '<div', '<span', '<br', '<b', '<em', 'href=', '<h3 style='] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $clean, $key);
        }
        $this->assertStringContainsString('link', $clean);
        $this->assertStringContainsString('cell', $clean);
        $this->assertStringContainsString('<h3>heading</h3>', $clean);
    }

    public function test_it_does_not_change_unrelated_settings(): void
    {
        $value = '<script>internal-value</script>';

        $this->assertSame(
            $value,
            PublicHtmlSanitizer::cleanSetting('unrelated_setting', $value)
        );
    }

    public static function policySettingKeys(): array
    {
        return [
            'about us' => ['about_us'],
            'cancellation policy' => ['cancellation_policy'],
            'privacy policy' => ['privacy_policy'],
            'refund policy' => ['refund_policy'],
            'shipping policy' => ['shipping_policy'],
            'terms and conditions' => ['terms_and_conditions'],
        ];
    }
}
