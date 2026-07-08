<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaCsAssistant;
use App\Http\Controllers\Controller;
use App\Models\NezhaAssistantMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 哪吒 商家助手：商家后台的 AI 问答（怎么上传/改菜品/排版/功能怎么用）+「会动手」代执行。
 * 白名单护栏在 NezhaCsAssistant::merchantAssistant（绝不提 StackFood、没列的功能不瞎答）。
 *
 * 🔴 安全设计（UX1-E 会话化后原样保留）：AI（LLM）只负责"识别意图 + 生成话术"，**绝不直接触发写操作**。
 *   意图识别是本文件里确定性的关键词匹配（detectStoreCommand 等），命中后只"提议 + 落一张待确认动作卡"；
 *   真正翻店铺状态在 applyStoreStatus() 等，由商家点确认后走本控制器（auth('vendor') 中间件 + 绑定本店），
 *   所以提示注入无法凭一句话把店关掉（要人点确认、且端点只作用于已登录商家自己的店）。动作请求不进 Q&A 缓存。
 *
 * 🟢 UX1-E 新增（L3 持久层，端点语义不变）：
 *   - 会话落库到 nezha_assistant_messages（per-restaurant）；index() 读回最近 30 条按日分组渲染，history() 只读分页看更早。
 *   - 动作卡三态：意图命中→pending 卡（草稿存进 payload，刷新后仍可确认）；确认成功→done；取消→cancelled。
 *   - 确认执行读取的草稿从 session 迁到卡片 payload（刷新后仍能确认）；越权防护 = restaurant_id + status=pending 作用域 + Food 全局 RestaurantScope 双保险。
 */
class NezhaAssistantController extends Controller
{
    private ?int $ridCache = null;
    private bool $ridResolved = false;

    /** 当前登录商家自己的店铺 id（会话/动作卡一律绑它，绝不接受外部传入）。控制器每请求实例化，缓存安全。 */
    private function currentRestaurantId(): ?int
    {
        if (!$this->ridResolved) {
            $r = Helpers::get_restaurant_data();
            $this->ridCache = $r ? (int) $r->id : null;
            $this->ridResolved = true;
        }
        return $this->ridCache;
    }

    /** 落一条文本消息（user/ai）。空内容或无店铺时跳过（不造脏行）。 */
    private function persist(string $role, ?string $content): void
    {
        $rid = $this->currentRestaurantId();
        if (!$rid || $content === null || $content === '') {
            return;
        }
        NezhaAssistantMessage::create([
            'restaurant_id' => $rid,
            'role'          => $role,
            'content'       => $content,
        ]);
    }

    /** 落一张待确认动作卡（role=ai + action_type/payload/status=pending）。 */
    private function persistCard(string $type, array $payload): void
    {
        $rid = $this->currentRestaurantId();
        if (!$rid) {
            return;
        }
        NezhaAssistantMessage::create([
            'restaurant_id' => $rid,
            'role'          => 'ai',
            'content'       => null,
            'action_type'   => $type,
            'payload'       => $payload,
            'status'        => 'pending',
        ]);
    }

    public function index()
    {
        $rid = $this->currentRestaurantId();
        $messages = collect();
        $hasMore = false;
        if ($rid) {
            $messages = NezhaAssistantMessage::where('restaurant_id', $rid)
                ->orderByDesc('id')->limit(30)->get()->reverse()->values();
            if ($messages->count() === 30) {
                $hasMore = NezhaAssistantMessage::where('restaurant_id', $rid)
                    ->where('id', '<', $messages->first()->id)->exists();
            }
        }
        return view('vendor-views.assistant.index', compact('messages', 'hasMore'));
    }

    /**
     * 只读分页：加载更早的对话（游标 = 前端当前最旧消息 id）。返回渲染好的 HTML 片段，客户端 prepend。
     * 🔴 一律绑本店；纯读，不写、不执行任何动作。
     */
    public function history(Request $request)
    {
        $rid = $this->currentRestaurantId();
        if (!$rid) {
            return response()->json(['html' => '', 'hasMore' => false, 'oldest' => 0]);
        }
        $before = (int) $request->input('before', 0);
        $q = NezhaAssistantMessage::where('restaurant_id', $rid);
        if ($before > 0) {
            $q->where('id', '<', $before);
        }
        $rows = $q->orderByDesc('id')->limit(30)->get();
        $hasMore = false;
        if ($rows->count() === 30) {
            $hasMore = NezhaAssistantMessage::where('restaurant_id', $rid)
                ->where('id', '<', $rows->last()->id)->exists();
        }
        $messages = $rows->reverse()->values();
        $oldest = $messages->isNotEmpty() ? (int) $messages->first()->id : 0;
        $html = view('vendor-views.assistant._messages', compact('messages'))->render();
        return response()->json(['html' => $html, 'hasMore' => $hasMore, 'oldest' => $oldest]);
    }

