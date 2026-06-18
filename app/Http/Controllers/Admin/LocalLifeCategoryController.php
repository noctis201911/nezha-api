<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocalLifeCategory;
use App\Models\LocalLifePost;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 本地生活「类目管理」：运营在后台增/删/改/排序类目。
 * 类目落 local_life_categories 表；前端金刚区按 status=1 动态渲染、发帖/建帖从此表选类目。
 * 类目名同时是 local_life_posts.category 的取值（去规范化存字符串，删类目不影响已发帖）。
 */
class LocalLifeCategoryController extends Controller
{
    // 频道(tab)白名单：与前端粗筛频道 & local_life_posts.tab 取值一致
    private const TABS = ['推荐', '租房', '招聘', '二手', '免费', '服务'];

    public function list()
    {
        $categories = LocalLifeCategory::orderBy('sort_order')->orderBy('id')->get();
        // 各类目下「已发布」帖子数（删类目前提示用，避免 N+1 用一次 group by）
        $postCounts = LocalLifePost::select('category', DB::raw('count(*) as c'))
            ->groupBy('category')
            ->pluck('c', 'category');
        $tabs = self::TABS;
        return view('admin-views.local-life.categories.list', compact('categories', 'postCounts', 'tabs'));
    }

    public function create()
    {
        $category = null;
        $tabs = self::TABS;
        return view('admin-views.local-life.categories.create', compact('category', 'tabs'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        if (LocalLifeCategory::where('name', $data['name'])->exists()) {
            Toastr::error('类目「' . $data['name'] . '」已存在');
            return back()->withInput();
        }
        LocalLifeCategory::create($data);
        Toastr::success('类目已创建');
        return redirect()->route('admin.local-life.categories.list');
    }

    public function edit($id)
    {
        $category = LocalLifeCategory::findOrFail($id);
        $tabs = self::TABS;
        return view('admin-views.local-life.categories.create', compact('category', 'tabs'));
    }

    public function update(Request $request, $id)
    {
        $category = LocalLifeCategory::findOrFail($id);
        $data = $this->validateData($request);
        // 改名时同步更新已发帖的 category 字段，避免类目页筛不到旧帖
        $oldName = $category->name;
        if ($oldName !== $data['name']) {
            if (LocalLifeCategory::where('name', $data['name'])->where('id', '!=', $id)->exists()) {
                Toastr::error('类目「' . $data['name'] . '」已存在');
                return back()->withInput();
            }
            LocalLifePost::where('category', $oldName)->update(['category' => $data['name'], 'updated_at' => now()]);
        }
        $category->update($data);
        Toastr::success('类目已更新');
        return redirect()->route('admin.local-life.categories.list');
    }

    // 启用 <-> 停用（停用只是前端隐藏，不删数据、不影响已发帖）
    public function statusToggle($id)
    {
        $category = LocalLifeCategory::findOrFail($id);
        $category->status = !$category->status;
        $category->save();
        Toastr::success($category->status ? '已启用' : '已停用');
        return back();
    }

    public function destroy(Request $request)
    {
        $category = LocalLifeCategory::find($request->id);
        if ($category) {
            $name = $category->name;
            $category->delete();
            Toastr::success('已删除类目「' . $name . '」（已发布的相关帖子不受影响，仍保留其分类文字）');
        }
        return redirect()->route('admin.local-life.categories.list');
    }

    private function validateData(Request $request): array
    {
        $request->validate([
            'name'       => 'required|string|max:60',
            'emoji'      => 'nullable|string|max:16',
            'color'      => 'nullable|string|max:40',
            'tab'        => ['required', 'string', 'in:' . implode(',', self::TABS)],
            'kind'       => 'nullable|string|in:ugc,merchant',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ], [
            'name.required' => '类目名必填',
            'tab.required'  => '归属频道必填',
            'tab.in'        => '归属频道不在允许范围',
        ]);

        return [
            'name'         => trim($request->name),
            'emoji'        => $request->emoji ?: null,
            'color'        => $request->color ?: null,
            'tab'          => $request->tab,
            'kind'         => $request->kind ?: 'ugc',
            'sort_order'   => (int) ($request->sort_order ?: 0),
            'is_sensitive' => $request->boolean('is_sensitive'),
            'status'       => $request->boolean('status'),
        ];
    }
}
