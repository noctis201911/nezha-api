<?php

namespace Tests\Unit;

use App\Support\PublicHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class PublicHtmlSanitizerTest extends TestCase
{
    public function test_it_preserves_the_formatting_used_by_current_public_policies(): void
    {
        $html = '<p style="color:#9a9a9a;font-size:13px;margin:0">Intro</p><h3>Title</h3><strong>Text</strong><ul><li>Item</li></ul>';

        $clean = PublicHtmlSanitizer::cleanSetting('privacy_policy', $html);

        $this->assertStringContainsString('color:#9a9a9a', $clean);
        $this->assertStringContainsString('font-size:13px', $clean);
        $this->assertStringContainsString('<h3>Title</h3>', $clean);
        $this->assertStringContainsString('<strong>Text</strong>', $clean);
        $this->assertStringContainsString('<li>Item</li>', $clean);
    }

    public function test_it_removes_scriptable_markup_attributes_protocols_and_css(): void
    {
        $html = <<<'HTML'
<p onclick="alert(1)" style="color:#333;background-image:url(https://attacker.test/x);position:fixed">Safe</p>
<script>alert(1)</script><img src=x onerror="alert(2)"><iframe src="https://attacker.test"></iframe>
<a href="javascript:alert(3)" title="bad">bad link</a><a href="https://nezha.am/help">good link</a>
HTML;

        $clean = PublicHtmlSanitizer::cleanSetting('terms_and_conditions', $html);

        $this->assertStringContainsString('Safe', $clean);
        $this->assertStringContainsString('https://nezha.am/help', $clean);
        foreach (['<script', '<img', '<iframe', 'onclick', 'onerror', 'javascript:', 'background-image', 'position:'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $clean);
        }
    }

    public function test_it_does_not_change_unrelated_settings(): void
    {
        $value = '<script>internal-value</script>';

        $this->assertSame(
            $value,
            PublicHtmlSanitizer::cleanSetting('unrelated_setting', $value)
        );
    }
}
