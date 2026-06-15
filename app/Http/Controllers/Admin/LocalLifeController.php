<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocalLifePost;
use App\Models\LocalLifeReport;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocalLifeController extends Controller
{
    // 违禁词种子默认（与 Api\V1\LocalLifeController::DEFAULT_BANNED_WORDS 保持一致；后台可在 business_settings 增删）
    private const DEFAULT_BANNED_WORDS = [
        '约炮', '卖淫', '嫖娼', '一夜情', '援交', '特殊服务', '上门保健', 'escort', 'sex service',
        '赌博', '博彩', '网赌', '百家乐', '时时彩', '菠菜平台', 'casino', 'betting',
        '刷单', '兼职刷信誉', '跑分', '洗钱', '代收款', '黑卡', '四件套', '贷款无抵押', '办证', '代开发票', 'fake document',
        '大麻', '冰毒', '代孕', '枪支', '仿真枪', '迷药',
        '加微信群', '引流到Telegram', '私域导流',
    ];

    public function list(Request $request)
    {
        $search = $request['search'];
        $statusFilter = $request->input('status'); // 空=全部, 否则按 status 值筛

        $posts = LocalLifePost::query();

        if ($request->has('search') && $search) {
            $key = explode(' ', $search);
            $posts->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('title', 'like', "%{$value}%")
                        ->orWhere('category', 'like', "%{$value}%");
                }
            });
        }

        if ($statusFilter !== null && $statusFilter !== '') {
            $posts->where('status', (int) $statusFilter);
        }

        // 待审核数（给页面顶部提示用）
        $pendingCount = LocalLifePost::where('status', LocalLifePost::STATUS_PENDING)->count();
        // 待处理举报总数
        $reportPendingTotal = LocalLifeReport::where('status', LocalLifeReport::STATUS_PENDING)->count();
        $ugcEnabled = (bool) (DB::table('business_settings')->where('key', 'locallife_ugc_enabled')->value('value'));

        $posts = $posts->latest()->paginate(config('default_pagination'))->appends([
            'search' => $search,
            'status' => $statusFilter,
        ]);

        // 当前页帖子的待处理举报数 map（避免 N+1）
        $reportCounts = LocalLifeReport::select('post_id', DB::raw('count(*) as c'))
            ->where('status', LocalLifeReport::STATUS_PENDING)
            ->whereIn('post_id', collect($posts->items())->pluck('id'))
            ->groupBy('post_id')
            ->pluck('c', 'post_id');

        return view('admin-views.local-life.list', compact('posts', 'search', 'statusFilter', 'pendingCount', 'reportPendingTotal', 'ugcEnabled', 'reportCounts'));
    }

    public function create()
    {
        $post = null;
        return view('admin-views.local-life.create', compact('post'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title'    => 'required|string|max:200',
            'category' => 'required|string|max:60',
            'tab'      => 'required|string|max:20',
            'status'   => 'required|in:0,1,2',
        ], [
            'title.required'    => '标题必填',
            'category.required' => '分类必填',
            'tab.required'      => 'Tab 必填',
        ]);

        // 违禁词扫描（防运营误录；命中即拒）
        if ($this->hitsBannedWord($this->scanText($request))) {
            Toastr::error('内容含违规词，请修改后再保存');
            return back()->withInput();
        }

        LocalLifePost::create($this->payload($request));

        Toastr::success('帖子已创建');
        return redirect()->route('admin.local-life.list');
    }

    public function edit($id)
    {
        $post = LocalLifePost::findOrFail($id);
        return view('admin-views.local-life.create', compact('post'));
    }

    public function update(Request $request, $id)
    {
        $post = LocalLifePost::findOrFail($id);

        $request->validate([
            'title'    => 'required|string|max:200',
            'category' => 'required|string|max:60',
            'tab'      => 'required|string|max:20',
            'status'   => 'required|in:0,1,2',
        ], [
            'title.required'    => '标题必填',
            'category.required' => '分类必填',
            'tab.required'      => 'Tab 必填',
        ]);

        if ($this->hitsBannedWord($this->scanText($request))) {
            Toastr::error('内容含违规词，请修改后再保存');
            return back()->withInput();
        }

        $post->update($this->payload($request));

        Toastr::success('帖子已更新');
        return redirect()->route('admin.local-life.list');
    }

    // 草稿(0) <-> 已发布(1) 切换；其它状态切换则恢复为已发布
    public function statusToggle($id)
    {
        $post = LocalLifePost::findOrFail($id);
        $post->status = $post->status == LocalLifePost::STATUS_PUBLISHED
            ? LocalLifePost::STATUS_DRAFT
            : LocalLifePost::STATUS_PUBLISHED;
        $post->save();
        Toastr::success($post->status == LocalLifePost::STATUS_PUBLISHED ? '已发布' : '已转为草稿');
        return back();
    }

    // 审核通过：待审核(3) -> 已发布(1)，清掉历史驳回理由
    public function approve($id)
    {
        $post = LocalLifePost::findOrFail($id);
        $post->status = LocalLifePost::STATUS_PUBLISHED;
        $post->reject_reason = null;
        $post->save();
        Toastr::success('已审核通过并发布');
        return back();
    }

    // 审核驳回：-> 已驳回(4)，记录理由（对发帖用户在"我的发布"可见）
    public function reject(Request $request, $id)
    {
        $request->validate([
            'reject_reason' => 'nullable|string|max:255',
        ]);
        $post = LocalLifePost::findOrFail($id);
        $post->status = LocalLifePost::STATUS_REJECTED;
        $post->reject_reason = $request->reject_reason ?: '内容不符合本地生活信息墙规则';
        $post->save();
        Toastr::warning('已驳回该帖');
        return back();
    }

    // 用户发帖入口总开关（真实影响开关，默认关；运营满意后手动开）
    public function ugcToggle(Request $request)
    {
        $enable = $request->boolean('enable');
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'locallife_ugc_enabled'],
            ['value' => $enable ? '1' : '0', 'updated_at' => now()]
        );
        Toastr::success($enable ? '已开放用户发帖入口' : '已关闭用户发帖入口');
        return back();
    }

    /* ============================ 护栏与文案设置 ============================ */

    // 设置页可编辑的 business_settings 键（违禁词/免责文案/规则全文 + 三个反滥用阈值）
    private const SETTING_KEYS = [
        'locallife_banned_words',
        'locallife_disclaimer',
        'locallife_terms',
        'locallife_ugc_daily_limit',
        'locallife_report_daily_limit',
        'locallife_ugc_min_interval_sec',
        'locallife_report_retention_days',
    ];

    // 编辑页：违禁词 / 免责短提示 / 规则全文 / 反滥用阈值
    public function settings()
    {
        $s = DB::table('business_settings')
            ->whereIn('key', self::SETTING_KEYS)
            ->pluck('value', 'key');
        return view('admin-views.local-life.settings', compact('s'));
    }

    public function settingsSave(Request $request)
    {
        $request->validate([
            'locallife_banned_words'         => 'nullable|string|max:20000',
            'locallife_disclaimer'           => 'nullable|string|max:2000',
            'locallife_terms'                => 'nullable|string|max:30000',
            'locallife_ugc_daily_limit'      => 'nullable|integer|min:1|max:100',
            'locallife_report_daily_limit'   => 'nullable|integer|min:1|max:500',
            'locallife_ugc_min_interval_sec' => 'nullable|integer|min:0|max:3600',
            'locallife_report_retention_days' => 'nullable|integer|min:1|max:3650',
        ], [
            'locallife_ugc_daily_limit.integer'      => '每日发帖上限须为整数',
            'locallife_ugc_daily_limit.min'          => '每日发帖上限至少 1',
            'locallife_report_daily_limit.integer'   => '每日举报上限须为整数',
            'locallife_ugc_min_interval_sec.integer' => '最小发帖间隔须为整数（秒）',
            'locallife_report_retention_days.integer' => '举报记录保留天数须为整数',
        ]);

        // 文案类(违禁词/免责/规则)：原样保存，允许清空(清空则顾客端回退代码内默认)
        foreach (['locallife_banned_words', 'locallife_disclaimer', 'locallife_terms'] as $k) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => $k],
                ['value' => (string) $request->input($k, ''), 'updated_at' => now()]
            );
        }
        // 阈值类：留空则不动(保留原值/代码默认)，只在填了有效整数时写入
        foreach (['locallife_ugc_daily_limit', 'locallife_report_daily_limit', 'locallife_ugc_min_interval_sec', 'locallife_report_retention_days'] as $k) {
            if ($request->filled($k)) {
                DB::table('business_settings')->updateOrInsert(
                    ['key' => $k],
                    ['value' => (string) (int) $request->input($k), 'updated_at' => now()]
                );
            }
        }

        Toastr::success('本地生活护栏与文案设置已保存');
        return redirect()->route('admin.local-life.settings');
    }

    public function destroy(Request $request)
    {
        $post = LocalLifePost::find($request->id);
        if ($post) {
            $post->delete();
            Toastr::success('已删除');
        }
        return redirect()->route('admin.local-life.list');
    }

    /* ============================ 举报处理 ============================ */

    // 某帖的举报列表（理由+说明+时间+举报人ID）
    public function reports($id)
    {
        $post = LocalLifePost::findOrFail($id);
        $reports = LocalLifeReport::where('post_id', $id)
            ->orderByRaw("FIELD(status, 0, 1, 2)") // 待处理在前
            ->latest()
            ->paginate(config('default_pagination'));
        return view('admin-views.local-life.reports', compact('post', 'reports'));
    }

    // 下线该帖：帖 status->2(已下线)，该帖所有待处理举报 status->1(已处理)
    public function offlinePost($id)
    {
        $post = LocalLifePost::findOrFail($id);
        $post->status = LocalLifePost::STATUS_OFFLINE;
        $post->save();
        LocalLifeReport::where('post_id', $id)
            ->where('status', LocalLifeReport::STATUS_PENDING)
            ->update(['status' => LocalLifeReport::STATUS_HANDLED, 'updated_at' => now()]);
        Toastr::success('已下线该帖并标记相关举报为已处理');
        return back();
    }

    // 驳回举报：举报 status->2(已驳回)，帖不动
    public function dismissReport($reportId)
    {
        $report = LocalLifeReport::findOrFail($reportId);
        $report->status = LocalLifeReport::STATUS_REJECTED;
        $report->save();
        Toastr::success('已驳回该举报，帖子保留');
        return back();
    }

    /* ============================ 内部工具 ============================ */

    // 整理表单字段；source 固定 admin，checkbox 转 bool，空价格/想要数归零
    private function payload(Request $request): array
    {
        return [
            'title'          => $request->title,
            'category'       => $request->category,
            'tab'            => $request->tab ?: '推荐',
            'description'    => $request->description,
            'cover_emoji'    => $request->cover_emoji,
            'cover_color'    => $request->cover_color,
            'price_amd'      => $request->filled('price_amd') ? (int) $request->price_amd : null,
            'price_suffix'   => $request->price_suffix,
            'is_free'        => $request->boolean('is_free'),
            'area_label'     => $request->area_label,
            'location_label' => $request->location_label,
            'is_urgent'      => $request->boolean('is_urgent'),
            'want_count'     => (int) ($request->want_count ?: 0),
            'contact_info'   => $request->contact_info,
            'expires_at'     => $request->filled('expires_at') ? $request->expires_at : null,
            'status'         => (int) $request->status,
            'source'         => 'admin',
        ];
    }

    private function scanText(Request $request): string
    {
        return trim($request->title . "\n" . (string) $request->description . "\n" . (string) $request->contact_info);
    }

    private function hitsBannedWord(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }
        foreach ($this->bannedWords() as $w) {
            if ($w !== '' && mb_stripos($text, $w) !== false) {
                return true;
            }
        }
        return false;
    }

    private function bannedWords(): array
    {
        $raw = DB::table('business_settings')->where('key', 'locallife_banned_words')->value('value');
        if ($raw === null || trim($raw) === '') {
            return self::DEFAULT_BANNED_WORDS;
        }
        $parts = preg_split('/[\r\n,，]+/u', $raw);
        $words = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $words[] = $p;
            }
        }
        return $words ?: self::DEFAULT_BANNED_WORDS;
    }
}
