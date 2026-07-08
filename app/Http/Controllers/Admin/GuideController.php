<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Guide;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 本地生活「生活攻略」后台管理（批2）。运营录入/编辑攻略长文（PGC）。
 * 合规 L1-1：纯信息展示。level1 话题（签证/居留/移民）勾选敏感 → 前端文末专用免责。
 * 内容时效制度：必填 info_as_of；过期宁下架（status→0），info_as_of>180 天列表琥珀 stale 徽标兜底。
 * 总开关 nezha_guides_status（默认 0）独立于单篇 status；两者双封印。
 */
class GuideController extends Controller
{
    private const IMG_DIR = 'guide/';

    public function list(Request $request)
    {
        $query = Guide::query();
        if ($request->filled('search')) {
            $kw = trim($request->search);
            $query->where(function ($q) use ($kw) {
                $q->where('title', 'like', "%$kw%")->orWhere('slug', 'like', "%$kw%");
            });
        }
        $guides = $query->orderBy('sort')->orderByDesc('id')->paginate(config('default_pagination'));
        return view('admin-views.guides.list', compact('guides'));
    }

    public function create()
    {
        $guide = null;
        return view('admin-views.guides.create', compact('guide'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['cover_url'] = $request->hasFile('cover') ? Helpers::upload(self::IMG_DIR, 'png', $request->file('cover')) : null;
        if ($data['status'] == 1 && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
        Guide::create($data);
        Toastr::success('攻略已创建');
        return redirect()->route('admin.guides.list');
    }

    public function edit($id)
    {
        $guide = Guide::findOrFail($id);
        return view('admin-views.guides.create', compact('guide'));
    }

    public function update(Request $request, $id)
    {
        $guide = Guide::findOrFail($id);
        $data = $this->validateData($request, $guide->id);
        if ($request->hasFile('cover')) {
            $data['cover_url'] = Helpers::upload(self::IMG_DIR, 'png', $request->file('cover'));
        }
        // 首次上架补 published_at；已有则不覆盖
        if ($data['status'] == 1 && empty($guide->published_at)) {
            $data['published_at'] = now();
        }
        $guide->update($data);
        Toastr::success('攻略已更新');
        return redirect()->route('admin.guides.list');
    }

    public function statusToggle($id)
    {
        $guide = Guide::findOrFail($id);
        $guide->status = $guide->status == 1 ? 0 : 1;
        if ($guide->status == 1 && empty($guide->published_at)) {
            $guide->published_at = now();
        }
        $guide->save();
        Toastr::success($guide->status == 1 ? '已上架' : '已下架');
        return back();
    }

    public function destroy(Request $request)
    {
        $guide = Guide::find($request->id);
        if ($guide) {
            $title = $guide->title;
            $guide->delete();
            Toastr::success('已删除攻略「' . $title . '」');
        }
        return redirect()->route('admin.guides.list');
    }

    private function validateData(Request $request, $ignoreId = null): array
    {
        $request->validate([
            'title'              => 'required|string|max:200',
            'slug'               => ['required', 'string', 'max:191', 'regex:/^[a-z0-9\-]+$/', 'unique:nezha_guides,slug' . ($ignoreId ? ',' . $ignoreId : '')],
            'summary'            => 'nullable|string|max:300',
            'body_md'            => 'nullable|string',
            'info_as_of'         => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'cover'              => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'sort'               => 'nullable|integer|min:0|max:9999',
        ], [
            'title.required'      => '标题必填',
            'slug.required'       => 'slug 必填（用于攻略网址）',
            'slug.regex'          => 'slug 只能含小写字母、数字、连字符',
            'slug.unique'         => '该 slug 已被占用，请换一个',
            'info_as_of.required' => '信息截至年月必填（时效锚点）',
            'info_as_of.regex'    => '信息截至格式应为 YYYY-MM',
        ]);

        return [
            'title'              => trim($request->title),
            'slug'               => Str::lower(trim($request->slug)),
            'summary'            => $request->summary ?: null,
            'body_md'            => $request->body_md ?: null,
            'info_as_of'         => $request->info_as_of,
            'is_sensitive_topic' => $request->boolean('is_sensitive_topic'),
            'sort'               => (int) ($request->sort ?: 0),
            'status'             => $request->boolean('status') ? 1 : 0,
        ];
    }
}
