<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\LocalLifePost;
use App\Models\LocalLifeReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LocalLifeController extends Controller
{
    // 列表接口对外暴露的字段（不含 contact_info / expires_at / user_id 等内部字段）
    private const LIST_FIELDS = [
        'id', 'title', 'category', 'tab', 'cover_emoji', 'cover_color', 'images',
        'price_amd', 'price_suffix', 'is_free', 'area_label', 'location_label',
        'is_urgent', 'want_count', 'created_at',
    ];

    // 图片存储目录（与 Helpers::upload 一致）
    private const IMG_DIR = 'local-life';

    // 发帖可选 tab / category 白名单（与前端表单一致）
    private const TABS = ['推荐', '租房', '招聘', '二手', '免费', '服务'];
    private const CATEGORIES = [
        '租房合租', '找工作', '二手闲置', '养车出行', '装修维修',
        '教育培训', '签证法律', '接送拼车', '家政保洁', '搬家', '维修水电',
        '免费赠送', '其他',
    ];

    // 举报理由白名单（前后端严格一致；"其他"必填 detail）
    private const REPORT_REASONS = [
        '虚假或诈骗信息',
        '涉黄 / 赌博 / 违法',
        '骚扰 / 辱骂 / 人身攻击',
        '重复或垃圾广告',
        '冒用他人信息 / 侵犯隐私',
        '其他',
    ];
    private const REPORT_REASON_OTHER = '其他';

    // 违禁词种子默认（仅在 business_settings.locallife_banned_words 未配置时兜底；后台可增删）
    private const DEFAULT_BANNED_WORDS = [
        // 涉黄/性交易
        '约炮', '卖淫', '嫖娼', '一夜情', '援交', '特殊服务', '上门保健', 'escort', 'sex service',
        // 赌博
        '赌博', '博彩', '网赌', '百家乐', '时时彩', '菠菜平台', 'casino', 'betting',
        // 诈骗/洗钱/灰产
        '刷单', '兼职刷信誉', '跑分', '洗钱', '代收款', '黑卡', '四件套', '贷款无抵押', '办证', '代开发票', 'fake document',
        // 毒品/违禁品
        '大麻', '冰毒', '代孕', '枪支', '仿真枪', '迷药',
        // 外站强引流
        '加微信群', '引流到Telegram', '私域导流',
    ];

    // 免责短提示（列表/详情底部常驻；后台 locallife_disclaimer 可覆盖）
    private const DEFAULT_DISCLAIMER = '本地生活为信息展示与撮合平台，所有信息由用户自行发布。哪吒不对信息真实性及交易结果负责，请自行核实、注意线下交易安全。';

    // 《本地生活信息发布规则》全文（后台 locallife_terms 可覆盖以便微调）
    private const DEFAULT_TERMS = "哪吒本地生活信息发布规则\n\n1. 本频道仅提供信息发布与展示服务，撮合供需双方私下联系，哪吒平台不参与任何交易、不收取交易款项、不提供担保或代收代付。\n\n2. 所有帖子内容由用户自行发布并负责，哪吒不对信息的真实性、合法性、准确性及交易结果承担责任。用户应自行核实信息、谨慎交易，线下见面注意人身与财产安全。\n\n3. 禁止发布以下内容：违法犯罪、诈骗、色情、赌博、毒品、枪支等违禁信息；虚假、冒用他人身份或联系方式的信息；骚扰、辱骂、歧视、侵犯他人隐私或知识产权的内容；与本地生活无关的垃圾广告或恶意引流。\n\n4. 你发布的联系方式仅对登录用户在帖子详情可见，并将在帖子到期后自动清除。请勿在帖子中填写他人信息或敏感个人资料。\n\n5. 哪吒有权对违反本规则的帖子不予通过、下线或删除，并视情况限制相关账号的发布权限。\n\n6. 提交发布即表示你已阅读并同意本规则。";

    /* ============================ 公开只读 ============================ */

    /**
     * 接口 A：本地生活帖子列表
     * GET /api/v1/local-life/posts?tab=推荐&limit=20&offset=1
     * 只返回已发布(status=1)，按 created_at DESC 分页。绝不返回 contact_info。
     * 附带 ugc_enabled / ugc_disclaimer / ugc_terms（前端 FAB、免责提示、规则弹层用）。
     */
    public function posts(Request $request)
    {
        $limit = (int) ($request->input('limit', 20));
        $limit = $limit > 0 ? min($limit, 50) : 20;
        $offset = (int) ($request->input('offset', 1));
        $offset = $offset > 0 ? $offset : 1;

        $query = LocalLifePost::where('status', LocalLifePost::STATUS_PUBLISHED);

        $tab = $request->input('tab');
        if ($tab && $tab !== '推荐') {
            $query->where('tab', $tab);
        }

        $total = $query->count();
        $posts = $query->orderBy('created_at', 'desc')
            ->skip(($offset - 1) * $limit)
            ->take($limit)
            ->get(self::LIST_FIELDS)
            ->map(function ($p) {
                $arr = $p->toArray();
                $arr['image_urls'] = $this->imageUrls($p->images);
                return $arr;
            });

        return response()->json([
            'total_size'     => $total,
            'limit'          => $limit,
            'offset'         => $offset,
            'ugc_enabled'    => $this->ugcEnabled(),
            'ugc_disclaimer' => $this->disclaimerText(),
            'ugc_terms'      => $this->termsText(),
            'posts'          => $posts,
        ], 200);
    }

    /**
     * 接口 B：本地生活帖子详情
     * GET /api/v1/local-life/posts/{id}
     * 只返回已发布帖子；contact_info 仅对持有有效 token 的登录用户返回，游客一律 null。
     */
    public function postDetail(Request $request, $id)
    {
        $post = LocalLifePost::where('status', LocalLifePost::STATUS_PUBLISHED)->find($id);

        if (!$post) {
            return response()->json(['errors' => [['code' => 'post', 'message' => '帖子不存在或已下线']]], 404);
        }

        $data = $post->toArray();
        $data['image_urls'] = $this->imageUrls($post->images);
        unset($data['user_id'], $data['reject_reason']); // 不对外暴露内部字段

        // PII 红线(L1-7)：联系方式只给已登录用户，游客看不到
        if (!auth('api')->check()) {
            $data['contact_info'] = null;
        }

        $data['ugc_disclaimer'] = $this->disclaimerText();
        $data['ugc_terms']      = $this->termsText();
        $data['report_reasons'] = self::REPORT_REASONS;

        return response()->json($data, 200);
    }

    /* ============================ 需登录(auth:api) ============================ */

    /**
     * 接口 C：用户发帖
     * POST /api/v1/local-life/posts  (auth:api)
     * 落库 status=3(待审核)、source='user'、user_id=当前用户、expires_at=+30天。
     * L1-1: 仅信息墙——绝不引入支付/押金/代收/下单/担保任何字段。
     * 护栏顺序：总开关 → 每日上限 → 字段校验 → 最小发帖间隔 → 重复标题 → 违禁词。
     */
    public function storePost(Request $request)
    {
        $userId = auth('api')->id();

        // 总开关：未开放则拒绝发帖（与前端 FAB 双保险）
        if (!$this->ugcEnabled()) {
            return response()->json(['errors' => [['code' => 'closed', 'message' => '发帖功能暂未开放']]], 403);
        }

        // 每用户每日发帖上限（基础反刷）
        $dailyLimit = (int) $this->setting('locallife_ugc_daily_limit', 5);
        $dailyLimit = $dailyLimit > 0 ? $dailyLimit : 5;
        $todayCount = LocalLifePost::where('user_id', $userId)
            ->where('created_at', '>=', Carbon::today())
            ->count();
        if ($todayCount >= $dailyLimit) {
            return response()->json(['errors' => [['code' => 'limit', 'message' => '今日发帖已达上限（' . $dailyLimit . ' 条），请明天再试']]], 429);
        }

        $validator = Validator::make($request->all(), [
            'title'          => 'required|string|max:200',
            'category'       => ['required', 'string', 'in:' . implode(',', self::CATEGORIES)],
            'tab'            => ['required', 'string', 'in:' . implode(',', self::TABS)],
            'description'    => 'nullable|string|max:2000',
            'contact_info'   => 'required|string|max:200',
            'price_amd'      => 'nullable|integer|min:0|max:999999999',
            'price_suffix'   => 'nullable|string|max:20',
            'is_free'        => 'nullable|boolean',
            'area_label'     => 'nullable|string|max:80',
            'location_label' => 'nullable|string|max:60',
            'images'         => 'nullable|array|max:6',
            'images.*'       => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ], [
            'title.required'        => '请填写标题',
            'category.required'     => '请选择分类',
            'category.in'           => '分类不在允许范围',
            'tab.required'          => '请选择频道',
            'tab.in'                => '频道不在允许范围',
            'contact_info.required' => '请填写联系方式，否则别人无法联系你',
            'images.max'            => '最多上传 6 张图片',
            'images.*.image'        => '只能上传图片',
            'images.*.mimes'        => '图片格式仅支持 jpg/png/webp',
            'images.*.max'          => '单张图片不能超过 5MB',
        ]);

        if ($validator->fails()) {
            // 直接用中文 message，不走 Helpers::error_processor（其 translate() 会给非翻译键加 "messages." 前缀）
            $errs = [];
            foreach ($validator->errors()->getMessages() as $field => $msgs) {
                $errs[] = ['code' => $field, 'message' => $msgs[0]];
            }
            return response()->json(['errors' => $errs], 422);
        }

        // 最小发帖间隔（反刷）：同用户两次发帖间隔过短直接拦
        $minInterval = (int) $this->setting('locallife_ugc_min_interval_sec', 60);
        if ($minInterval > 0) {
            $last = LocalLifePost::where('user_id', $userId)->latest('created_at')->first();
            if ($last && $last->created_at && $last->created_at->diffInSeconds(Carbon::now()) < $minInterval) {
                return response()->json(['errors' => [['code' => 'too_fast', 'message' => '操作太频繁，请稍后再试']]], 429);
            }
        }

        // 重复标题（反刷）：同用户近 24h 内有完全相同标题的帖
        $dupTitle = LocalLifePost::where('user_id', $userId)
            ->where('title', $request->title)
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->exists();
        if ($dupTitle) {
            return response()->json(['errors' => [['code' => 'duplicate', 'message' => '你已发布过相同标题的信息']]], 422);
        }

        // 违禁词过滤（命中即拒，不转待审核）：扫 title + description + contact_info
        $scan = trim($request->title . "\n" . (string) $request->description . "\n" . (string) $request->contact_info);
        if ($this->hitsBannedWord($scan)) {
            // 不回显命中词，避免被试探绕过
            return response()->json(['errors' => [['code' => 'banned', 'message' => '内容含违规词，请修改后再发布']]], 422);
        }

        $isFree = $request->boolean('is_free');

        // 图片上传（jpg/png 自动转 webp）；仅存文件名数组
        $imageNames = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $imageNames[] = Helpers::upload(self::IMG_DIR . '/', 'webp', $file);
            }
        }

        $post = LocalLifePost::create([
            'user_id'        => $userId,
            'title'          => $request->title,
            'category'       => $request->category,
            'tab'            => $request->tab,
            'description'    => $request->description,
            'images'         => $imageNames ?: null,
            'price_amd'      => (!$isFree && $request->filled('price_amd')) ? (int) $request->price_amd : null,
            'price_suffix'   => $isFree ? null : $request->price_suffix,
            'is_free'        => $isFree,
            'area_label'     => $request->area_label,
            'location_label' => $request->location_label,
            'is_urgent'      => false,
            'want_count'     => 0,
            'contact_info'   => $request->contact_info,
            'expires_at'     => Carbon::now()->addDays(30),
            'status'         => LocalLifePost::STATUS_PENDING,
            'source'         => 'user',
        ]);

        return response()->json([
            'message' => '已提交，审核通过后会展示在本地生活',
            'id'      => $post->id,
        ], 200);
    }

    /**
     * 接口 D：我的发布
     * GET /api/v1/local-life/my-posts  (auth:api)
     */
    public function myPosts(Request $request)
    {
        $userId = auth('api')->id();

        $posts = LocalLifePost::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->take(100)
            ->get()
            ->map(function ($p) {
                $arr = $p->toArray();
                $arr['status_label'] = $p->statusLabel();
                $arr['image_urls']   = $this->imageUrls($p->images);
                return $arr;
            });

        return response()->json(['posts' => $posts], 200);
    }

    /**
     * 接口 E：举报已发布帖（auth:api，禁匿名）
     * POST /api/v1/local-life/posts/{id}/report
     * reason 必填且在白名单；reason=其他时 detail 必填(max 500)；同用户对同帖已有未处理举报→去重提示；
     * 每用户每日举报上限 locallife_report_daily_limit(默认20)。L1-1: 不含任何资金字段。
     */
    public function reportPost(Request $request, $id)
    {
        $userId = auth('api')->id();

        $post = LocalLifePost::where('status', LocalLifePost::STATUS_PUBLISHED)->find($id);
        if (!$post) {
            return response()->json(['errors' => [['code' => 'post', 'message' => '帖子不存在或已下线']]], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'in:' . implode(',', self::REPORT_REASONS)],
            'detail' => 'nullable|string|max:500',
        ], [
            'reason.required' => '请选择举报理由',
            'reason.in'       => '举报理由不在允许范围',
            'detail.max'      => '说明最多 500 字',
        ]);
        $validator->after(function ($v) use ($request) {
            if ($request->input('reason') === self::REPORT_REASON_OTHER && !trim((string) $request->input('detail'))) {
                $v->errors()->add('detail', '选择"其他"时请填写具体说明');
            }
        });
        if ($validator->fails()) {
            $errs = [];
            foreach ($validator->errors()->getMessages() as $field => $msgs) {
                $errs[] = ['code' => $field, 'message' => $msgs[0]];
            }
            return response()->json(['errors' => $errs], 422);
        }

        // 每日举报上限
        $reportLimit = (int) $this->setting('locallife_report_daily_limit', 20);
        $reportLimit = $reportLimit > 0 ? $reportLimit : 20;
        $todayReports = LocalLifeReport::where('user_id', $userId)
            ->where('created_at', '>=', Carbon::today())
            ->count();
        if ($todayReports >= $reportLimit) {
            return response()->json(['errors' => [['code' => 'limit', 'message' => '今日举报已达上限，请明天再试']]], 429);
        }

        // 去重：同用户对同帖已有未处理举报 → 友好提示(不报错)
        $exists = LocalLifeReport::where('post_id', $post->id)
            ->where('user_id', $userId)
            ->where('status', LocalLifeReport::STATUS_PENDING)
            ->exists();
        if ($exists) {
            return response()->json(['message' => '你已举报过该信息，我们会尽快处理'], 200);
        }

        LocalLifeReport::create([
            'post_id' => $post->id,
            'user_id' => $userId,
            'reason'  => $request->reason,
            'detail'  => $request->input('detail') ?: null,
            'status'  => LocalLifeReport::STATUS_PENDING,
        ]);

        return response()->json(['message' => '已收到举报，感谢反馈'], 200);
    }

    /* ============================ 内部工具 ============================ */

    private function imageUrls($images): array
    {
        if (empty($images)) {
            return [];
        }
        $list = is_array($images) ? $images : (json_decode($images, true) ?: []);
        $urls = [];
        foreach ($list as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $base = basename($name);
            $url = Helpers::get_full_url(self::IMG_DIR, $base, 'public');
            if ($url) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    private function ugcEnabled(): bool
    {
        return (bool) $this->setting('locallife_ugc_enabled', '0');
    }

    private function disclaimerText(): string
    {
        $v = $this->setting('locallife_disclaimer', null);
        return ($v !== null && trim($v) !== '') ? $v : self::DEFAULT_DISCLAIMER;
    }

    private function termsText(): string
    {
        $v = $this->setting('locallife_terms', null);
        return ($v !== null && trim($v) !== '') ? $v : self::DEFAULT_TERMS;
    }

    /**
     * 违禁词命中判定：词库取后台 business_settings.locallife_banned_words(换行/逗号分隔)，
     * 未配置时用 DEFAULT_BANNED_WORDS 兜底。子串匹配、不区分大小写(mb_stripos)。
     */
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
        $raw = $this->setting('locallife_banned_words', null);
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

    private function setting(string $key, $default)
    {
        $v = DB::table('business_settings')->where('key', $key)->value('value');
        return $v === null ? $default : $v;
    }
}
