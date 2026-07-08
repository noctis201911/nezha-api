<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\LocalLifePost;
use App\Models\LocalLifeCategory;
use App\Models\LocalLifeMerchant;
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
    private const MERCHANT_IMG_DIR = 'local-life-merchant';

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
        // 签证/移民诈骗（敏感类目「移民」「签证」加强审核）
        '包过签', '保证过签', '100%过签', '百分百过签', '拒签全退', '拒签退全款', '包入籍', '包拿绿卡', '包拿身份', '黑户洗白', '假学历证', '假资产证明', '假银行流水',
        // 按摩/SPA 涉黄（敏感类目「按摩」加强审核）
        '大保健', '莞式', '楼凤', '一条龙服务', '性服务', '裸聊', '裸体按摩',
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

        $query = LocalLifePost::where('status', LocalLifePost::STATUS_PUBLISHED)
            ->where('listing_status', LocalLifePost::LISTING_ACTIVE); // 信息流只出在售(已成交/已失效不进流)

        // 信息流只出「个人发帖(ugc)」类目：商家服务类目(移民/签证/美容美发/按摩…)走商家页，不混进信息流
        $ugcCats = LocalLifeCategory::where('status', true)->where('kind', 'ugc')->pluck('name')->toArray();
        if (!empty($ugcCats)) {
            $query->whereIn('category', $ugcCats);
        }

        $tab = $request->input('tab');
        if ($tab && $tab !== '推荐') {
            $query->where('tab', $tab);
        }

        // 子类精确筛选（前端「找服务/上门服务」子图标）：有则在 tab 基础上再按 category 收窄
        $category = $request->input('category');
        if ($category !== null && trim($category) !== '') {
            $query->where('category', $category);
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
            'ugc_pii_notice'   => $this->piiNoticeText(),
            'ugc_pii_required' => $this->piiConsentRequired(),
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
        // 审核态仍须已发布(1)；但生命周期态(在售/已成交/已失效)不过滤——
        // 已成交/已失效帖直链访问显「终态章」而非 404(HANDOFF §C.10)。
        $post = LocalLifePost::where('status', LocalLifePost::STATUS_PUBLISHED)->find($id);

        if (!$post) {
            return response()->json(['errors' => [['code' => 'post', 'message' => '帖子不存在或已下线']]], 404);
        }

        $data = $post->toArray();
        $data['image_urls'] = $this->imageUrls($post->images);
        unset($data['user_id'], $data['reject_reason']); // 不对外暴露内部字段
        // listing_status / contact_method / contact_value 已在 toArray 中，前端据此渲染状态章 + sticky 转化条

        // PII 红线(L1-7)：联系方式(含结构化 method/value)只给已登录用户，游客看不到
        if (!auth('api')->check()) {
            $data['contact_info']   = null;
            $data['contact_method'] = null;
            $data['contact_value']  = null;
        }

        $data['ugc_disclaimer'] = $this->disclaimerText();
        $data['ugc_terms']      = $this->termsText();
        $data['ugc_pii_notice']   = $this->piiNoticeText();
        $data['ugc_pii_required'] = $this->piiConsentRequired();
        $data['report_reasons'] = self::REPORT_REASONS;

        return response()->json($data, 200);
    }

    /* ============================ 需登录(auth:api) ============================ */

    /**
     * 接口 F：本地生活类目列表（公开只读）
     * GET /api/v1/local-life/categories
     * 返回后台启用中的类目（前端金刚区按此动态渲染；加新类目无需改前端）。
     */
    public function categories(Request $request)
    {
        $cats = LocalLifeCategory::where('status', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['name', 'emoji', 'color', 'tab', 'kind', 'is_sensitive'])
            ->map(function ($c) {
                return [
                    'name'         => $c->name,
                    'emoji'        => $c->emoji,
                    'color'        => $c->color,
                    'tab'          => $c->tab,
                    'kind'         => $c->kind ?: 'ugc',
                    'is_sensitive' => (bool) $c->is_sensitive,
                ];
            });
        return response()->json(['categories' => $cats], 200);
    }

    /**
     * 商家列表（公开只读）。纯信息展示，无任何支付/下单入口（L1-1 信息墙）。
     * GET /api/v1/local-life/merchants?category=&area=&open_now=1&has_offer=1&limit=&offset=
     */
    public function merchants(Request $request)
    {
        $limit = (int) ($request->input('limit', 30));
        $limit = $limit > 0 ? min($limit, 60) : 30;
        $offset = (int) ($request->input('offset', 1));
        $offset = $offset > 0 ? $offset : 1;

        $query = LocalLifeMerchant::where('status', true);
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('area')) {
            $query->where('area', $request->area);
        }
        if ($request->boolean('has_offer')) {
            $query->where('has_offer', true);
        }

        $total = $query->count();
        $rows = $query->orderBy('sort_order')->orderByDesc('id')
            ->skip(($offset - 1) * $limit)->take($limit)->get();

        $list = $rows->map(fn ($m) => $this->merchantCard($m));
        if ($request->boolean('open_now')) {
            $list = $list->filter(fn ($m) => $m['is_open'] === true)->values();
        }

        // 当前类目下的区域选项（前端「全部区域」下拉）
        $areas = LocalLifeMerchant::where('status', true)
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->whereNotNull('area')->distinct()->orderBy('area')->pluck('area')->values();

        return response()->json([
            'total_size' => $total,
            'limit'      => $limit,
            'offset'     => $offset,
            'areas'      => $areas,
            'merchants'  => $list,
        ], 200);
    }

    /**
     * 商家详情（公开只读）。纯信息展示，不含任何支付/下单入口。
     * GET /api/v1/local-life/merchants/{id}
     */
    public function merchantDetail(Request $request, $id)
    {
        $m = LocalLifeMerchant::where('status', true)->find($id);
        if (!$m) {
            return response()->json(['errors' => [['code' => 'merchant', 'message' => '商家不存在或已下线']]], 404);
        }
        $data = $this->merchantCard($m);
        $data['intro']             = $m->intro;
        $data['services']          = is_array($m->services) ? $m->services : [];
        $data['images']            = $this->merchantImageUrls($m->images);
        $data['wechat_qr_url']     = $m->wechat_qr ? Helpers::get_full_url(self::MERCHANT_IMG_DIR, $m->wechat_qr, 'public') : null;
        $data['address']           = $m->address;
        $data['latitude']          = $m->latitude;
        $data['longitude']         = $m->longitude;
        $data['hours_note']        = $m->hours_note;
        $data['google_rating_url'] = $m->google_rating_url;
        $data['contacts']          = $m->normalizedContacts();
        return response()->json($data, 200);
    }

    /** 商家卡片公共字段（列表 + 详情共用） */
    private function merchantCard(LocalLifeMerchant $m): array
    {
        return [
            'id'            => $m->id,
            'name'          => $m->name,
            'category'      => $m->category,
            'logo_url'      => $m->logo ? Helpers::get_full_url(self::MERCHANT_IMG_DIR, $m->logo, 'public') : null,
            'rating'        => (float) $m->rating,
            'google_rating' => $m->google_rating !== null ? (float) $m->google_rating : null,
            'area'          => $m->area,
            'is_sensitive'  => (bool) $m->is_sensitive,
            'has_offer'     => (bool) $m->has_offer,
            'offer_text'    => $m->offer_text,
            'is_open'       => $m->isOpenNow(),
            'today_hours'   => $m->todayHoursLabel(),
        ];
    }

    private function merchantImageUrls($images): array
    {
        if (empty($images)) {
            return [];
        }
        $listImgs = is_array($images) ? $images : (json_decode($images, true) ?: []);
        $urls = [];
        foreach ($listImgs as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $url = Helpers::get_full_url(self::MERCHANT_IMG_DIR, basename($name), 'public');
            if ($url) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /** 发帖类目白名单：后台启用中的类目名 ∪ 旧常量（兼容历史帖；表空时回退常量） */
    private function categoryNames(): array
    {
        $db = LocalLifeCategory::activeNames();
        $names = array_values(array_unique(array_merge($db, self::CATEGORIES)));
        return $names ?: self::CATEGORIES;
    }

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
            'category'       => ['required', 'string', 'in:' . implode(',', $this->categoryNames())],
            'tab'            => ['required', 'string', 'in:' . implode(',', self::TABS)],
            'description'    => 'nullable|string|max:2000',
            // 结构化联系方式(批1)：method+value 为主；contact_info 保留兼容旧客户端。二者至少一组，见下方 composeContact。
            'contact_method' => ['nullable', 'string', 'in:微信,电话,WhatsApp,Telegram'],
            'contact_value'  => 'nullable|string|max:200',
            'contact_info'   => 'nullable|string|max:200',
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

        // 结构化联系方式(批1)：优先 method+value 合成展示串，回退旧客户端自由文本 contact_info。
        [$contactInfo, $contactMethod, $contactValue] = $this->composeContact($request);
        if ($contactInfo === '') {
            return response()->json(['errors' => [['code' => 'contact_value', 'message' => '请填写联系方式，否则别人无法联系你']]], 422);
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

        // 违禁词过滤（命中即拒，不转待审核）：扫 title + description + 合成后的联系方式
        $scan = trim($request->title . "\n" . (string) $request->description . "\n" . $contactInfo);
        if ($this->hitsBannedWord($scan)) {
            // 不回显命中词，避免被试探绕过
            return response()->json(['errors' => [['code' => 'banned', 'message' => '内容含违规词，请修改后再发布']]], 422);
        }

        // PII 同意守卫：仅当运营开启《个人数据处理通知》采集时强制(默认关→永不触发，行为不变)
        if ($this->piiConsentRequired() && !$request->boolean('agree_pii')) {
            return response()->json(['errors' => [['code' => 'agree_pii', 'message' => '请阅读并同意《个人数据处理通知》后再发布']]], 422);
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
            'contact_info'   => $contactInfo,
            'contact_method' => $contactMethod,
            'contact_value'  => $contactValue,
            // 单时钟(业主 2026-07-07 批准)：expires_at=60 天，既是上架寿命又是 PII 到期清锚点(L1-7)
            'expires_at'     => Carbon::now()->addDays(60),
            'status'         => LocalLifePost::STATUS_PENDING,
            'listing_status' => LocalLifePost::LISTING_ACTIVE,
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
                $arr['status_label']    = $p->statusLabel();     // 审核态中文
                $arr['lifecycle_label'] = $p->lifecycleLabel();  // 生命周期态中文(在售/已成交/已失效)
                // 续期资格：未超 180 天总寿命硬顶(封顶 PII 留存, L1-7)
                $arr['renewable'] = $p->created_at
                    ? Carbon::now()->lt($p->created_at->copy()->addDays(180))
                    : true;
                $arr['image_urls'] = $this->imageUrls($p->images);
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

    /**
     * 接口：举报商家（复用帖举报理由白名单 + 每日上限 + 防重，target=merchant）。
     * POST /api/v1/local-life/merchants/{id}/report  (auth:api)
     * L1-1：仅举报记录，不碰钱。
     */
    public function reportMerchant(Request $request, $id)
    {
        $userId = auth('api')->id();

        $merchant = LocalLifeMerchant::where('status', true)->find($id);
        if (!$merchant) {
            return response()->json(['errors' => [['code' => 'merchant', 'message' => '商家不存在或已下线']]], 404);
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

        // 每日举报上限（与帖举报共用同一计数口径）
        $reportLimit = (int) $this->setting('locallife_report_daily_limit', 20);
        $reportLimit = $reportLimit > 0 ? $reportLimit : 20;
        $todayReports = LocalLifeReport::where('user_id', $userId)
            ->where('created_at', '>=', Carbon::today())
            ->count();
        if ($todayReports >= $reportLimit) {
            return response()->json(['errors' => [['code' => 'limit', 'message' => '今日举报已达上限，请明天再试']]], 429);
        }

        // 去重：同用户对同商家已有未处理举报 → 友好提示
        $exists = LocalLifeReport::where('merchant_id', $merchant->id)
            ->where('user_id', $userId)
            ->where('status', LocalLifeReport::STATUS_PENDING)
            ->exists();
        if ($exists) {
            return response()->json(['message' => '你已举报过该商家，我们会尽快处理'], 200);
        }

        LocalLifeReport::create([
            'merchant_id' => $merchant->id,
            'post_id'     => null,
            'user_id'     => $userId,
            'reason'      => $request->reason,
            'detail'      => $request->input('detail') ?: null,
            'status'      => LocalLifeReport::STATUS_PENDING,
        ]);

        return response()->json(['message' => '已收到举报，感谢反馈'], 200);
    }

    /* =================== 我的发布·生命周期动作(auth:api, 仅本人 UGC 帖) =================== */

    /** 仅取当前登录用户自己的 UGC 帖(source=user)——对象级鉴权，防越权改他人帖(IDOR)。 */
    private function ownedPost($id): ?LocalLifePost
    {
        return LocalLifePost::where('user_id', auth('api')->id())
            ->where('source', 'user')
            ->find($id);
    }

    /**
     * 接口 G：标记成交  POST /api/v1/local-life/posts/{id}/mark-sold
     * listing_status -> sold(已成交)；仅本人帖。不动审核态、不碰 expires_at/PII。
     */
    public function markSold(Request $request, $id)
    {
        $post = $this->ownedPost($id);
        if (!$post) {
            return response()->json(['errors' => [['code' => 'post', 'message' => '帖子不存在']]], 404);
        }
        $post->listing_status = LocalLifePost::LISTING_SOLD;
        $post->save();
        return response()->json(['message' => '已标记为成交', 'listing_status' => 'sold'], 200);
    }

    /**
     * 接口 H：下架  POST /api/v1/local-life/posts/{id}/take-down
     * listing_status -> expired(已失效)；仅本人帖。信息流即不再展示，直链显终态章。
     */
    public function takeDown(Request $request, $id)
    {
        $post = $this->ownedPost($id);
        if (!$post) {
            return response()->json(['errors' => [['code' => 'post', 'message' => '帖子不存在']]], 404);
        }
        $post->listing_status = LocalLifePost::LISTING_EXPIRED;
        $post->save();
        return response()->json(['message' => '已下架', 'listing_status' => 'expired'], 200);
    }

    /**
     * 接口 I：续期  POST /api/v1/local-life/posts/{id}/renew
     * listing_status -> active + expires_at 重置 now+60 天(封顶 created_at+180 天硬顶)。
     * - 联系方式已被 PII 到期清(contact_info 空) → 拒绝，引导重发(空联系方式的活帖无意义)。
     * - 超 180 天总寿命 → 拒绝，引导重发(封顶 PII 留存, L1-7)。
     * expired / sold 态均可续回 active(HANDOFF §C.10)。
     */
    public function renew(Request $request, $id)
    {
        $post = $this->ownedPost($id);
        if (!$post) {
            return response()->json(['errors' => [['code' => 'post', 'message' => '帖子不存在']]], 404);
        }
        if (trim((string) $post->contact_info) === '') {
            return response()->json(['errors' => [['code' => 'purged', 'message' => '联系方式已过期清除，请重新发布']]], 422);
        }
        $created = $post->created_at ?: Carbon::now();
        $hardCap = $created->copy()->addDays(180);
        if (Carbon::now()->gte($hardCap)) {
            return response()->json(['errors' => [['code' => 'cap', 'message' => '该信息已达最长展示期（180 天），请重新发布']]], 422);
        }
        $newExpiry = Carbon::now()->addDays(60);
        if ($newExpiry->gt($hardCap)) {
            $newExpiry = $hardCap; // 不越过总寿命硬顶
        }
        $post->listing_status = LocalLifePost::LISTING_ACTIVE;
        $post->expires_at = $newExpiry;
        $post->save();
        return response()->json([
            'message'        => '已续期，将继续展示',
            'listing_status' => 'active',
            'expires_at'     => $post->expires_at->toIso8601String(),
        ], 200);
    }

    /* ============================ 内部工具 ============================ */

    /**
     * 结构化联系方式 → [合并展示串 contact_info, method, value]。
     * 新前端传 contact_method(微信/电话/WhatsApp/Telegram)+contact_value；
     * 旧客户端只传自由文本 contact_info → 原样保留、method/value 置 null(降级)。
     * contact_info 始终有合并串：供后台展示 / 旧详情渲染 / PII 到期清一致(单一 PII 载体)。
     */
    private function composeContact(Request $request): array
    {
        $method = trim((string) $request->input('contact_method'));
        $value  = trim((string) $request->input('contact_value'));
        $allowed = ['微信', '电话', 'WhatsApp', 'Telegram'];
        if ($method !== '' && in_array($method, $allowed, true) && $value !== '') {
            return [$method . '：' . $value, $method, $value];
        }
        // 回退旧自由文本
        $free = trim((string) $request->input('contact_info'));
        return [$free, null, null];
    }

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
        return $this->localizedSetting('locallife_disclaimer', self::DEFAULT_DISCLAIMER);
    }

    private function termsText(): string
    {
        return $this->localizedSetting('locallife_terms', self::DEFAULT_TERMS);
    }

    /**
     * 《个人数据处理通知》(PII 同意文案)。多语言键 locallife_pii_notice{,_en,_ru,_hy}。
     * 默认无任何配置 → 返回空串；正式上线须先有注册主体(数据控制者=公司全称+注册地址)+律师审校。
     */
    private function piiNoticeText(): string
    {
        return $this->localizedSetting('locallife_pii_notice', '');
    }

    /**
     * 发帖时是否强制勾选《个人数据处理通知》同意。两道门同时满足才生效：
     * ① 运营显式开启 locallife_pii_consent_enabled；② 通知文本非空。
     * 默认开关关 → 永远 false → 不采集新同意，行为与今天一致(零变化)。
     */
    private function piiConsentRequired(): bool
    {
        $enabled = (bool) $this->setting('locallife_pii_consent_enabled', '0');
        return $enabled && trim($this->piiNoticeText()) !== '';
    }

    /**
     * 按当前 UI 语言(X-localization)解析文案：该语种专属键(_en/_ru/_hy)非空→用之；
     * 否则回退中文基键；再回退代码默认 $fallback。任一语种键留空 = 自动回退中文，
     * 故"只搭多语言骨架、暂不灌俄/亚语文案"对线上零影响。
     */
    private function localizedSetting(string $baseKey, string $fallback): string
    {
        $suffix = $this->langSuffix();
        if ($suffix !== '') {
            $v = $this->setting($baseKey . $suffix, null);
            if ($v !== null && trim($v) !== '') {
                return $v;
            }
        }
        $v = $this->setting($baseKey, null);
        return ($v !== null && trim($v) !== '') ? $v : $fallback;
    }

    /**
     * X-localization → 文案键后缀。中文用无后缀基键(locallife_terms)，未知语言一律回退中文。
     */
    private function langSuffix(): string
    {
        $loc = strtolower((string) (request()->header('X-localization') ?: app()->getLocale() ?: 'zh'));
        if (str_starts_with($loc, 'en')) {
            return '_en';
        }
        if (str_starts_with($loc, 'ru')) {
            return '_ru';
        }
        if (str_starts_with($loc, 'hy') || str_starts_with($loc, 'am') || str_starts_with($loc, 'arm')) {
            return '_hy';
        }
        return ''; // zh-CN / zh / 未知 → 中文基键
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
        // 统一走共享筛查器（与本地生活商家录入同一套词库，避免两套词库漂移）
        return \App\CentralLogics\NezhaContentScreen::words();
    }

    private function setting(string $key, $default)
    {
        $v = DB::table('business_settings')->where('key', $key)->value('value');
        return $v === null ? $default : $v;
    }
}
