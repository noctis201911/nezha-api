<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\NezhaContacts;
use App\CentralLogics\NezhaListing;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒[外卖TG化 Phase1·挂牌态] 后台运营入口。
 *
 * 补 CHANGELOG 2026-07-20 登记的两个缺口：①无总闸（关闭要 revert+重部署）②无后台界面（开一家店要手工 DML，
 * 且 nezha_contacts 的 JSON 要运营手写）。
 *
 * 本页只做三件事，都不碰资金/退款/结算：
 *   1. 总闸  business_settings.nezha_listing_status（读写单点在 NezhaListing）
 *   2. 逐店开关 restaurants.nezha_listing_only
 *   3. 逐店顾客侧公开联系方式 restaurants.nezha_contacts（结构化表单，method 枚举与 NezhaContacts::METHODS 同源）
 *
 * 🔴 未决合规项（业主 2026-07-21 拍板「先做工具 + 后台硬提示」）：
 *    平台代为公开一个**未经 OFAC 姓名筛查**的商家联系方式，是否落入 L1-6 射程，尚未裁定
 *    （见 docs/compliance/CHANGELOG.md 2026-07-20 条目）。故本页顶部对运营写死一条红字提示，
 *    但**不做技术性硬拦**——现网 vendor_kyc_profiles 为 0 行，硬拦会连平台自建种子店一起废掉。
 */
class NezhaListingController extends Controller
{
    /** 联系方式最多几条（够用即可，防运营贴一屏） */
    private const MAX_CONTACTS = 6;

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));

        $listed = Restaurant::where('nezha_listing_only', 1)
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'status', 'nezha_listing_only', 'nezha_contacts']);

        // 搜索结果只用于「把一家店加进挂牌」——已挂牌的店在上面那张表里操作
        $candidates = collect();
        if ($search !== '') {
            $candidates = Restaurant::where(function ($q) {
                    $q->whereNull('nezha_listing_only')->orWhere('nezha_listing_only', 0);
                })
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%');
                    if (is_numeric($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                })
                ->orderBy('id')
                ->limit(20)
                ->get(['id', 'name', 'slug', 'status', 'nezha_listing_only']);
        }

        return view('admin-views.nezha-listing.index', [
            'master_on'   => NezhaListing::enabled(),
            // 🔴 关总闸的真实代价 = 这些【已上架】的挂牌店立刻恢复站内接单(其中不少是代建占位账号、
            //    后台无人接单)。做成实时数字给运营看, 别让人凭「反正能回滚」的印象点下去。
            'listed_live_count' => $listed->where('status', 1)->count(),
            'listed'      => $listed,
            'candidates'  => $candidates,
            'search'      => $search,
            'methods'     => NezhaContacts::METHODS,
            'method_label' => [
                'telegram' => 'Telegram',
                'whatsapp' => 'WhatsApp',
                'wechat'   => translate('微信'),
                'phone'    => translate('电话'),
            ],
        ]);
    }

    /** 总闸（真实影响开关：关 = 全部挂牌店回到功能上线前，预建店直链 404） */
    public function toggleMaster(Request $request)
    {
        $enable = $request->boolean('enable');

        DB::table('business_settings')->updateOrInsert(
            ['key' => NezhaListing::SWITCH_KEY],
            ['value' => $enable ? '1' : '0', 'updated_at' => now()]
        );
        NezhaListing::flushCache();

        Log::info('[nezha-listing] master switch -> ' . ($enable ? '1' : '0') . ' by admin#' . (auth('admin')->id() ?? '?'));
        Toastr::success($enable
            ? translate('挂牌态总闸已开启（顾客侧最长约 60 秒后生效）')
            : translate('挂牌态总闸已关闭：所有挂牌店回到未挂牌状态，预建店（未上架）的直链将不可访问'));

        return back();
    }

    /** 逐店挂牌开关 */
    public function toggleStore(Request $request, Restaurant $restaurant)
    {
        $enable = $request->boolean('enable');

        // 🔴 给「正在营业、可下单」的店开挂牌 = 这家店立刻停止站内接单（前端不渲染下单入口 + 后端 403）。
        // 界面上已有二次确认弹窗；这里再要一次显式 ack，防绕过界面直接 POST 误开真实营业店。
        if ($enable && (int) $restaurant->status === 1 && ! $request->boolean('ack_active')) {
            Toastr::error(translate('该商家当前正常营业接单，开启挂牌态需要二次确认'));
            return back();
        }

        $restaurant->nezha_listing_only = $enable ? 1 : 0;
        $restaurant->save();

        Log::info('[nezha-listing] restaurant#' . $restaurant->id . ' listing_only -> ' . ($enable ? '1' : '0')
            . ' (status=' . (int) $restaurant->status . ') by admin#' . (auth('admin')->id() ?? '?'));

        if ($enable) {
            Toastr::success(NezhaListing::enabled()
                ? translate('已开启挂牌态。别忘了填联系方式，否则顾客点「联系店家」会无处可去')
                : translate('已开启挂牌态，但总闸当前是关的，顾客侧暂不生效'));
        } else {
            Toastr::success(translate('已关闭挂牌态'));
        }

        return back();
    }

    /** 顾客侧公开联系方式（结构化表单 → JSON） */
    public function updateContacts(Request $request, Restaurant $restaurant)
    {
        $methods = (array) $request->input('method', []);
        $values  = (array) $request->input('value', []);
        $labels  = (array) $request->input('label', []);

        $contacts = [];
        foreach ($methods as $i => $method) {
            $method = strtolower(trim((string) $method));
            $value  = trim((string) ($values[$i] ?? ''));
            $label  = trim((string) ($labels[$i] ?? ''));

            if ($value === '') {
                continue; // 空行 = 这条不要了
            }
            if (! in_array($method, NezhaContacts::METHODS, true)) {
                Toastr::error(translate('联系方式类型不合法'));
                return back();
            }
            if (mb_strlen($value) > 191 || mb_strlen($label) > 60) {
                Toastr::error(translate('联系方式内容过长'));
                return back();
            }

            $contacts[] = ['method' => $method, 'value' => $value, 'label' => $label];
            if (count($contacts) >= self::MAX_CONTACTS) {
                break;
            }
        }

        // 规范化后为空 = 顾客侧根本拿不到可用入口，直接提醒运营（不拦，允许先清空）
        $normalized = NezhaContacts::normalize($contacts);

        $restaurant->nezha_contacts = $contacts ?: null;
        $restaurant->save();

        Log::info('[nezha-listing] restaurant#' . $restaurant->id . ' contacts updated: ' . count($contacts)
            . ' item(s) by admin#' . (auth('admin')->id() ?? '?'));

        if ($contacts && ! $normalized) {
            Toastr::warning(translate('已保存，但这些联系方式规范化后为空，顾客侧不会显示，请检查填写内容'));
        } else {
            Toastr::success(translate('联系方式已保存（顾客侧最长约 60 秒后生效）'));
        }

        return back();
    }
}
