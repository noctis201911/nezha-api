<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocalLifePost;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;

class LocalLifeController extends Controller
{
    public function list(Request $request)
    {
        $search = $request['search'];
        if ($request->has('search') && $search) {
            $key = explode(' ', $search);
            $posts = LocalLifePost::where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('title', 'like', "%{$value}%")
                        ->orWhere('category', 'like', "%{$value}%");
                }
            });
        } else {
            $posts = new LocalLifePost();
        }
        $posts = $posts->latest()->paginate(config('default_pagination'));
        return view('admin-views.local-life.list', compact('posts', 'search'));
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

    // 草稿(0) <-> 已发布(1) 切换；已下线(2)切换则恢复为已发布
    public function statusToggle($id)
    {
        $post = LocalLifePost::findOrFail($id);
        $post->status = $post->status == 1 ? 0 : 1;
        $post->save();
        Toastr::success($post->status == 1 ? '已发布' : '已转为草稿');
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
