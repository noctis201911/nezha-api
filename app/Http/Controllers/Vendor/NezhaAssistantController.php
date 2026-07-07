<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaCsAssistant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 哪吒 商家助手：商家后台的 AI 问答（怎么上传/改菜品/排版/功能怎么用）。
 * 白名单护栏在 NezhaCsAssistant::merchantAssistant（绝不提 StackFood、没列的功能不瞎答）。
 *
 * Phase 1「会动手」能力：暂停 / 恢复接单。
 * 🔴 安全设计：AI（LLM）只负责"识别意图 + 生成话术"，**绝不直接触发写操作**。
 *   意图识别是本文件里确定性的关键词匹配（detectStoreCommand），命中后只"提议 + 出确认按钮"；
 *   真正翻店铺状态在 applyStoreStatus()，由商家点确认后走本控制器（auth('vendor') 中间件 + 绑定本店），
 *   所以提示注入无法凭一句话把店关掉（要人点确认、且端点只作用于已登录商家自己的店）。动作请求不进 Q&A 缓存。
 */
class NezhaAssistantController extends Controller
{
    public function index()
    {
        return view('vendor-views.assistant.index');
    }

    public function ask(Request $request)
    {
        // ① 确认执行分支：来自答案下方"确认"按钮，直接执行动作（不经 AI）。
        if ($request->filled('confirm_action')) {
            $action = $request->input('confirm_action');
            if (!in_array($action, ['pause', 'resume', 'feedback'], true)) {
                return back()->with('ma_a', '这个操作我没看懂，请重试。');
            }
            $vendorId = Helpers::get_vendor_id();
            $akey = 'nezha-assistant-act:' . ($vendorId ?: $request->ip());
            if (RateLimiter::tooManyAttempts($akey, 20)) {
                return back()->with('ma_a', '操作太频繁了，请稍后再试～');
            }
            RateLimiter::hit($akey, 60);
            $result = $action === 'feedback' ? $this->applyFeedback() : $this->applyStoreStatus($action);
            return back()->with('ma_a', $result);
        }

        // ② 普通提问 / 动作意图
        $request->validate(['question' => 'required|string|max:500']);
        $q = trim($request->question);

        // 哪吒: 单商家限速 30 次/分钟, 防连点刷 DeepSeek 烧平台 token (员工与店主共享同一桶 = 按店限)
        $vendorId = Helpers::get_vendor_id();
        $key = 'nezha-assistant-ask:' . ($vendorId ?: $request->ip());
        if (RateLimiter::tooManyAttempts($key, 30)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('ma_q', $q)->with('ma_a', '您问得太快啦，请 ' . $seconds . ' 秒后再试～');
        }
        RateLimiter::hit($key, 60); // 60 秒滑动窗口

        // Phase 1 动作意图：暂停 / 恢复接单（命令式，非"怎么…"提问）→ 先确认再执行
        $cmd = $this->detectStoreCommand($q);
        if ($cmd) {
            $restaurant = Helpers::get_restaurant_data();
            $closed = $restaurant ? (int) $restaurant->nezha_temp_closed : 0;
            if ($cmd === 'pause' && $closed === 1) {
                return back()->with('ma_q', $q)->with('ma_a', '您的店当前已经是【暂停接单】了，顾客暂时下不了单。想恢复接单，跟我说「恢复接单」就行。');
            }
            if ($cmd === 'resume' && $closed === 0) {
                return back()->with('ma_q', $q)->with('ma_a', '您的店当前就是【营业中】、正常接单呢，无需恢复。');
            }
            $tip = $cmd === 'pause'
                ? '您是要把店铺改成【暂停接单】吗？改后顾客暂时下不了单，进行中的订单不受影响。确认请点下方按钮。'
                : '您是要把店铺改成【营业中·恢复接单】吗？确认请点下方按钮。';
            return back()->with('ma_q', $q)->with('ma_a', $tip)->with('ma_action', $cmd);
        }

        // Phase 2 动作意图：把商家的话整理成一条「问题反馈」交给平台超管（先给商家看草稿→确认→提交）
        if ($this->detectFeedbackIntent($q)) {
            $draft = NezhaCsAssistant::draftMerchantFeedback($q);
            session()->put('ma_fb_draft', $draft);
            $typeLabel = \App\Models\VendorFeedback::TYPE_LABELS[$draft['type']] ?? $draft['type'];
            $preview = "我按您说的整理了一条反馈，确认后提交给平台超管：\n\n【类型】{$typeLabel}\n【主题】{$draft['subject']}\n【详情】{$draft['description']}\n\n没问题就点下方「确认提交给平台」；想补充或改，直接把要说的再发我一遍。";
            return back()->with('ma_q', $q)->with('ma_a', $preview)->with('ma_action', 'feedback');
        }

        $answer = NezhaCsAssistant::merchantAssistant($q);
        return back()->with('ma_q', $q)->with('ma_a', $answer);
    }

