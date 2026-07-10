<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\LocalLifeCategory;
use App\Models\LocalLifeMerchant;
use App\Models\LocalLifeMerchantAccount;
use App\Models\LocalLifeReport;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;

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
        // 各商家待处理举报数（列表徽标）
        $reportCounts = LocalLifeReport::whereNotNull('merchant_id')
            ->where('status', LocalLifeReport::STATUS_PENDING)
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->selectRaw('merchant_id, count(*) as c')
            ->groupBy('merchant_id')->pluck('c', 'merchant_id');
        return view('admin-views.local-life.merchants.list', compact('merchants', 'categories', 'reportCounts'));
    }

    /** 某商家的举报列表（待处理在前） */
    public function reports($id)
    {
        $merchant = LocalLifeMerchant::findOrFail($id);
        $reports = LocalLifeReport::where('merchant_id', $id)
            ->orderByRaw('FIELD(status, 0, 1, 2)')
            ->latest()
            ->paginate(config('default_pagination'));
        return view('admin-views.local-life.merchants.reports', compact('merchant', 'reports'));
    }

    /** 隐藏该商家(status=0) + 把其待处理举报标记为已处理 */
    public function resolveReports($id)
    {
        $merchant = LocalLifeMerchant::findOrFail($id);
        $merchant->status = false;
        $merchant->save();
        LocalLifeReport::where('merchant_id', $id)
            ->where('status', LocalLifeReport::STATUS_PENDING)
            ->update(['status' => LocalLifeReport::STATUS_HANDLED, 'updated_at' => now()]);
        Toastr::success('已隐藏该商家并标记相关举报为已处理');
        return back();
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
        $account = LocalLifeMerchantAccount::where('merchant_id', $merchant->id)->first();
        return view('admin-views.local-life.merchants.create', compact('merchant', 'categories', 'account'));
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
            $data['cover_image'] = null; // 相册重传→门面重置自动
        } else {
            $pick = trim((string) $request->input('cover_image'));
            $data['cover_image'] = ($pick !== '' && is_array($merchant->images) && in_array($pick, $merchant->images, true)) ? $pick : null;
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

    /**
     * 地址→坐标：服务端 geocode（Google Geocoding API，用 map_api_key_server = 服务器 IP key）。
     * 只读地理编码，不碰资金。失败一律软返回（前端保留手填经纬度）。
     * POST admin/local-life/merchants/geocode  body: address
     */
    public function geocode(Request $request)
    {
        $address = trim((string) $request->input('address', ''));
        if ($address === '') {
            return response()->json(['ok' => false, 'message' => '请先填写详细地址'], 422);
        }
        $key = Helpers::get_business_settings('map_api_key_server');
        if (!$key) {
            return response()->json(['ok' => false, 'message' => '未配置服务端地图密钥，请手填经纬度'], 200);
        }
        try {
            $resp = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'key'     => $key,
                'region'  => 'am', // 亚美尼亚偏向
            ]);
            $json = $resp->json();
            $status = $json['status'] ?? 'ERR';
            if ($status === 'OK' && !empty($json['results'][0]['geometry']['location'])) {
                $loc = $json['results'][0]['geometry']['location'];
                return response()->json([
                    'ok'        => true,
                    'lat'       => round((float) $loc['lat'], 7),
                    'lng'       => round((float) $loc['lng'], 7),
                    'formatted' => $json['results'][0]['formatted_address'] ?? null,
                ], 200);
            }
            $msg = $status === 'ZERO_RESULTS' ? '未能解析该地址，请手填经纬度或换更精确地址' : ('地图返回 ' . $status . '，请手填经纬度');
            return response()->json(['ok' => false, 'message' => $msg], 200);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => '地图服务暂不可用，请手填经纬度'], 200);
        }
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
            'google_rating_count' => 'nullable|integer|min:0|max:100000000',
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
            'cover_image'       => 'nullable|string|max:191',
            'services'          => 'nullable|array',
            'services.*.title'  => 'nullable|string|max:120',
            'services.*.desc'   => 'nullable|string|max:200',
            'services.*.price_text' => 'nullable|string|max:60',
            // 招牌标（v3 §④-4）：勾选写进 JSON featured，前端加「招牌」tag + 组内置顶（每店 ≤3 由前端渲染截断）
            'services.*.featured' => 'nullable|boolean',
            // 房型卡(§2b)：每项可选图 + attrs 子集(户型/面积/设施)。仅租房民宿类目用；其他类目留空回落文字行。
            'services.*.image'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'services.*.existing_image'    => 'nullable|string|max:191',
            'services.*.attrs.layout'      => 'nullable|in:studio,1b1l,2b1l,3b1l,4plus',
            'services.*.attrs.area_label'  => 'nullable|string|max:20',
            'services.*.attrs.amenities'   => 'nullable|array|max:10',
            'services.*.attrs.amenities.*' => 'in:furniture,washer,fridge,ac,heating,elevator,parking,balcony,private_bath,kitchen',
            'contacts'          => 'nullable|array',
            'contacts.*.method' => 'nullable|string|in:wechat,phone,whatsapp,telegram',
            'contacts.*.value'  => 'nullable|string|max:120',
            'contacts.*.label'  => 'nullable|string|max:40',
        ], [
            'name.required'     => '商家名必填',
            'category.required' => '请选择类目',
            'category.in'       => '类目不在允许范围（仅商家型类目）',
        ]);

        $services = $this->buildServicesWithMedia($request);
        $contacts = $this->parseContacts($request);

        // 硬禁业务词筛查：命中即拒（换汇/加密买卖/医美注射/性服务/赌博/制裁规避等）
        $servicesFlat = '';
        if (is_array($services)) {
            foreach ($services as $s) {
                $servicesFlat .= "\n" . ($s['title'] ?? '') . ' ' . ($s['desc'] ?? '') . ' ' . ($s['price_text'] ?? '');
            }
        }
        $screenText = trim($request->name . "\n" . (string) $request->intro . "\n" . (string) $request->offer_text . $servicesFlat);
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
            'google_rating_count' => $request->filled('google_rating_count') ? (int) $request->google_rating_count : null,
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
            'services'          => $services,
            'contacts'          => $contacts,
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

    /**
     * 服务项 → [{title,desc,price_text}]。
     * 新：动态行数组 services[i][title|desc|price_text]（标题非空才算一项）。
     * 兼容：旧文本每行「标题 | 描述 | 价格文字」（历史存量/回退）。
     */
    private function parseServices($raw): ?array
    {
        if (is_array($raw)) {
            $items = [];
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                $desc  = trim((string) ($row['desc'] ?? ''));
                $price = trim((string) ($row['price_text'] ?? ''));
                if ($title === '') {
                    continue; // 标题必填才成一项
                }
                $items[] = ['title' => $title, 'desc' => $desc, 'price_text' => $price];
            }
            return $items ?: null;
        }
        if (!is_string($raw) || trim($raw) === '') {
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

    /**
     * 服务项(房型)含媒体版(§2b)：在 title/desc/price_text 基础上，按行索引解析可选 image(新传或保留)
     * 与 attrs 子集(layout/area_label/amenities，白名单过滤未知键剥离)。标题非空才成一项。
     * 非数组(旧文本格式) → 回退 parseServices。
     */
    private function buildServicesWithMedia(Request $request): ?array
    {
        $rows = $request->input('services');
        if (!is_array($rows)) {
            return $this->parseServices($rows);
        }
        $layoutAllow = ['studio', '1b1l', '2b1l', '3b1l', '4plus'];
        $amenAllow   = ['furniture', 'washer', 'fridge', 'ac', 'heating', 'elevator', 'parking', 'balcony', 'private_bath', 'kitchen'];
        $items = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $item = [
                'title'      => $title,
                'desc'       => trim((string) ($row['desc'] ?? '')),
                'price_text' => trim((string) ($row['price_text'] ?? '')),
            ];
            // 房型图：新上传优先，否则保留隐藏域回填的原文件名
            $file = $request->file("services.$i.image");
            if ($file) {
                $item['image'] = Helpers::upload(self::IMG_DIR, 'webp', $file);
            } else {
                $existing = trim((string) ($row['existing_image'] ?? ''));
                if ($existing !== '') {
                    $item['image'] = basename($existing);
                }
            }
            // attrs 子集白名单
            $rawAttrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
            $attrs = [];
            $layout = trim((string) ($rawAttrs['layout'] ?? ''));
            if (in_array($layout, $layoutAllow, true)) {
                $attrs['layout'] = $layout;
            }
            $area = trim((string) ($rawAttrs['area_label'] ?? ''));
            if ($area !== '') {
                $attrs['area_label'] = mb_substr($area, 0, 20);
            }
            if (is_array($rawAttrs['amenities'] ?? null)) {
                $am = array_values(array_unique(array_filter($rawAttrs['amenities'], fn ($x) => in_array($x, $amenAllow, true))));
                if ($am) {
                    $attrs['amenities'] = $am;
                }
            }
            if ($attrs) {
                $item['attrs'] = $attrs;
            }
            // 招牌标（v3 §④-4）：checkbox 勾选 → featured=true（缺失键不写，保持 JSON 精简）
            if (filter_var($row['featured'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $item['featured'] = true;
            }
            $items[] = $item;
        }
        return $items ?: null;
    }

    /**
     * 结构化联系方式 → [{method,value,label?}]。
     * 动态行 contacts[i][method|value|label]；method ∈ wechat|phone|whatsapp|telegram，value 非空才成一条。
     * L1-1：仅联系方式展示，不含支付/下单。
     */
    private function parseContacts(Request $request): ?array
    {
        $raw = $request->input('contacts', []);
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $method = strtolower(trim((string) ($row['method'] ?? '')));
            $value  = trim((string) ($row['value'] ?? ''));
            $label  = trim((string) ($row['label'] ?? ''));
            if ($value === '' || !in_array($method, ['wechat', 'phone', 'whatsapp', 'telegram'], true)) {
                continue;
            }
            $item = ['method' => $method, 'value' => $value];
            if ($label !== '') {
                $item['label'] = $label;
            }
            $out[] = $item;
        }
        return $out ?: null;
    }

    /* ---------------- 商户轻管理面账号（C·邮箱+密码·邮箱自助设密/找回） ---------------- */

    /** 为商户开通账号：填店主邮箱 → 建账号(无密码) → 发「设置密码」邮件 */
    public function accountCreate(Request $request, $id)
    {
        $merchant = LocalLifeMerchant::findOrFail($id);
        $request->validate([
            'email'        => 'required|email|max:191|unique:local_life_merchant_accounts,email',
            'contact_name' => 'nullable|string|max:120',
        ], [
            'email.unique' => '该邮箱已绑定其他商户账号',
        ], ['email' => '邮箱']);

        if (LocalLifeMerchantAccount::where('merchant_id', $merchant->id)->exists()) {
            Toastr::warning('该商户已有账号，请勿重复开通');
            return back();
        }

        $account = LocalLifeMerchantAccount::create([
            'merchant_id'  => $merchant->id,
            'email'        => strtolower(trim($request->email)),
            'password'     => null,
            'contact_name' => $request->contact_name ?: null,
            'status'       => true,
        ]);

        $sent = Password::broker('local_merchants')->sendResetLink(['email' => $account->email]);
        Toastr::success($sent === Password::RESET_LINK_SENT
            ? '账号已开通，设置密码邮件已发送至 ' . $account->email
            : '账号已开通，但设置密码邮件发送失败，请用「重新发送」重试');
        return back();
    }

    /** 重新发送设置/重置密码邮件 */
    public function accountSendLink($id)
    {
        $account = LocalLifeMerchantAccount::where('merchant_id', $id)->firstOrFail();
        $sent = Password::broker('local_merchants')->sendResetLink(['email' => $account->email]);
        Toastr::success($sent === Password::RESET_LINK_SENT
            ? '设置/重置密码邮件已发送至 ' . $account->email
            : '邮件发送失败，请稍后重试');
        return back();
    }

    /** 停用 / 启用账号（停用后商户无法登录管理面） */
    public function accountToggle($id)
    {
        $account = LocalLifeMerchantAccount::where('merchant_id', $id)->firstOrFail();
        $account->status = !$account->status;
        $account->save();
        Toastr::success($account->status ? '账号已启用' : '账号已停用');
        return back();
    }

    /** 修改绑定邮箱（改后需重新发送设置密码邮件） */
    public function accountUpdateEmail(Request $request, $id)
    {
        $account = LocalLifeMerchantAccount::where('merchant_id', $id)->firstOrFail();
        $request->validate([
            'email' => 'required|email|max:191|unique:local_life_merchant_accounts,email,' . $account->id,
        ], ['email.unique' => '该邮箱已绑定其他商户账号'], ['email' => '邮箱']);
        $account->email = strtolower(trim($request->email));
        $account->save();
        Toastr::success('邮箱已更新为 ' . $account->email . '，如需请重新发送设置密码邮件');
        return back();
    }

    /** 删除账号（解绑；不删商户条目与历史提交快照） */
    public function accountDelete($id)
    {
        $account = LocalLifeMerchantAccount::where('merchant_id', $id)->firstOrFail();
        $email = $account->email;
        $account->delete();
        Toastr::success('已删除账号 ' . $email);
        return back();
    }
}
