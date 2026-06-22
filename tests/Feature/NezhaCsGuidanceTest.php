<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaCsClassifier;
use Tests\TestCase;

/**
 * 哪吒[在场感知 · 死指引墙]
 *
 * 客服话术里凡是"让顾客回复『X』触发某模式"的关键词，X 必须真在对应触发词表里——
 * 否则就是"死指引"：话术指引顾客这么回，系统却触发不了，把人困在原地。
 *
 * 这次真出过：英文确认提示让顾客回 "talk to rider" 进翻译模式，但 enterXlate 当时只有中文，
 * "talk to rider" 触发不了；进模式回复里写 Say "exit translation"，exitXlate 也只有中文。
 *
 * 🔴 维护：以后在任何客服回复里新增"请回复『X』""just reply X""Say \"X\""之类指引，
 *   就把 X 加到下面对应清单，这道墙才保得住。
 * 局限(诚实)：这是"显式配对墙"，只保下面已登记的指引词，不自动扫源码；
 *   语气/语言/流程是否到位属判断类，墙不住，靠 CLAUDE.md「在场感知自检」牌兜。
 */
class NezhaCsGuidanceTest extends TestCase
{
    /** 话术里指引顾客"进入翻译模式"用的词（中/英都在回复里出现过），必须真能进。 */
    public function test_enter_translate_guidance_keywords_are_live(): void
    {
        foreach (['和骑手对话', 'talk to rider'] as $kw) {
            $this->assertTrue(
                NezhaCsClassifier::isEnterTranslateMode($kw),
                "进翻译模式指引词「{$kw}」未注册 enterXlate = 死指引（话术让顾客这么回却触发不了）"
            );
        }
    }

    /** 进模式回复里写的"退出"指引词（退出翻译 / exit translation），必须真能退。 */
    public function test_exit_translate_guidance_keywords_are_live(): void
    {
        foreach (['退出翻译', 'exit translation'] as $kw) {
            $this->assertTrue(
                NezhaCsClassifier::isExitTranslateMode($kw),
                "退翻译模式指引词「{$kw}」未注册 exitXlate = 死指引"
            );
        }
    }
}
