<?php

namespace App\Http\Controllers\LocalMerchant;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaContentScreen;
use App\Http\Controllers\Controller;
use App\Models\LocalLifeMerchantChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * 本地生活商户轻管理面 —— 面板（登录后）。
 * 全复审：商户任何提交都不直接生效，只写「待审变更」快照(status=0)，超管过审才应用到线上。
 * 作用域全程锚定登录账号的 merchant_id（EnsureLocalMerchant 把门），绝不信任请求里的 id → 结构性防 IDOR。
 * L1-1：纯信息墙，不碰钱不接单；提交前跑禁业务词筛查(NezhaContentScreen)。
 */
class PanelController extends Controller
{
    private const GUARD   = 'local_merchant';
    private const IMG_DIR = 'local-life-merchant/';
    /** 与 Api\V1\LocalLifeController::RENTAL_CATEGORY 同步（该处为 private 拿不到，镜像一份） */
    private const RENTAL_CATEGORY = '租房民宿';

    private function account()
    {
        return Auth::guard(self::GUARD)->user();
    }

    private function merchant()
    {
        return $this->account()?->merchant;
    }

    /** 当前待审快照（每商户至多一条 status=0） */
    private function pendingChange($merchantId): ?LocalLifeMerchantChange
    {
        return LocalLifeMerchantChange::where('merchant_id', $merchantId)
            ->where('status', LocalLifeMerchantChange::STATUS_PENDING)
            ->latest('id')->first();
    }

    /** 商户可自改字段的线上现值（预填 + base_snapshot 用） */
    private function liveEditable($m): array
    {
        return [
            'name'       => $m->name,
            'address'    => $m->address,
            'intro'      => $m->intro,
            'open_days'  => is_array($m->open_days) ? $m->open_days : [],
            'open_time'  => $m->open_time,
            'close_time' => $m->close_time,
            'hours_note' => $m->hours_note,
            'has_offer'  => (bool) $m->has_offer,
            'offer_text' => $m->offer_text,
            'services'   => is_array($m->services) ? $m->services : [],
            'contacts'   => is_array($m->contacts) ? $m->contacts : [],
            'logo'       => $m->logo,
            'wechat_qr'  => $m->wechat_qr,
            'images'     => is_array($m->images) ? $m->images : [],
        ];
    }

    public function home(Request $request)
    {
        $merchant = $this->merchant();
        if (!$merchant) {
            abort(404);
        }
        $pending = $this->pendingChange($merchant->id);
        $history = LocalLifeMerchantChange::where('merchant_id', $merchant->id)
            ->orderByDesc('id')->limit(8)->get();

        return view('local_merchant.home', compact('merchant', 'pending', 'history'));
    }

    public function editForm(Request $request)
    {
        $merchant = $this->merchant();
        if (!$merchant) {
            abort(404);
        }
        $pending = $this->pendingChange($merchant->id);
        // 有待审就在待审基础上继续改；否则用线上现值
        $prefill = $pending ? array_merge($this->liveEditable($merchant), (array) $pending->payload) : $this->liveEditable($merchant);
        $isRental = ($merchant->category === self::RENTAL_CATEGORY);

        return view('local_merchant.edit', compact('merchant', 'prefill', 'pending', 'isRental'));
    }

    public function submit(Request $request)
    {
        $merchant = $this->merchant();
        if (!$merchant) {
            abort(404);
        }
        $account = $this->account();
        $pending = $this->pendingChange($merchant->id);

        // 图片基线：现有待审快照优先，否则线上现值（不传新图=保留现状）
        $baseline = $pending ? array_merge($this->liveEditable($merchant), (array) $pending->payload) : $this->liveEditable($merchant);

        $isRental = ($merchant->category === self::RENTAL_CATEGORY);
        $payload = $this->buildPayload($request, $baseline, $isRental);

        // 建/替换 该商户唯一待审快照
        $data = [
            'merchant_id'   => $merchant->id,
            'account_id'    => $account->id,
            'payload'       => $payload,
            'base_snapshot' => $this->liveEditable($merchant),
            'status'        => LocalLifeMerchantChange::STATUS_PENDING,
            'review_note'   => null,
            'reviewed_by'   => null,
            'reviewed_at'   => null,
            'submit_ip'     => $request->ip(),
            'submit_ua'     => mb_substr((string) $request->userAgent(), 0, 255),
        ];
        if ($pending) {
            $pending->update($data);
        } else {
            LocalLifeMerchantChange::create($data);
        }

        return redirect()->route('local-merchant.home')
            ->with('status', '已提交，等待平台确认后生效。确认前顾客端仍显示原内容。');
    }

