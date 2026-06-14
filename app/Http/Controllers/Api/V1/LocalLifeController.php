<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LocalLifePost;
use Illuminate\Http\Request;

class LocalLifeController extends Controller
{
    // 列表接口对外暴露的字段（不含 contact_info / expires_at 等内部字段）
    private const LIST_FIELDS = [
        'id', 'title', 'category', 'tab', 'cover_emoji', 'cover_color',
        'price_amd', 'price_suffix', 'is_free', 'area_label', 'location_label',
        'is_urgent', 'want_count', 'created_at',
    ];

    /**
     * 接口 A：本地生活帖子列表
     * GET /api/v1/local-life/posts?tab=推荐&limit=20&offset=1
     * 只返回已发布(status=1)，按 created_at DESC 分页。绝不返回 contact_info。
     */
    public function posts(Request $request)
    {
        $limit = (int) ($request->input('limit', 20));
        $limit = $limit > 0 ? min($limit, 50) : 20;
        $offset = (int) ($request->input('offset', 1));
        $offset = $offset > 0 ? $offset : 1;

        $query = LocalLifePost::where('status', 1);

        $tab = $request->input('tab');
        if ($tab && $tab !== '推荐') {
            $query->where('tab', $tab);
        }

        $total = $query->count();
        $posts = $query->orderBy('created_at', 'desc')
            ->skip(($offset - 1) * $limit)
            ->take($limit)
            ->get(self::LIST_FIELDS);

        return response()->json([
            'total_size' => $total,
            'limit'      => $limit,
            'offset'     => $offset,
            'posts'      => $posts,
        ], 200);
    }

    /**
     * 接口 B：本地生活帖子详情
     * GET /api/v1/local-life/posts/{id}
     * 只返回已发布帖子；contact_info 仅对持有有效 token 的登录用户返回，游客一律 null。
     * 路由不强制鉴权（游客也能看详情），鉴权状态在控制器内用 auth('api')->check() 判断。
     */
    public function postDetail(Request $request, $id)
    {
        $post = LocalLifePost::where('status', 1)->find($id);

        if (!$post) {
            return response()->json(['errors' => [['code' => 'post', 'message' => '帖子不存在或已下线']]], 404);
        }

        $data = $post->toArray();

        // PII 红线(L1-7)：联系方式只给已登录用户，游客看不到
        if (!auth('api')->check()) {
            $data['contact_info'] = null;
        }

        return response()->json($data, 200);
    }
}
