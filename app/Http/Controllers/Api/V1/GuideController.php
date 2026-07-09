<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Guide;
use App\Models\LocalLifeMerchant;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use League\CommonMark\CommonMarkConverter;

/**
 * 本地生活「生活攻略」公开只读 API（批2）。
 * 合规 L1-1：纯信息展示，不碰交易/收款；内嵌店卡只跳转不带促销承诺。
 * 总开关 business_settings.nezha_guides_status（默认 0 封印）：
 *   - 列表 → {enabled:false, guides:[]}
 *   - 详情 → {closed:true}（前端显空态，不 404，兼容已分享的直链）
 *   - 有用 → 404 语义
 * 正文 body_md 走 League CommonMark（html_input=strip / allow_unsafe_links=false，零新依赖）；
 * 正文内独占一行的 {{restaurant:ID}} / {{merchant:ID}} → 解析成占位符 <!--nz-card-N--> + cards[]。
 */
class GuideController extends Controller
{
    private const MERCHANT_IMG_DIR = 'local-life-merchant';
    private const RESTAURANT_IMG_DIR = 'restaurant';
    private const GUIDE_IMG_DIR = 'guide';

    /** 封面文件名 → 前端可用完整 URL（cover_url 列存文件名，同 merchant logo 口径） */
    private function coverUrl($g): ?string
    {
        return $g->cover_url ? Helpers::get_full_url(self::GUIDE_IMG_DIR, $g->cover_url, 'public') : null;
    }

    /* ============================ 公开只读 ============================ */

    /**
     * 攻略列表 GET /api/v1/local-life/guides
     * status=1，按 sort,published_at；开关=0 时回 {enabled:false, guides:[]}。
     */
    public function index(Request $request)
    {
        if (!$this->enabled()) {
            return response()->json(['enabled' => false, 'guides' => [], 'teaser' => ''], 200);
        }

        $rows = Guide::where('status', 1)
            ->orderBy('sort')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        $first = true;
        $guides = $rows->map(function ($g) use (&$first) {
            $card = [
                'id'                 => $g->id,
                'title'              => $g->title,
                'slug'               => $g->slug,
                'cover_url'          => $this->coverUrl($g),
                'summary'            => $g->summary ?: null,
                'info_as_of'         => $g->info_as_of,
                'helpful_count'      => (int) $g->helpful_count,
                'is_sensitive_topic' => (bool) $g->is_sensitive_topic,
                // 首篇（sort 最前）= 「新来必读」白胶囊 tag（MVP 写死第一篇）
                'is_new_pick'        => $first,
            ];
            $first = false;
            return $card;
        });

        return response()->json([
            'enabled' => true,
            'guides'  => $guides,
            'teaser'  => $this->entryTeaser($rows),
        ], 200);
    }

    /**
     * 攻略详情 GET /api/v1/local-life/guides/{slug}
     * 开关=0 回 {closed:true}；找不到回 404；正常回 guide + body_html + cards[]。
     */
    public function show(Request $request, $slug)
    {
        if (!$this->enabled()) {
            return response()->json(['closed' => true], 200);
        }

        $g = Guide::where('status', 1)->where('slug', $slug)->first();
        if (!$g) {
            return response()->json(['errors' => [['code' => 'guide', 'message' => '攻略不存在或已下线']]], 404);
        }

        [$bodyHtml, $cards] = $this->renderBody((string) $g->body_md);

        return response()->json([
            'guide' => [
                'id'                 => $g->id,
                'title'              => $g->title,
                'slug'               => $g->slug,
                'cover_url'          => $this->coverUrl($g),
                'summary'            => $g->summary ?: null,
                'info_as_of'         => $g->info_as_of,
                'last_updated'       => optional($g->updated_at)->toDateString(),
                'is_sensitive_topic' => (bool) $g->is_sensitive_topic,
                'is_stale'           => $g->isStale(),
                'helpful_count'      => (int) $g->helpful_count,
                'body_html'          => $bodyHtml,
            ],
            'cards' => $cards,
        ], 200);
    }

    /**
     * 有用 +1 POST /api/v1/local-life/guides/{id}/helpful
     * 无登录墙（0 级反馈软计数），路由挂命名 throttle 防刷；前端 localStorage 防重复点。
     * 开关=0 → 404 语义。
     */
    public function helpful(Request $request, $id)
    {
        if (!$this->enabled()) {
            return response()->json(['errors' => [['code' => 'closed', 'message' => '攻略功能暂未开放']]], 404);
        }
        $g = Guide::where('status', 1)->find($id);
        if (!$g) {
            return response()->json(['errors' => [['code' => 'guide', 'message' => '攻略不存在或已下线']]], 404);
        }
        // 原子自增，避免并发计数丢失
        Guide::where('id', $g->id)->increment('helpful_count');
        $count = (int) Guide::where('id', $g->id)->value('helpful_count');
        return response()->json(['helpful_count' => $count], 200);
    }

    /* ============================ 内部工具 ============================ */

