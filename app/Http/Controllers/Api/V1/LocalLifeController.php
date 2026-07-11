<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\LocalLifePost;
use App\Models\LocalLifeCategory;
use App\Models\LocalLifeMerchant;
use App\Models\LocalLifeMerchantNote;
use App\Models\LocalLifeReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LocalLifeController extends Controller
{
    // 列表接口对外暴露的字段（不含 contact_info / expires_at / user_id 等内部字段）
    // attrs 纳入：列表卡摘要行「户型 · 面积 · 区」需 attrs.layout（§3.3）。
    private const LIST_FIELDS = [
        'id', 'title', 'category', 'tab', 'cover_emoji', 'cover_color', 'images',
        'price_amd', 'price_suffix', 'is_free', 'area_label', 'location_label', 'attrs',
        'is_urgent', 'want_count', 'created_at',
    ];

    // ── 租房民宿结构化字段（HANDOFF §2）：enum 存英文 key，中文 label 在前端 llRentalAttrs.js。
    //    改这里的枚举务必同步前端常量表（同 maxImagesFor 惯例）。
    private const RENTAL_CATEGORY = '租房民宿';
    private const RENT_TYPES   = ['whole', 'shared', 'short'];
    private const LAYOUTS      = ['studio', '1b1l', '2b1l', '3b1l', '4plus'];
    private const HOUSE_TYPES  = ['apartment', 'house', 'other'];
    private const DEPOSITS     = ['none', 'one_month', 'nego'];
    private const AVAILABLES   = ['now', 'date'];
    private const BILLS        = ['water', 'electric', 'gas', 'internet', 'hoa'];
    private const AMENITIES    = ['furniture', 'washer', 'fridge', 'ac', 'heating', 'elevator', 'parking', 'balcony', 'private_bath', 'kitchen'];
    private const TENANT_REQS  = ['no_smoking', 'pets_ok', 'female_only', 'male_only', 'registration_ok'];
    private const LANGUAGES    = ['zh', 'en', 'ru', 'hy'];

    // 图片存储目录（与 Helpers::upload 一致）
    private const IMG_DIR = 'local-life';
    private const MERCHANT_IMG_DIR = 'local-life-merchant';
    private const NOTE_IMG_DIR = 'local-life-note'; // 笔记图（批N·走 posts 同一上传管线，独立目录便于区分）

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
    private const DEFAULT_DISCLAIMER = '本地生活信息由用户发布，请核实信息、注意线下交易安全。';

    // 《本地生活信息发布规则》全文（后台 locallife_terms 可覆盖以便微调）
    private const DEFAULT_TERMS = "哪吒本地生活信息发布规则\n\n1. 本频道为信息发布与展示服务，供需双方自行联系、线下自主交易。\n\n2. 帖子内容由用户发布，请核实信息、谨慎交易，线下见面注意人身与财产安全。\n\n3. 禁止发布以下内容：违法犯罪、诈骗、色情、赌博、毒品、枪支等违禁信息；虚假、冒用他人身份或联系方式的信息；骚扰、辱骂、歧视、侵犯他人隐私或知识产权的内容；与本地生活无关的垃圾广告或恶意引流。\n\n4. 你发布的联系方式仅对登录用户在帖子详情可见，并将在帖子到期后自动清除。请勿在帖子中填写他人信息或敏感个人资料。\n\n5. 哪吒有权对违反本规则的帖子不予通过、下线或删除，并视情况限制相关账号的发布权限。\n\n6. 提交发布即表示你已阅读并同意本规则。";

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

        // 真实浏览计数(§2/岔口③)：同 IP 6h 去重、从 1 次即显示。计数失败不影响详情展示。
        $this->bumpViews($post, 'post');

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
        // 真实浏览计数(§2b)：与帖子完全同套(同 IP 6h 去重 · 从 1 显示)。
        $this->bumpViews($m, 'merchant');

        $data = $this->merchantCard($m);
        $data['intro']             = $m->intro;
        // 房型卡(§2b)：services 每项可选 image(→URL) + attrs(layout/area_label/amenities 子集)；未填的项前端回落文字行。
        $data['services']          = $this->decorateServices($m->services);
        $data['images']            = $this->merchantImageUrls($this->imagesCoverFirst($m));
        $data['wechat_qr_url']     = $m->wechat_qr ? Helpers::get_full_url(self::MERCHANT_IMG_DIR, $m->wechat_qr, 'public') : null;
        $data['address']           = $m->address;
        $data['latitude']          = $m->latitude;
        $data['longitude']         = $m->longitude;
        $data['hours_note']        = $m->hours_note;
        $data['google_rating_url'] = $m->google_rating_url;
        $data['contacts']          = $m->normalizedContacts();
        $data['views']             = (int) $m->views;
        // 笔记预览(批N §④-8)：最新 4 条过审笔记 + 总数。总闸关 / 无过审 → 空(前端整卡不渲染)。
        $data['notes_preview']     = $this->notesPreviewFor($m);
        // 店内视频外链卡(档1 §④-1)：总闸开时透出规范化外链；闸关/空 → [](前端整卡不渲染)。
        $data['video_links']       = $this->videoEnabled() ? $m->normalizedVideoLinks() : [];
        return response()->json($data, 200);
    }

    /**
     * 笔记预览(批N)：详情页笔记卡用。返回 {total, items:[最新4条过审]}。
     * 总闸 nezha_merchant_notes_status=0 或无过审笔记 → {total:0, items:[]}（前端整卡隐）。
     */
    private function notesPreviewFor(LocalLifeMerchant $m): array
    {
        if (!$this->notesEnabled()) {
            return ['total' => 0, 'items' => []];
        }
        $total = LocalLifeMerchantNote::where('merchant_id', $m->id)
            ->where('status', LocalLifeMerchantNote::STATUS_APPROVED)->count();
        if ($total === 0) {
            return ['total' => 0, 'items' => []];
        }
        $items = LocalLifeMerchantNote::where('merchant_id', $m->id)
            ->where('status', LocalLifeMerchantNote::STATUS_APPROVED)
            ->with('user:id,f_name,l_name,image')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(4)->get()
            ->map(fn ($n) => $this->notePublicPayload($n, $m))->all();
        return ['total' => $total, 'items' => $items];
    }

    /**
     * services 展示装饰(§2b 房型卡)：保留 title/desc/price_text 原样，
     * 若某项含 image(文件名) → 附 image_url；若含 attrs(layout/area_label/amenities) → 原样透传(前端映射中文)。
     * 未含 image/attrs 的项 = 原状文字行(零迁移)。
     */
    private function decorateServices($services): array
    {
        if (!is_array($services)) {
            return [];
        }
        $out = [];
        foreach ($services as $s) {
            if (!is_array($s)) {
                continue;
            }
            $item = [
                'title'      => $s['title'] ?? null,
                'desc'       => $s['desc'] ?? null,
                'price_text' => $s['price_text'] ?? null,
            ];
            $img = trim((string) ($s['image'] ?? ''));
            if ($img !== '') {
                $item['image_url'] = Helpers::get_full_url(self::MERCHANT_IMG_DIR, basename($img), 'public');
            }
            if (isset($s['attrs']) && is_array($s['attrs']) && !empty($s['attrs'])) {
                $item['attrs'] = $s['attrs'];
            }
            // 招牌标（v3 §④-4）：透传 featured 布尔，前端加「招牌」tag + 组内置顶（缺失=无 tag）
            if (!empty($s['featured'])) {
                $item['featured'] = true;
            }
            $out[] = $item;
        }
        return $out;
    }

    /** 商家卡片公共字段（列表 + 详情共用） */
    private function merchantCard(LocalLifeMerchant $m): array
    {
        return [
            'id'            => $m->id,
            'name'          => $m->name,
            'category'      => $m->category,
            'logo_url'      => $m->logo ? Helpers::get_full_url(self::MERCHANT_IMG_DIR, $m->logo, 'public') : null,
            'cover_image_url' => $this->coverImageUrl($m),
            'rating'        => (float) $m->rating,
            'google_rating' => $m->google_rating !== null ? (float) $m->google_rating : null,
            'google_rating_count' => $m->google_rating_count !== null ? (int) $m->google_rating_count : null,
            'area'          => $m->area,
            'latitude'      => $m->latitude !== null ? (float) $m->latitude : null,
            'longitude'     => $m->longitude !== null ? (float) $m->longitude : null,
            'is_sensitive'  => (bool) $m->is_sensitive,
            'has_offer'     => (bool) $m->has_offer,
            'offer_text'    => $m->offer_text,
            'is_open'       => $m->isOpenNow(),
            'today_hours'   => $this->todayHoursFor($m),
            // 瀑布流商户伪帖卡「X 起」价(§2b)：取 services 第一个可解析项 → {amount,suffix}；无则 null(价格区留空)
            'price_from'    => $this->firstServicePrice($m),
            // 好店列表卡服务锚点(v3 §④-5)：招牌优先/前 2 项可解析价目 [{title,amount,suffix}]；无可解析项则 []
            'service_anchors' => $this->serviceAnchors($m),
        ];
    }

    /**
     * 好店列表卡「服务锚点行」(v3 §④-5)：最多 2 项 {title,amount,suffix}。
     * 只取 price_text 可解析(前导数字)的服务；featured 优先(稳定序)、否则原序前 2。
     * 全不可解析(如雅顺全"面议") → 空数组，前端整行隐。
     */
    private function serviceAnchors(LocalLifeMerchant $m): array
    {
        $services = is_array($m->services) ? $m->services : [];
        $parseable = [];
        foreach ($services as $idx => $s) {
            if (!is_array($s)) {
                continue;
            }
            $pt = trim((string) ($s['price_text'] ?? ''));
            if ($pt === '' || !preg_match('/^\s*([\d,]+)/', $pt, $mm)) {
                continue;
            }
            $amount = (int) str_replace(',', '', $mm[1]);
            if ($amount <= 0) {
                continue;
            }
            $suffix = trim(preg_replace('/^\s*(֏|AMD|amd|դր\.?|драм)\s*/iu', '', trim(substr($pt, strlen($mm[0])))));
            $suffix = trim(preg_replace('/\s*起(订)?\s*$/u', '', $suffix));
            $parseable[] = [
                'title'    => trim((string) ($s['title'] ?? '')),
                'amount'   => $amount,
                'suffix'   => $suffix,
                'featured' => !empty($s['featured']),
                'idx'      => $idx,
            ];
        }
        // 招牌优先，其余保持原序（稳定）
        usort($parseable, function ($a, $b) {
            if ($a['featured'] !== $b['featured']) {
                return $b['featured'] <=> $a['featured'];
            }
            return $a['idx'] <=> $b['idx'];
        });
        return array_map(
            fn ($x) => ['title' => $x['title'], 'amount' => $x['amount'], 'suffix' => $x['suffix']],
            array_slice($parseable, 0, 2)
        );
    }

    /**
     * services 第一个「可解析」项的起价(§2b 伪帖卡)。price_text 前导数字才算可解析(如"350000֏ /月")，
     * 返回 ['amount'=>int,'suffix'=>string]；"面议"等无数字 → 跳过；全不可解析 → null(前端留空)。
     * 运营 SOP：把主推房型放第一位（ADMIN_GUIDE）。
     */
    private function firstServicePrice(LocalLifeMerchant $m): ?array
    {
        $services = is_array($m->services) ? $m->services : [];
        foreach ($services as $s) {
            if (!is_array($s)) {
                continue;
            }
            $pt = trim((string) ($s['price_text'] ?? ''));
            if ($pt === '' || !preg_match('/^\s*([\d,]+)/', $pt, $mm)) {
                continue;
            }
            $amount = (int) str_replace(',', '', $mm[1]);
            if ($amount <= 0) {
                continue;
            }
            // 去掉前导金额 + 货币符，剩「/月」「/晚」等；再剥尾部「起/起订」(前端统一补「起」，避免"/月 起 起")
            $suffix = trim(preg_replace('/^\s*(֏|AMD|amd|դր\.?|драм)\s*/iu', '', trim(substr($pt, strlen($mm[0])))));
            $suffix = trim(preg_replace('/\s*起(订)?\s*$/u', '', $suffix));
            return ['amount' => $amount, 'suffix' => $suffix];
        }
        return null;
    }

    /**
     * 营业时间行文字(§2b 治理)：租房民宿类目且完全无营业时间数据(open/close/hours_note 皆空) → null，
     * 前端整行不显(§8 无数据不显·租房房源本无"营业时间"概念)。其他类目沿用现状兜底文案，不受影响。
     */
    private function todayHoursFor(LocalLifeMerchant $m): ?string
    {
        if (trim((string) $m->category) === self::RENTAL_CATEGORY
            && empty($m->open_time) && empty($m->close_time) && empty($m->hours_note)) {
            return null;
        }
        return $m->todayHoursLabel();
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

    /**
     * 门面图三位一体（§5）：cover_image 若为相册成员则置于首位、其余原序跟后（去重），
     * 未设或非成员则原序返回。详情页 hero 首图与 og 分享卡门面(merchantHeroPath)同源。
     */
    private function imagesCoverFirst(LocalLifeMerchant $m): array
    {
        $imgs = is_array($m->images)
            ? array_values(array_filter($m->images, fn ($x) => is_string($x) && $x !== ''))
            : [];
        $cover = trim((string) ($m->cover_image ?? ''));
        if ($cover !== '' && in_array($cover, $imgs, true)) {
            $rest = array_values(array_filter($imgs, fn ($x) => $x !== $cover));
            return array_merge([$cover], $rest);
        }
        return $imgs;
    }

    /**
     * 门面图 URL（§5·列表卡缩略图优先用它，回落 logo）。
     * cover_image 为相册成员时返回其 URL，否则 null（与 og 卡同一守卫，防悬空文件名 404）。
     */
    private function coverImageUrl(LocalLifeMerchant $m): ?string
    {
        $cover = trim((string) ($m->cover_image ?? ''));
        if ($cover === '') {
            return null;
        }
        $imgs = is_array($m->images) ? $m->images : [];
        if (!in_array($cover, $imgs, true)) {
            return null;
        }
        return Helpers::get_full_url(self::MERCHANT_IMG_DIR, basename($cover), 'public');
    }

    /** 发帖类目白名单：后台启用中的类目名 ∪ 旧常量（兼容历史帖；表空时回退常量） */
    private function categoryNames(): array
    {
        $db = LocalLifeCategory::activeNames();
        $names = array_values(array_unique(array_merge($db, self::CATEGORIES)));
        return $names ?: self::CATEGORIES;
    }

    // 发帖图片上限按类目分档：租房民宿房源需多图(房型/客厅/厨卫/楼体)放宽到 15，其余 6。
    // 与前端 PostFormDrawer.jsx maxImagesFor 一致；改一处务必同步另一处。
    private const MAX_IMAGES_DEFAULT = 6;
    private const MAX_IMAGES_BY_CATEGORY = ['租房民宿' => 15];
    private function maxImagesFor(?string $category): int
    {
        return self::MAX_IMAGES_BY_CATEGORY[trim((string) $category)] ?? self::MAX_IMAGES_DEFAULT;
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

        $maxImages = $this->maxImagesFor($request->input('category'));
        // 租房民宿类目：所在区(location_label)升为必填（HANDOFF §2）；其他类目保持选填。
        $isRental = trim((string) $request->input('category')) === self::RENTAL_CATEGORY;

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
            'location_label' => ($isRental ? 'required|' : 'nullable|') . 'string|max:60',
            'images'         => 'nullable|array|max:' . $maxImages,
            'images.*'       => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ], [
            'title.required'        => '请填写标题',
            'category.required'     => '请选择分类',
            'category.in'           => '分类不在允许范围',
            'tab.required'          => '请选择频道',
            'tab.in'                => '频道不在允许范围',
            'location_label.required' => '请选择所在区',
            'contact_info.required' => '请填写联系方式，否则别人无法联系你',
            'images.max'            => '最多上传 ' . $maxImages . ' 张图片',
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

        // 租房结构化字段(attrs)：仅租房民宿类目接受(其他类目剥离不存)；enum 白名单/数组项白名单+max/
        // available=date 须附合法日期/街道过违禁词。非法即 422（§3.2/§6）。
        [$attrs, $attrsErr] = $this->buildRentalAttrs($request);
        if ($attrsErr) {
            return response()->json(['errors' => $attrsErr], 422);
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
            'attrs'          => $attrs,
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

    /* ============================ 笔记（批N · 图文内容层） ============================ */

    /**
     * 接口：某商家的笔记列表（公开只读，分页 10）。
     * GET /api/v1/local-life/merchants/{id}/notes?page=1
     * 只返回 status=1(过审)，过审时间倒序。总闸关 → 空列表。绝不返回待审/驳回/下架笔记。
     */
    public function merchantNotes(Request $request, $id)
    {
        $m = LocalLifeMerchant::where('status', true)->find($id);
        if (!$m) {
            return response()->json(['errors' => [['code' => 'merchant', 'message' => '商家不存在或已下线']]], 404);
        }
        if (!$this->notesEnabled()) {
            return response()->json(['notes' => [], 'total' => 0, 'page' => 1, 'has_more' => false], 200);
        }
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 10;
        $total   = LocalLifeMerchantNote::where('merchant_id', $m->id)
            ->where('status', LocalLifeMerchantNote::STATUS_APPROVED)->count();
        $rows = LocalLifeMerchantNote::where('merchant_id', $m->id)
            ->where('status', LocalLifeMerchantNote::STATUS_APPROVED)
            ->with('user:id,f_name,l_name,image')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($page, $perPage)->get();
        $notes = $rows->map(fn ($n) => $this->notePublicPayload($n, $m))->all();
        return response()->json([
            'notes'    => $notes,
            'total'    => $total,
            'page'     => $page,
            'has_more' => $page * $perPage < $total,
        ], 200);
    }

    /**
     * 接口：客户写笔记。
     * POST /api/v1/local-life/merchants/{id}/notes  (auth:api)
     * 落库 status=0(待审)、author_type=customer、user_id=当前用户。人工审核后展示。
     * 护栏顺序：总开关 → 每用户每商家每日上限(命名·作用域计数) → 字段校验(图≥1) → 联系方式拦截 → 违禁词。
     * L1-1：仅信息，无任何交易字段。§②-4：笔记内禁联系方式（表单提示 + 本层模式拦截 + 违禁词 + 人工审）。
     */
    public function storeNote(Request $request, $id)
    {
        $userId = auth('api')->id();

        if (!$this->notesEnabled()) {
            return response()->json(['errors' => [['code' => 'closed', 'message' => '笔记功能暂未开放']]], 403);
        }

        $m = LocalLifeMerchant::where('status', true)->find($id);
        if (!$m) {
            return response()->json(['errors' => [['code' => 'merchant', 'message' => '商家不存在或已下线']]], 404);
        }

        // 命名 throttle：每用户每商家每日 ≤2（作用域全限定计数，不与其它 throttle 共用 key）
        $todayCount = LocalLifeMerchantNote::where('user_id', $userId)
            ->where('merchant_id', $m->id)
            ->where('author_type', LocalLifeMerchantNote::AUTHOR_CUSTOMER)
            ->where('created_at', '>=', Carbon::today())
            ->count();
        if ($todayCount >= 2) {
            return response()->json(['errors' => [['code' => 'limit', 'message' => '今日笔记次数已达上限，请明天再试']]], 429);
        }

        $validator = Validator::make($request->all(), [
            'title'    => 'nullable|string|max:30',
            'body'     => 'required|string|max:500',
            'images'   => 'required|array|min:1|max:9',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ], [
            'body.required'   => '请填写笔记正文',
            'body.max'        => '正文最多 500 字',
            'title.max'       => '标题最多 30 字',
            'images.required' => '请至少上传 1 张图片',
            'images.min'      => '请至少上传 1 张图片',
            'images.max'      => '最多上传 9 张图片',
            'images.*.image'  => '只能上传图片',
            'images.*.mimes'  => '图片格式仅支持 jpg/png/webp',
            'images.*.max'    => '单张图片不能超过 5MB',
        ]);
        if ($validator->fails()) {
            $errs = [];
            foreach ($validator->errors()->getMessages() as $field => $msgs) {
                $errs[] = ['code' => $field, 'message' => $msgs[0]];
            }
            return response()->json(['errors' => $errs], 422);
        }

        $scan = trim((string) $request->title . "\n" . (string) $request->body);

        // 联系方式拦截(§②-4)：笔记内禁联系方式（电话/微信号/@handle/wa.me/t.me/网址）——命中即拒
        if ($this->looksLikeContact($scan)) {
            return response()->json(['errors' => [['code' => 'contact', 'message' => '笔记内请勿填写联系方式（电话/微信/链接等），联系请走商家联系卡']]], 422);
        }

        // 违禁词过滤（命中即拒，不转待审）
        if ($this->hitsBannedWord($scan)) {
            return response()->json(['errors' => [['code' => 'banned', 'message' => '内容含违规词，请修改后再发布']]], 422);
        }

        // 图片上传（jpg/png 自动转 webp）；仅存文件名数组。图 1–9 张，至少 1 张。
        $imageNames = [];
        foreach ($request->file('images') as $file) {
            $imageNames[] = Helpers::upload(self::NOTE_IMG_DIR . '/', 'webp', $file);
        }

        LocalLifeMerchantNote::create([
            'merchant_id' => $m->id,
            'author_type' => LocalLifeMerchantNote::AUTHOR_CUSTOMER,
            'user_id'     => $userId,
            'title'       => $request->filled('title') ? trim((string) $request->title) : null,
            'body'        => trim((string) $request->body),
            'images'      => $imageNames,
            'status'      => LocalLifeMerchantNote::STATUS_PENDING,
        ]);

        return response()->json(['message' => '已提交，审核通过后会展示在商家页'], 200);
    }

    /**
     * 接口：举报某条笔记（复用帖举报理由白名单 + 每日上限 + 防重，target=note）。
     * POST /api/v1/local-life/notes/{id}/report  (auth:api)
     */
    public function reportNote(Request $request, $id)
    {
        $userId = auth('api')->id();

        $note = LocalLifeMerchantNote::where('status', LocalLifeMerchantNote::STATUS_APPROVED)->find($id);
        if (!$note) {
            return response()->json(['errors' => [['code' => 'note', 'message' => '笔记不存在或已下架']]], 404);
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

        // 每日举报上限（与帖/商家举报共用同一计数口径）
        $reportLimit = (int) $this->setting('locallife_report_daily_limit', 20);
        $reportLimit = $reportLimit > 0 ? $reportLimit : 20;
        $todayReports = LocalLifeReport::where('user_id', $userId)
            ->where('created_at', '>=', Carbon::today())
            ->count();
        if ($todayReports >= $reportLimit) {
            return response()->json(['errors' => [['code' => 'limit', 'message' => '今日举报已达上限，请明天再试']]], 429);
        }

        // 去重：同用户对同笔记已有未处理举报 → 友好提示
        $exists = LocalLifeReport::where('note_id', $note->id)
            ->where('user_id', $userId)
            ->where('status', LocalLifeReport::STATUS_PENDING)
            ->exists();
        if ($exists) {
            return response()->json(['message' => '你已举报过该笔记，我们会尽快处理'], 200);
        }

        LocalLifeReport::create([
            'note_id'     => $note->id,
            'post_id'     => null,
            'merchant_id' => null,
            'user_id'     => $userId,
            'reason'      => $request->reason,
            'detail'      => $request->input('detail') ?: null,
            'status'      => LocalLifeReport::STATUS_PENDING,
        ]);

        return response()->json(['message' => '已收到举报，感谢反馈'], 200);
    }

    /** 笔记总闸（批N）：business_settings.nezha_merchant_notes_status=1 才开放。默认关。 */
    private function notesEnabled(): bool
    {
        return (string) $this->setting('nezha_merchant_notes_status', '0') === '1';
    }

    /** 店内视频卡总闸（档1）：business_settings.nezha_merchant_video_status=1 才透出。默认关。 */
    private function videoEnabled(): bool
    {
        return (string) $this->setting('nezha_merchant_video_status', '0') === '1';
    }

    /**
     * 笔记对外展示体（详情卡预览 + 全量列表 + 详情抽屉共用同一结构）。
     * 作者透明(§②-5)：商家笔记→显商家名+logo+author_type=merchant（前端加「商家」chip）；
     *   客户笔记→显昵称+头像；用户已注销(硬删)→「用户已注销」+ 头像 null（前端回落 3D 小哪吒）。
     */
    private function notePublicPayload(LocalLifeMerchantNote $n, LocalLifeMerchant $m): array
    {
        if ($n->author_type === LocalLifeMerchantNote::AUTHOR_MERCHANT) {
            $authorName   = $m->name;
            $authorAvatar = $m->logo ? Helpers::get_full_url(self::MERCHANT_IMG_DIR, $m->logo, 'public') : null;
        } else {
            $u = $n->user; // 注销后为 null
            $authorName   = $u ? trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? '')) : '';
            if ($authorName === '') {
                $authorName = $u ? '哪吒用户' : '用户已注销';
            }
            $authorAvatar = ($u && $u->image) ? $u->image_full_url : null;
        }
        $images = $this->noteImageUrls($n->images);
        return [
            'id'            => $n->id,
            'title'         => $n->title,
            'body'          => $n->body,
            'images'        => $images,
            'cover_url'     => $images[0] ?? null,
            'author_type'   => $n->author_type,
            'author_name'   => $authorName,
            'author_avatar' => $authorAvatar,
            'created_at'    => optional($n->created_at)->toIso8601String(),
            'created_label' => optional($n->created_at)->timezone('Asia/Yerevan')->format('Y-m-d'),
        ];
    }

    /** 笔记图文件名数组 → 完整 URL 数组。 */
    private function noteImageUrls($images): array
    {
        if (!is_array($images)) {
            return [];
        }
        $out = [];
        foreach ($images as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $out[] = Helpers::get_full_url(self::NOTE_IMG_DIR, basename($name), 'public');
            }
        }
        return $out;
    }

    /**
     * 联系方式启发式拦截(§②-4)：笔记禁联系方式。委托共享筛查器，与 /m 商户面同一套规则（防漂移）。
     */
    private function looksLikeContact(?string $text): bool
    {
        return \App\CentralLogics\NezhaContentScreen::looksLikeContact($text);
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

    /**
     * 校验并构建租房结构化字段(attrs)。仅 category=租房民宿 接受，其他类目返回 [null,null](剥离不存)。
     * 返回 [attrs(?array), errorPayload(?array)]；errorPayload 非空 = 422（非法 enum/超长数组/日期缺失/街道违禁）。
     * attrs 前端以 JSON 字符串提交（multipart 同表单夹带图片，嵌套数组易走样）；也容忍已解析数组。
     */
    private function buildRentalAttrs(Request $request): array
    {
        if (trim((string) $request->input('category')) !== self::RENTAL_CATEGORY) {
            return [null, null]; // 非租房类目：不存 attrs
        }
        $raw = $request->input('attrs');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [null, null];
        }

        $v = Validator::make($raw, [
            'rent_type'      => 'nullable|in:' . implode(',', self::RENT_TYPES),
            'layout'         => 'nullable|in:' . implode(',', self::LAYOUTS),
            'house_type'     => 'nullable|in:' . implode(',', self::HOUSE_TYPES),
            'deposit'        => 'nullable|in:' . implode(',', self::DEPOSITS),
            'available'      => 'nullable|in:' . implode(',', self::AVAILABLES),
            'available_date' => 'nullable|date_format:Y-m-d',
            'bills'          => 'nullable|array|max:5',
            'bills.*'        => 'in:' . implode(',', self::BILLS),
            'amenities'      => 'nullable|array|max:10',
            'amenities.*'    => 'in:' . implode(',', self::AMENITIES),
            'tenant_reqs'    => 'nullable|array|max:5',
            'tenant_reqs.*'  => 'in:' . implode(',', self::TENANT_REQS),
            'languages'      => 'nullable|array|max:4',
            'languages.*'    => 'in:' . implode(',', self::LANGUAGES),
            'street'         => 'nullable|string|max:60',
        ], [
            'in'                         => '房源信息含无效选项',
            'array'                      => '房源信息格式不正确',
            'max'                        => '房源信息所选项过多',
            'available_date.date_format' => '入住日期格式不正确',
            'street.max'                 => '街道最多 60 字',
        ]);
        $v->after(function ($val) use ($raw) {
            if (($raw['available'] ?? null) === 'date' && trim((string) ($raw['available_date'] ?? '')) === '') {
                $val->errors()->add('available_date', '选择指定日期时请填写入住日期');
            }
        });
        if ($v->fails()) {
            $errs = [];
            foreach ($v->errors()->getMessages() as $field => $msgs) {
                $errs[] = ['code' => 'attrs.' . $field, 'message' => $msgs[0]];
            }
            return [null, $errs];
        }

        // 街道是自由文本新入口 → 过违禁词(§4 红线)
        $street = trim((string) ($raw['street'] ?? ''));
        if ($street !== '' && $this->hitsBannedWord($street)) {
            return [null, [['code' => 'banned', 'message' => '内容含违规词，请修改后再发布']]];
        }

        // 只落白名单键、去空；数组去重去非法项
        $out = [];
        foreach (['rent_type', 'layout', 'house_type', 'deposit', 'available'] as $k) {
            $val = trim((string) ($raw[$k] ?? ''));
            if ($val !== '') {
                $out[$k] = $val;
            }
        }
        if (($out['available'] ?? null) === 'date' && trim((string) ($raw['available_date'] ?? '')) !== '') {
            $out['available_date'] = trim((string) $raw['available_date']);
        }
        foreach (['bills' => self::BILLS, 'amenities' => self::AMENITIES, 'tenant_reqs' => self::TENANT_REQS, 'languages' => self::LANGUAGES] as $k => $allow) {
            if (!empty($raw[$k]) && is_array($raw[$k])) {
                $arr = array_values(array_unique(array_filter($raw[$k], fn ($x) => in_array($x, $allow, true))));
                if ($arr) {
                    $out[$k] = $arr;
                }
            }
        }
        if ($street !== '') {
            $out['street'] = $street;
        }

        return [$out ?: null, null];
    }

    /**
     * 真实浏览计数(§2/§2b)：同 IP 6h 去重(redis Cache::add 原子占位)，命中才 +1；从 1 次即显示。
     * $prefix = post|merchant。计数失败(缓存不可用等)静默跳过，绝不影响详情展示。
     */
    private function bumpViews($model, string $prefix): void
    {
        try {
            $ipHash = substr(sha1((string) request()->ip()), 0, 16);
            $key = "ll_view:{$prefix}:{$model->id}:{$ipHash}";
            if (Cache::add($key, 1, now()->addHours(6))) {
                $model->increment('views');
            }
        } catch (\Throwable $e) {
            // no-op：计数是锦上添花，不阻断详情
        }
    }
}
