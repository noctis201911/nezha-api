<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocalLifePost;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocalLifeController extends Controller
{
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
        $ugcEnabled = (bool) (DB::table('business_settings')->where('key', 'locallife_ugc_enabled')->value('value'));

        $posts = $posts->latest()->paginate(config('default_pagination'))->appends([
            'search' => $search,
            'status' => $statusFilter,
        ]);

        return view('admin-views.local-life.list', compact('posts', 'search', 'statusFilter', 'pendingCount', 'ugcEnabled'));
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

    public function destroy(Request $request)
    {
        $post = LocalLifePost::find($request->id);
        if ($post) {
            $post->delete();
            Toastr::success('已删除');
        }
        return redirect()->route('admin.local-life.list');
    }

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
}