    /**
     * 识别"暂停 / 恢复接单"命令（确定性关键词，不经 LLM）。返回 'pause' | 'resume' | null。
     * 只认命令式；"怎么暂停接单""暂停会怎样"这类提问 → 交给 Q&A。误判也无害（要人点确认才执行）。
     */
    private function detectStoreCommand(string $q): ?string
    {
        $s = preg_replace('/\s+/u', '', $q);
        // 信息 / 怎么做类提问 → 不当命令
        if (preg_match('/怎么|怎样|如何|咋|在哪|哪里|哪儿|为什么|什么意思|意思是|会不会|收费|影响|多久|几点|能撑|多少钱/u', $s)) {
            return null;
        }
        // 恢复接单（先判，更具体）
        if (preg_match('/(恢复|开始|继续|重新|重开|打开|开启).{0,3}(接单|营业)|可以接单了|恢复正常|开张营业|营业了|重新开门/u', $s)) {
            return 'resume';
        }
        // 暂停接单
        if (preg_match('/(暂停|停止|停|关掉|关闭|先别|别|不想?|停一?下).{0,3}接单|暂停营业|临时关店|临时打烊|太忙.{0,10}(接单|营业|单)|忙不过来|做不过来|爆单|来不及做/u', $s)) {
            return 'pause';
        }
        return null;
    }

    /**
     * 执行店铺营业状态切换（Set 到明确状态，不盲翻），复用门店页/作业台同源写路径的语义。
     * 🔴 绑定已登录商家自己的店（Helpers::get_restaurant_data），绝不接受外部传入的店铺 id。
     */
    private function applyStoreStatus(string $action): string
    {
        $restaurant = Helpers::get_restaurant_data();
        if (!$restaurant) {
            return '没找到您的店铺信息，请刷新后重试或联系平台。';
        }
        $want = $action === 'pause' ? 1 : 0;
        $restaurant->nezha_temp_closed = $want;
        if ($want) {
            $restaurant->active = 1; // 暂停时保持店铺可见(顾客端显"休息中")、不消失，与门店页开关一致
        }
        $restaurant->save();
        \Illuminate\Support\Facades\Log::info('nezha_store_status_toggle', [
            'restaurant_id'     => $restaurant->id,
            'by'                => optional(auth('vendor')->user())->id,
            'nezha_temp_closed' => (int) $want,
            'action'            => $want ? 'pause' : 'open',
            'via'               => 'assistant',
            'at'                => now()->toIso8601String(),
        ]);
        // 回读真实状态（直接查库）再回复，不凭内存值谎报"改好了"
        $now = (int) \Illuminate\Support\Facades\DB::table('restaurants')->where('id', $restaurant->id)->value('nezha_temp_closed');
        if ($now !== $want) {
            return '⚠️ 没能改成功，请到「商家配置 → 营业状态」手动切换，或稍后再试。';
        }
        return $want
            ? '✅ 已帮您把店铺改成【暂停接单】。顾客现在下不了单（进行中的订单不受影响）。想恢复，跟我说一声「恢复接单」就行。'
            : '✅ 已帮您把店铺改成【营业中】，正常接单啦～';
    }

    /** 识别"帮我反馈给平台/超管、投诉、联系超管"类升级诉求（确定性关键词）。误判无害（要人点确认才提交）。 */
    private function detectFeedbackIntent(string $q): bool
    {
        $s = preg_replace('/\s+/u', '', $q);
        if (preg_match('/怎么|如何|怎样|在哪|哪里|什么意思/u', $s)) {
            return false; // "怎么提交反馈" 这类是提问 → 交给 Q&A
        }
        return (bool) preg_match('/联系超管|联系平台|联系官方客服|找超管|找平台|向平台(反映|反馈|报告|投诉|说明)|反馈给(平台|超管)|帮我(写|提交|发|弄).{0,4}(反馈|投诉)|(提交|写|发).{0,3}(问题)?反馈|问题反馈|让(平台|超管)(介入|处理)|需要(平台|超管)(介入|处理)|上报(平台|超管)|投诉.{0,12}(平台|超管|官方)|(给|向)(平台|超管|官方).{0,4}投诉/u', $s);
    }

    /**
     * 提交草稿好的问题反馈给平台（逻辑同 FeedbackController::store，🔴绑定本商家 get_vendor_data）。
     * 草稿放 session ma_fb_draft（服务端，防客户端篡改），提交后清除。
     */
    private function applyFeedback(): string
    {
        $draft = session('ma_fb_draft');
        session()->forget('ma_fb_draft');
        if (!is_array($draft) || empty($draft['subject']) || empty($draft['description'])) {
            return '没找到要提交的反馈内容，麻烦把您的问题再说一遍。';
        }
        $type = in_array(($draft['type'] ?? 'other'), ['commission', 'settlement', 'feature', 'other'], true) ? $draft['type'] : 'other';
        $vendor = Helpers::get_vendor_data();
        if (!$vendor) {
            return '没找到您的商家信息，请刷新后重试。';
        }
        $restaurant = $vendor->restaurants->first() ?? null;
        $fb = \App\Models\VendorFeedback::create([
            'vendor_id'     => $vendor->id,
            'restaurant_id' => $restaurant?->id,
            'type'          => $type,
            'subject'       => mb_substr((string) $draft['subject'], 0, 150),
            'description'   => mb_substr((string) $draft['description'], 0, 4000),
            'status'        => 'open',
        ]);
        try {
            $name = $restaurant?->name ?? ('商家#' . $vendor->id);
            $typeLabel = \App\Models\VendorFeedback::TYPE_LABELS[$type] ?? $type;
            Helpers::sendTelegramToAdmin("🛎️ 新商家反馈 #{$fb->id}\n商家: {$name}\n类型: {$typeLabel}\n主题: {$draft['subject']}\n(来自商家助手 · 后台「商家反馈」可查看处理)");
        } catch (\Throwable $e) {
        }
        return "✅ 已把这条反馈提交给平台，编号 #{$fb->id}。处理进度和平台回复会显示在「问题反馈」页；平台处理完您也会收到通知。";
    }
}