    public function history(Request $request)
    {
        $merchant = $this->merchant();
        if (!$merchant) {
            abort(404);
        }
        $changes = LocalLifeMerchantChange::where('merchant_id', $merchant->id)
            ->orderByDesc('id')->paginate(15);

        return view('local_merchant.history', compact('merchant', 'changes'));
    }

    /* ---------------- 校验 + 组装（仅自改白名单字段） ---------------- */

    private function buildPayload(Request $request, array $baseline, bool $isRental): array
    {
        $request->validate([
            'name'                  => 'required|string|max:120',
            'address'               => 'nullable|string|max:255',
            'intro'                 => 'nullable|string|max:3000',
            'open_time'             => 'nullable|string|max:5',
            'close_time'            => 'nullable|string|max:5',
            'hours_note'            => 'nullable|string|max:120',
            'offer_text'            => 'nullable|string|max:120',
            'logo'                  => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'wechat_qr'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'images.*'              => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'services'              => 'nullable|array|max:40',
            'services.*.title'      => 'nullable|string|max:120',
            'services.*.desc'       => 'nullable|string|max:200',
            'services.*.price_text' => 'nullable|string|max:60',
            // 房型卡(仅租房民宿类目用；非租房服务端 gate 一律不采纳，见 buildRentalServices 调用点)
            'services.*.image'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'services.*.existing_image'    => 'nullable|string|max:191',
            'services.*.attrs.layout'      => 'nullable|in:studio,1b1l,2b1l,3b1l,4plus',
            'services.*.attrs.area_label'  => 'nullable|string|max:20',
            'services.*.attrs.amenities'   => 'nullable|array|max:10',
            'services.*.attrs.amenities.*' => 'in:furniture,washer,fridge,ac,heating,elevator,parking,balcony,private_bath,kitchen',
            'contacts'              => 'nullable|array|max:20',
            'contacts.*.method'     => 'nullable|string|in:wechat,phone,whatsapp,telegram',
            'contacts.*.value'      => 'nullable|string|max:120',
            'contacts.*.label'      => 'nullable|string|max:40',
            'open_days'             => 'nullable|array',
            'images'                => 'nullable|array|max:12',
        ], [
            'name.required' => '店名必填',
        ], ['name' => '店名', 'address' => '地址', 'intro' => '介绍']);

        // 类目门（服务端，非 UI 摆设）：租房走房型卡解析(吃 image/attrs)；非租房走现状(忽略请求里任何 image/attrs，按 title 保留 baseline)
        $services = $isRental
            ? $this->buildRentalServices($request)
            : $this->parseServices($request->input('services'), is_array($baseline['services'] ?? null) ? $baseline['services'] : []);
        $contacts = $this->parseContacts($request);

        // 硬禁业务词筛查（换汇/加密买卖/医美注射/性服务/赌博/制裁规避等）——命中即拒
        $servicesFlat = '';
        foreach ((array) $services as $s) {
            $servicesFlat .= "\n" . ($s['title'] ?? '') . ' ' . ($s['desc'] ?? '') . ' ' . ($s['price_text'] ?? '');
        }
        $screenText = trim($request->name . "\n" . (string) $request->intro . "\n" . (string) $request->offer_text . $servicesFlat);
        if (NezhaContentScreen::hits($screenText)) {
            throw ValidationException::withMessages([
                'name' => '内容命中平台禁止经营 / 硬禁业务关键词，无法提交。如确属正规持牌业务，请联系平台客服。',
            ]);
        }

        // 图片：新传才替换，否则沿用基线
        $logo   = $request->hasFile('logo') ? Helpers::upload(self::IMG_DIR, 'png', $request->file('logo')) : ($baseline['logo'] ?? null);
        $wechat = $request->hasFile('wechat_qr') ? Helpers::upload(self::IMG_DIR, 'png', $request->file('wechat_qr')) : ($baseline['wechat_qr'] ?? null);
        $images = $request->hasFile('images') ? $this->uploadAlbum($request) : ($baseline['images'] ?? []);

        return [
            'name'       => trim((string) $request->name),
            'address'    => $request->address ?: null,
            'intro'      => $request->intro ?: null,
            'open_days'  => $this->parseOpenDays($request),
            'open_time'  => $request->open_time ?: null,
            'close_time' => $request->close_time ?: null,
            'hours_note' => $request->hours_note ?: null,
            'has_offer'  => $request->boolean('has_offer'),
            'offer_text' => $request->offer_text ?: null,
            'services'   => $services,
            'contacts'   => $contacts,
            'logo'       => $logo,
            'wechat_qr'  => $wechat,
            'images'     => $images,
        ];
    }