    public function ask(Request $request)
    {
        // ⓪ 取消动作卡：把 pending 卡置为 cancelled（作用域绑本店 + pending，防越权/重放）。纯状态回写，不执行任何动作。
        if ($request->filled('cancel_action')) {
            $rid = $this->currentRestaurantId();
            $msgId = (int) $request->input('msg_id');
            if ($rid && $msgId) {
                NezhaAssistantMessage::where('restaurant_id', $rid)
                    ->where('id', $msgId)->where('status', 'pending')
                    ->update(['status' => 'cancelled']);
            }
            return back();
        }

        // ① 确认执行分支：来自动作卡的"确认"按钮，直接执行动作（不经 AI）。
        if ($request->filled('confirm_action')) {
            $action = $request->input('confirm_action');
            if (!in_array($action, ['pause', 'resume', 'feedback', 'price'], true)) {
                return back();
            }
            $rid = $this->currentRestaurantId();
            $akey = 'nezha-assistant-act:' . ($rid ?: $request->ip());
            if (RateLimiter::tooManyAttempts($akey, 20)) {
                $this->persist('ai', '操作太频繁了，请稍后再试～');
                return back();
            }
            RateLimiter::hit($akey, 60);

            // 定位这张待确认卡（🔴 绑本店 + 同 action_type + status=pending：防越权改他店、防重复执行/重放）。
            $msgId = (int) $request->input('msg_id');
            $card = $rid ? NezhaAssistantMessage::where('restaurant_id', $rid)
                ->where('id', $msgId)->where('action_type', $action)
                ->where('status', 'pending')->first() : null;
            if (!$card) {
                $this->persist('ai', '这个操作已处理或已过期，请重新发起。');
                return back();
            }

            $payload = is_array($card->payload) ? $card->payload : [];
            [$ok, $msg] = match ($action) {
                'feedback' => $this->applyFeedback($payload),
                'price'    => $this->applyPriceChange($payload),
                default    => $this->applyStoreStatus($action),
            };
            if ($ok) {
                $card->status = 'done';
                $card->save();
            }
            $this->persist('ai', $msg);
            return back();
        }

        // ② 普通提问 / 动作意图（经典 POST：JS 关时的兜底；流式 reload 分支也回落到这里）
        $request->validate(['question' => 'required|string|max:500']);
        $q = trim($request->question);

        // 哪吒: 单商家限速 30 次/分钟, 防连点刷 DeepSeek 烧平台 token (员工与店主共享同一桶 = 按店限)
        $rid = $this->currentRestaurantId();
        $key = 'nezha-assistant-ask:' . ($rid ?: $request->ip());
        if (RateLimiter::tooManyAttempts($key, 30)) {
            $seconds = RateLimiter::availableIn($key);
            $this->persist('user', $q);
            $this->persist('ai', '您问得太快啦，请 ' . $seconds . ' 秒后再试～');
            return back();
        }
        RateLimiter::hit($key, 60); // 60 秒滑动窗口

        $this->persist('user', $q);

        // Phase 1 动作意图：暂停 / 恢复接单（命令式，非"怎么…"提问）→ 先出确认卡再执行
        $cmd = $this->detectStoreCommand($q);
        if ($cmd) {
            $restaurant = Helpers::get_restaurant_data();
            $closed = $restaurant ? (int) $restaurant->nezha_temp_closed : 0;
            if ($cmd === 'pause' && $closed === 1) {
                $this->persist('ai', '您的店当前已经是【暂停接单】了，顾客暂时下不了单。想恢复接单，跟我说「恢复接单」就行。');
                return back();
            }
            if ($cmd === 'resume' && $closed === 0) {
                $this->persist('ai', '您的店当前就是【营业中】、正常接单呢，无需恢复。');
                return back();
            }
            $tip = $cmd === 'pause'
                ? '好的。暂停后顾客暂时下不了单，进行中的订单不受影响。请在下方卡片确认。'
                : '好的。恢复营业后顾客可以正常下单。请在下方卡片确认。';
            $this->persist('ai', $tip);
            $this->persistCard($cmd, []);
            return back();
        }

        // Phase 2 动作意图：把商家的话整理成一条「问题反馈」交给平台超管（先给商家看草稿→确认→提交）
        if ($this->detectFeedbackIntent($q)) {
            $draft = NezhaCsAssistant::draftMerchantFeedback($q);
            $typeLabel = \App\Models\VendorFeedback::TYPE_LABELS[$draft['type']] ?? $draft['type'];
            $preview = "我按您说的整理了一条反馈，确认后提交给平台超管：\n\n【类型】{$typeLabel}\n【主题】{$draft['subject']}\n【详情】{$draft['description']}\n\n没问题就点下方卡片「确认提交给平台」；想补充或改，直接把要说的再发我一遍。";
            $this->persist('ai', $preview);
            $this->persistCard('feedback', [
                'type'        => $draft['type'],
                'subject'     => $draft['subject'],
                'description' => $draft['description'],
            ]);
            return back();
        }

        // Phase 3 动作意图：改某道菜的价格（LLM 抽取菜名+新价 → 本店查菜(全局RestaurantScope) → 确认 → 改价）
        if ($this->detectPriceIntent($q)) {
            $pc = NezhaCsAssistant::extractPriceChange($q);
            if ($pc) {
                $foods = \App\Models\Food::where('name', 'like', '%' . $pc['name'] . '%')->limit(6)->get(['id', 'name', 'price']);
                if ($foods->count() === 1) {
                    $food = $foods->first();
                    $this->persist('ai', "找到「{$food->name}」，当前 ֏" . $this->nzMoney($food->price) . "。改价请在下方卡片确认。");
                    $this->persistCard('price', [
                        'food_id'   => $food->id,
                        'food_name' => $food->name,
                        'old_price' => (float) $food->price,
                        'new_price' => (float) $pc['price'],
                    ]);
                    return back();
                }
                if ($foods->count() > 1) {
                    $names = $foods->pluck('name')->implode('、');
                    $this->persist('ai', "找到多道名字相近的菜：{$names}。请说得更具体些，比如「把{$foods->first()->name}改成" . intval($pc['price']) . "」。");
                    return back();
                }
                $this->persist('ai', "没找到叫「{$pc['name']}」的菜，请对照「商品列表」里的准确菜名再说一次。");
                return back();
            }
            // 抽取不到明确菜名/价格 → 落到 Q&A 引导商家怎么改价
        }

        $answer = NezhaCsAssistant::merchantAssistant($q);
        $this->persist('ai', $answer);
        return back();
    }

