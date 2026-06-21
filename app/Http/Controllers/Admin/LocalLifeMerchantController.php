<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\LocalLifeCategory;
use App\Models\LocalLifeMerchant;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;

/**
 * 本地生活「商家管理」：运营在后台增/删/改商家（移民/签证/美容美发/按摩/包车出行/本地旅游…）。
 * 商家落 local_life_merchants 表；前端商家类目点击→商家列表→商家店铺页按本表渲染。
 * 合规 L1-1：纯信息展示，不碰钱、不接预订下单。敏感类目(移民/签证/按摩)重点审核。
 */
class LocalLifeMerchantController extends Controller
{
    private const IMG_DIR = 'local-life-merchant/';

    /** 仅 merchant 型类目可建商家（ugc 型=个人发帖，不在此） */
    private function merchantCategories()
    {
        return LocalLifeCategory::where('status', true)
            ->where('kind', 'merchant')
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    public function list(Request $request)
    {
        $query = LocalLifeMerchant::query();
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('search')) {
            $kw = trim($request->search);
            $query->where(function ($q) use ($kw) {
                $q->where('name', 'like', "%$kw%")->orWhere('area', 'like', "%$kw%");
            });
        }
        $merchants = $query->orderBy('sort_order')->orderByDesc('id')->paginate(config('default_pagination'));
        $categories = $this->merchantCategories();
        return view('admin-views.local-life.merchants.list', compact('merchants', 'categories'));
    }

    public function create()
    {
        $merchant = null;
        $categories = $this->merchantCategories();
        return view('admin-views.local-life.merchants.create', compact('merchant', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['logo']      = $request->hasFile('logo') ? Helpers::upload(self::IMG_DIR, 'png', $request->file('logo')) : null;
        $data['wechat_qr'] = $request->hasFile('wechat_qr') ? Helpers::upload(self::IMG_DIR, 'png', $request->file('wechat_qr')) : null;
        $data['images']    = $this->uploadAlbum($request);
        LocalLifeMerchant::create($data);
        Toastr::success('商家已创建');
        return redirect()->route('admin.local-life.merchants.list');
    }

    public function edit($id)
    {
        $merchant = LocalLifeMerchant::findOrFail($id);
        $categories = $this->merchantCategories();
        return view('admin-views.local-life.merchants.create', compact('merchant', 'categories'));
    }

    public function update(Request $request, $id)
    {
        $merchant = LocalLifeMerchant::findOrFail($id);
        $data = $this->validateData($request);
        // 图片：有新传才替换，否则保留旧图（不传不删）
        if ($request->hasFile('logo')) {
            $data['logo'] = Helpers::upload(self::IMG_DIR, 'png', $request->file('logo'));
        }
        if ($request->hasFile('wechat_qr')) {
            $data['wechat_qr'] = Helpers::upload(self::IMG_DIR, 'png', $request->file('wechat_qr'));
        }
        if ($request->hasFile('images')) {
            $data['images'] = $this->uploadAlbum($request);
        }
        $merchant->update($data);
        Toastr::success('商家已更新');
        return redirect()->route('admin.local-life.merchants.list');
    }

    public function statusToggle($id)
    {
        $merchant = LocalLifeMerchant::findOrFail($id);
        $merchant->status = !$merchant->status;
        $merchant->save();
        Toastr::success($merchant->status ? '已上线' : '已隐藏');
        return back();
    }

    public function destroy(Request $request)
    {
        $merchant = LocalLifeMerchant::find($request->id);
        if ($merchant) {
            $name = $merchant->name;
            $merchant->delete();
            Toastr::success('已删除商家「' . $name . '」');
        }
        return redirect()->route('admin.local-life.merchants.list');
    }

    private function uploadAlbum(Request $request): ?array
    {
        if (!$request->hasFile('images')) {
            return null;
        }
        $names = [];
        foreach ($request->file('images') as $file) {
            $names[] = Helpers::upload(self::IMG_DIR, 'png', $file);
        }
        return $names ?: null;
    }

    private function validateData(Request $request): array
    {
        $catNames = $this->merchantCategories()->pluck('name')->toArray();
        $request->validate([
            'name'              => 'required|string|max:120',
            'category'          => ['required', 'string', 'in:' . implode(',', $catNames)],
            'rating'            => 'nullable|numeric|min:0|max:5',
            'google_rating'     => 'nullable|numeric|min:0|max:5',
            'google_rating_url' => 'nullable|string|max:255',
            'area'              => 'nullable|string|max:60',
            'address'           => 'nullable|string|max:255',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
            'open_time'         => 'nullable|string|max:5',
            'close_time'        => 'nullable|string|max:5',
            'hours_note'        => 'nullable|string|max:120',
            'intro'             => 'nullable|string|max:3000',
            'offer_text'        => 'nullable|string|max:120',
            'sort_order'        => 'nullable|integer|min:0|max:9999',
            'logo'              => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'wechat_qr'         => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'images.*'          => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ], [
            'name.required'     => '商家名必填',
            'category.required' => '请选择类目',
            'category.in'       => '类目不在允许范围（仅商家型类目）',
        ]);

        // 硬禁业务词筛查：命中即拒（换汇/加密买卖/医美注射/性服务/赌博/制裁规避等）
        $screenText = trim($request->name . "\n" . (string) $request->intro . "\n" . (string) $request->offer_text . "\n" . (string) $request->services);
        if (\App\CentralLogics\NezhaContentScreen::hits($screenText)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => '内容命中禁止经营 / 硬禁业务关键词，该商家不予上线。如确属正规持牌业务，请联系技术调整词库。',
            ]);
        }

        // 敏感类目自动置敏感旗标（移民/签证/按摩）
        $isSensitive = LocalLifeCategory::where('name', $request->category)->value('is_sensitive');

        return [
            'name'              => trim($request->name),
            'category'          => $request->category,
            'rating'            => $request->filled('rating') ? (float) $request->rating : 5.0,
            'google_rating'     => $request->filled('google_rating') ? (float) $request->google_rating : null,
            'google_rating_url' => $request->google_rating_url ?: null,
            'area'              => $request->area ?: null,
            'address'           => $request->address ?: null,
            'latitude'          => $request->filled('latitude') ? (float) $request->latitude : null,
            'longitude'         => $request->filled('longitude') ? (float) $request->longitude : null,
            'open_days'         => $this->parseOpenDays($request),
            'open_time'         => $request->open_time ?: null,
            'close_time'        => $request->close_time ?: null,
            'hours_note'        => $request->hours_note ?: null,
            'intro'             => $request->intro ?: null,
            'services'          => $this->parseServices($request->services),
            'has_offer'         => $request->boolean('has_offer'),
            'offer_text'        => $request->offer_text ?: null,
            'is_sensitive'      => (bool) $isSensitive,
            'sort_order'        => (int) ($request->sort_order ?: 0),
            'status'            => $request->boolean('status'),
        ];
    }

    /** 营业星期复选框 → [0..6] 数组 */
    private function parseOpenDays(Request $request): ?array
    {
        $days = $request->input('open_days', []);
        if (!is_array($days)) {
            return null;
        }
        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn ($d) => $d >= 0 && $d <= 6)));
        return $days ?: null;
    }

    /** 服务项文本（每行「标题 | 描述 | 价格文字」）→ [{title,desc,price_text}] */
    private function parseServices(?string $raw): ?array
    {
        if (!$raw || trim($raw) === '') {
            return null;
        }
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $items[] = [
                'title'      => $parts[0] ?? '',
                'desc'       => $parts[1] ?? '',
                'price_text' => $parts[2] ?? '',
            ];
        }
        return $items ?: null;
    }
}