    private function uploadAlbum(Request $request): array
    {
        $names = [];
        foreach ((array) $request->file('images') as $file) {
            if ($file) {
                $names[] = Helpers::upload(self::IMG_DIR, 'png', $file);
            }
        }
        return $names;
    }

    /** 营业星期复选 → [0..6] */
    private function parseOpenDays(Request $request): array
    {
        $days = $request->input('open_days', []);
        if (!is_array($days)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            array_map('intval', $days),
            fn ($d) => $d >= 0 && $d <= 6
        )));
    }

    /**
     * 房型卡(仅租房民宿类目)：抄 Admin\LocalLifeMerchantController::buildServicesWithMedia() ——
     * 图新传优先/否则 existing_image 隐藏域回填 basename；attrs(layout/area_label/amenities)按白名单剥离未知值。
     * 与 admin 逻辑保持一致，白名单变更须两处同步改。
     */
    private function buildRentalServices(Request $request): array
    {
        $rows = $request->input('services');
        if (!is_array($rows)) {
            return [];
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
            $file = $request->file("services.$i.image");
            if ($file) {
                $item['image'] = Helpers::upload(self::IMG_DIR, 'webp', $file);
            } else {
                $existing = trim((string) ($row['existing_image'] ?? ''));
                if ($existing !== '') {
                    $item['image'] = basename($existing);
                }
            }
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
            $items[] = $item;
        }
        return $items;
    }

    /**
     * 服务项动态行 → [{title,desc,price_text}]（标题非空才成一项）。
     * 非租房类目：不编辑 image+attrs，但必须按 title 从 baseline 保留它们（含请求里混入的任何 image/attrs 一律不采纳），
     * 否则商户一自助改就会抹掉后台录入的房型图/attrs（数据丢失），或让非租房商户越权注入 attrs。
     */
    private function parseServices($raw, array $baseline = []): array
    {
        if (!is_array($raw)) {
            return [];
        }
        // 按 title 建 baseline 房型卡媒体索引
        $keep = [];
        foreach ($baseline as $b) {
            if (is_array($b) && trim((string) ($b['title'] ?? '')) !== '') {
                $keep[trim((string) $b['title'])] = [
                    'image' => $b['image'] ?? null,
                    'attrs' => (is_array($b['attrs'] ?? null) && !empty($b['attrs'])) ? $b['attrs'] : null,
                ];
            }
        }
        $items = [];
        foreach ($raw as $row) {
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
            if (isset($keep[$title])) {
                if (!empty($keep[$title]['image'])) {
                    $item['image'] = $keep[$title]['image'];
                }
                if (!empty($keep[$title]['attrs'])) {
                    $item['attrs'] = $keep[$title]['attrs'];
                }
            }
            $items[] = $item;
        }
        return $items;
    }

    /** 联系方式动态行 → [{method,value,label?}]（value 非空 + method 合法才成一条） */
    private function parseContacts(Request $request): array
    {
        $raw = $request->input('contacts', []);
        if (!is_array($raw)) {
            return [];
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
        return $out;
    }
}