    /**
     * 流式问答端点（SSE）：纯 Q&A 逐字返回，消除"提交后整页刷新、看着像卡死"。
     * 🔴 动作意图（暂停/恢复接单·代写反馈·改价）在这里一律返回 {mode:reload}，交回经典 POST 的 ask() 流程走"确认卡片"，
     *    绝不流式、绝不进缓存、绝不在此落库（由 ask() 落）——"确定性识别意图→确认→后端执行、与 LLM 输出解耦"的安全设计原样保留。
     *    缓存命中直接整段秒回（{mode:cached}）；只有未命中的纯问答才真正走 DeepSeek 流式。
     * 🟢 UX1-E：纯问答（cached / SSE）在此落库——用户问句 + 完整 AI 答（流式结束/中断都在 finally 里落已累加部分）。
     */
    public function stream(Request $request)
    {
        $request->validate(['question' => 'required|string|max:500']);
        $q = trim($request->question);

        // 沿用问答限速桶（30 次/分钟·店主与店员共享同一桶 = 按店限）
        $rid = $this->currentRestaurantId();
        $key = 'nezha-assistant-ask:' . ($rid ?: $request->ip());
        if (RateLimiter::tooManyAttempts($key, 30)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json(['mode' => 'error', 'answer' => '您问得太快啦，请 ' . $seconds . ' 秒后再试～']);
        }
        RateLimiter::hit($key, 60);

        // 动作意图 → 交回经典 POST 的 ask()（出确认卡），不流式、不缓存、不落库（ask() 会落用户问句+提议+卡）
        if ($this->detectStoreCommand($q) || $this->detectFeedbackIntent($q) || $this->detectPriceIntent($q)) {
            return response()->json(['mode' => 'reload']);
        }

        // 到这里必是纯问答（cached 或 SSE）→ 落用户问句
        $this->persist('user', $q);

        // Q&A 缓存命中 → 整段秒回（无需流式），并落库 AI 答
        $cached = NezhaCsAssistant::merchantCachedAnswer($q);
        if ($cached !== null) {
            $this->persist('ai', $cached);
            return response()->json(['mode' => 'cached', 'answer' => $cached]);
        }

        // 未命中 → SSE 逐字流式；累加整段，结束/中断都在 finally 里落库（捕获部分答，刷新后不丢）
        $rid2 = $rid;
        return response()->stream(function () use ($q, $rid2) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            ob_implicit_flush(true);
            echo ": open\n\n"; // 先吐一个注释帧，尽快让浏览器 ReadableStream 开始送字
            @flush();

            $full = '';
            try {
                NezhaCsAssistant::merchantAssistantStream($q, function ($token) use (&$full) {
                    $full .= $token;
                    echo 'data: ' . json_encode(['t' => $token], JSON_UNESCAPED_UNICODE) . "\n\n";
                    @flush();
                });
            } finally {
                if ($full !== '' && $rid2) {
                    try {
                        NezhaAssistantMessage::create([
                            'restaurant_id' => $rid2,
                            'role'          => 'ai',
                            'content'       => $full,
                        ]);
                    } catch (\Throwable $e) {
                        // 落库失败不影响已流式返回的回答
                    }
                }
            }

            echo "event: done\ndata: \"ok\"\n\n";
            @flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no', // 🔴 关键：告诉 nginx / Cloudflare 不要缓冲这条响应，否则整段憋到最后=依旧像卡死
            'Connection'        => 'keep-alive',
        ]);
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
     * 返回 [bool $ok, string $msg]：$ok 才把动作卡置 done。
     */
    private function applyStoreStatus(string $action): array
    {
        $restaurant = Helpers::get_restaurant_data();
        if (!$restaurant) {
            return [false, '没找到您的店铺信息，请刷新后重试或联系平台。'];
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
            return [false, '⚠️ 没能改成功，请到「商家配置 → 营业状态」手动切换，或稍后再试。'];
        }
        return [true, $want
            ? '✅ 已帮您把店铺改成【暂停接单】。顾客现在下不了单（进行中的订单不受影响）。想恢复，跟我说一声「恢复接单」就行。'
            : '✅ 已帮您把店铺改成【营业中】，正常接单啦～'];
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
     * 草稿从动作卡 payload 取（服务端持久，防客户端篡改），执行后卡置 done。
     * 返回 [bool $ok, string $msg]。
     */
    private function applyFeedback(array $draft): array
    {
        if (empty($draft['subject']) || empty($draft['description'])) {
            return [false, '没找到要提交的反馈内容，麻烦把您的问题再说一遍。'];
        }
        $type = in_array(($draft['type'] ?? 'other'), ['commission', 'settlement', 'feature', 'other'], true) ? $draft['type'] : 'other';
        $vendor = Helpers::get_vendor_data();
        if (!$vendor) {
            return [false, '没找到您的商家信息，请刷新后重试。'];
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
        return [true, "✅ 已把这条反馈提交给平台，编号 #{$fb->id}。处理进度和平台回复会显示在「问题反馈」页；平台处理完您也会收到通知。"];
    }

    /** 识别"改某道菜价格"命令（确定性关键词，抽取交给 LLM）。误判无害（提取不到就落 Q&A）。 */
    private function detectPriceIntent(string $q): bool
    {
        $s = preg_replace('/\s+/u', '', $q);
        if (preg_match('/怎么|如何|怎样|在哪|哪里/u', $s)) {
            return false;
        }
        return (bool) preg_match('/(改|调|涨|降|设|定)(一?下)?价|价格.{0,4}(改|调|设|定|涨|降)|(涨|降|调|改)到\d|改成\d|卖\d|(单价|售价).{0,4}\d|\d+.{0,3}(元|块|֏|德拉姆)/u', $s);
    }

    /** ֏ 价格显示：整数不带小数、带千分位（4800 -> 4,800）；有小数保留至多 2 位。 */
    private function nzMoney($p): string
    {
        $f = (float) $p;
        if (floor($f) == $f) {
            return number_format($f, 0);
        }
        return rtrim(rtrim(number_format($f, 2), '0'), '.');
    }

    /**
     * 执行改价（草稿从动作卡 payload 取）。🔴 Food 全局 RestaurantScope 自动绑本店，
     * findOrFail 外店 id -> 404，天然防越权改他店价；save() 触发模型缓存失效（同 updatePrice）。
     * 返回 [bool $ok, string $msg]。
     */
    private function applyPriceChange(array $d): array
    {
        if (empty($d['food_id']) || !isset($d['new_price']) || (float) $d['new_price'] <= 0) {
            return [false, '没找到要改的价格信息，麻烦重新说一遍（比如「把麻婆豆腐改成 1200」）。'];
        }
        try {
            $food = \App\Models\Food::findOrFail((int) $d['food_id']);
        } catch (\Throwable $e) {
            return [false, '没找到这道菜（可能已删除或改名），请刷新后重试。'];
        }
        $food->price = (float) $d['new_price'];
        // 与 FoodController::updatePrice 一致：固定折扣 >= 新价会算出负价，随价调整清掉，防坏数据
        if (($food->discount_type ?? '') === 'amount' && (float) $food->discount >= (float) $food->price) {
            $food->discount = 0;
        }
        $food->save();
        $now = \App\Models\Food::findOrFail($food->id)->price;
        return [true, '✅ 已把【' . $food->name . '】的单价改成 ֏' . $this->nzMoney($now) . '，顾客端菜单已同步。'];
    }
}