    /** 总开关（business_settings.nezha_guides_status，默认 0 封印） */
    private function enabled(): bool
    {
        $v = DB::table('business_settings')->where('key', 'nezha_guides_status')->value('value');
        return (bool) ($v ?? 0);
    }

    /** 入口条副行 teaser：最新在架篇目题眼（题目冒号前段）拼接，后端下发不写死 */
    private function entryTeaser($rows): string
    {
        $titles = $rows->take(4)->map(function ($g) {
            $t = (string) $g->title;
            // 取「：/:」前段作题眼；无冒号则整题
            $head = preg_split('/[：:]/u', $t)[0] ?? $t;
            return trim($head);
        })->filter()->values()->all();
        return implode(' · ', $titles);
    }

    /**
     * body_md → [body_html, cards[]]。
     * 按行扫描：独占一行的 {{restaurant:ID}} / {{merchant:ID}} 为分段点。
     * 每段普通 markdown 单独转 HTML（html_input=strip 剥离裸 HTML）；分段点若实体有效，
     * 拼接后（转换之外）插入占位符 <!--nz-card-N-->（不经转换器故不被 strip）+ 登记 cards[N]；
     * 实体下架/不存在 → 整卡跳过（不插占位、不登记）。前端按占位符切分插卡。
     */
    private function renderBody(string $md): array
    {
        $converter = new CommonMarkConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $lines = preg_split('/\r\n|\r|\n/', $md);
        $html = '';
        $buffer = [];
        $cards = [];
        $n = 0;

        $flush = function () use (&$buffer, &$html, $converter) {
            if (empty($buffer)) {
                return;
            }
            $segMd = implode("\n", $buffer);
            $buffer = [];
            if (trim($segMd) === '') {
                return;
            }
            $html .= (string) $converter->convert($segMd);
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*\{\{(restaurant|merchant):(\d+)\}\}\s*$/', $line, $m)) {
                $card = $this->buildCard($m[1], (int) $m[2], $n);
                if ($card) {
                    $flush();                          // 先输出店卡前的正文段
                    $html .= '<!--nz-card-' . $n . '-->';
                    $cards[] = $card;
                    $n++;
                }
                // 实体无效 → 该行整卡跳过（不进 buffer、不留痕）
                continue;
            }
            $buffer[] = $line;
        }
        $flush();

        return [$html, $cards];
    }

    /** 单张内嵌店卡数据（实体下架/不存在返回 null → 整卡跳过） */
    private function buildCard(string $type, int $id, int $n): ?array
    {
        if ($type === 'restaurant') {
            $r = Restaurant::find($id);
            if (!$r || !$r->status) {
                return null;
            }
            [$cuisine, $rating] = $this->restaurantMeta($r, $id);
            $logo = $r->logo ?: $r->cover_photo;
            return [
                'n'             => $n,
                'type'          => 'restaurant',
                'id'            => (int) $id,
                'name'          => $r->name,
                'logo_url'      => $logo ? Helpers::get_full_url(self::RESTAURANT_IMG_DIR, $logo, 'public') : null,
                'rating'        => $rating,
                'rating_source' => $rating !== null ? 'platform' : null,
                'category'      => $cuisine,   // 「中餐 · 烧烤」
                'area'          => null,
            ];
        }
        if ($type === 'merchant') {
            $m = LocalLifeMerchant::where('status', true)->find($id);
            if (!$m) {
                return null;
            }
            // 诚实评分：只认 Google 真源（DS§6.12/§8），无 Google 值 → rating/rating_source 都不出（禁商家自填回落）
            $rating = $m->google_rating !== null ? (float) $m->google_rating : null;
            $source = $m->google_rating !== null ? 'google' : null;
            return [
                'n'             => $n,
                'type'          => 'merchant',
                'id'            => (int) $id,
                'name'          => $m->name,
                'logo_url'      => $m->logo ? Helpers::get_full_url(self::MERCHANT_IMG_DIR, $m->logo, 'public') : null,
                'rating'        => $rating !== null ? round($rating, 1) : null,
                'rating_source' => $source,
                'category'      => $m->category,
                'area'          => $m->area ?: null,
            ];
        }
        return null;
    }

    /** 餐厅菜系 + 平台评分（reviews avg，与 OgImageController 同源口径） */
    private function restaurantMeta($restaurant, $id): array
    {
        try { app()->setLocale('zh'); } catch (\Throwable $e) {}
        $cuisine = null;
        try {
            $names = $restaurant->cuisine->pluck('name')->filter()->take(2)->all();
            $cuisine = $names ? implode(' · ', $names) : null;
        } catch (\Throwable $e) {}
        $rating = null;
        try {
            $q = DB::table('reviews')->join('food', 'food.id', '=', 'reviews.food_id')->where('food.restaurant_id', $id);
            if ((clone $q)->count() > 0) {
                $rating = round((float) (clone $q)->avg('reviews.rating'), 1);
            }
        } catch (\Throwable $e) {}
        return [$cuisine, $rating];
    }
}
