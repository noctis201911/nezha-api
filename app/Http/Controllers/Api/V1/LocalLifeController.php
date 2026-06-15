<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\LocalLifePost;
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

    /* ============================ 公开只读 ============================ */

    /**
     * 接口 A：本地生活帖子列表
     * GET /api/v1/local-life/posts?tab=推荐&limit=20&offset=1
     * 只返回已发布(status=1)，按 created_at DESC 分页。绝不返回 contact_info。
     * 附带 ugc_enabled（前端 FAB 据此决定"即将开放"还是打开发帖表单）。
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
            'total_size'  => $total,
            'limit'       => $limit,
            'offset'      => $offset,
            'ugc_enabled' => $this->ugcEnabled(),
            'posts'       => $posts,
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

        return response()->json($data, 200);
    }

    /* ============================ 需登录(auth:api) ============================ */

    /**
     * 接口 C：用户发帖
     * POST /api/v1/local-life/posts  (auth:api)
     * 落库 status=3(待审核)、source='user'、user_id=当前用户、expires_at=+30天。
     * L1-1: 仅信息墙——绝不引入支付/押金/代收/下单/担保任何字段。
     */
    public function storePost(Request $request)
    {
        $userId = auth('api')->id();

        // 总开关：未开放则拒绝发帖（与前端 FAB 双保险）
        if (!$this->ugcEnabled()) {
            return response()->json(['errors' => [['code' => 'closed', 'message' => '发帖功能暂未开放']]], 403);
        }

        // 每用户每日发帖上限（基础反刷，更强的反滥用顺延窗口⑤）
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
     * 返回当前用户的全部帖子（含待审核/已发布/已驳回/已下线），带 statusLabel。
     * 自己的 contact_info 可返回；图片拼成完整 URL。
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
            // 文件名形如 local-life/xxx.webp 或 xxx.webp，统一只取 basename 交给 get_full_url 拼
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

    private function setting(string $key, $default)
    {
        $v = DB::table('business_settings')->where('key', $key)->value('value');
        return $v === null ? $default : $v;
    }
}
