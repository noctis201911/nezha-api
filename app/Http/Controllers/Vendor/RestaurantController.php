<?php

namespace App\Http\Controllers\Vendor;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Restaurant;
use App\Models\Translation;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class RestaurantController extends Controller
{
    public function view()
    {
        $shop = Helpers::get_restaurant_data();
        return view('vendor-views.shop.shopInfo', compact('shop'));
    }

    // 哪吒: 门店形象合并页 — logo/封面/分享图集中一处 + 顾客端预览 + 显示位置标注
    public function brand()
    {
        $shop = Helpers::get_restaurant_data();
        return view('vendor-views.shop.brand', compact('shop'));
    }

    public function edit()
    {
        $shop = Restaurant::withoutGlobalScope('translate')->with('translations')->find(Helpers::get_restaurant_id());

        // 哪吒: 商家自助改地图定位 — 取本店配送区多边形+中心, 供地图选点器画蓝区并做区内校验
        $zonePolygon = [];
        $zoneCenter = null;
        try {
            $zone = \App\Models\Zone::withoutGlobalScopes()
                ->selectRaw('*, ST_AsText(ST_Centroid(`coordinates`)) as center')
                ->find($shop->zone_id);
            if ($zone && $zone->coordinates) {
                $area = json_decode($zone->coordinates[0]->toJson(), true);
                $zonePolygon = \App\CentralLogics\Helpers::format_coordiantes($area['coordinates']);
                $parts = explode(' ', trim((string) $zone->center));
                $zoneCenter = [
                    'lat' => (float) trim($parts[1] ?? '', 'POINT()'),
                    'lng' => (float) trim($parts[0] ?? '', 'POINT()'),
                ];
            }
        } catch (\Throwable $e) {
            $zonePolygon = [];
            $zoneCenter = null;
        }

        return view('vendor-views.shop.edit', compact('shop', 'zonePolygon', 'zoneCenter'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required|max:191',
            'address' => 'nullable|max:1000',
            'contact' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:9|max:20|unique:restaurants,phone,'.Helpers::get_restaurant_id(),
            'image' => 'nullable|max:2048',
            'photo' => 'nullable|max:2048',

        ], [
            'f_name.required' => translate('messages.first_name_is_required'),
        ]);

        if($request->name[array_search('default', $request->lang)] == '' ){
            Toastr::error(translate('default_restaurant_name_is_required'));
            return back();
        }
        if($request->address[array_search('default', $request->lang)] == '' ){
            Toastr::error(translate('default_restaurant_address_is_required'));
            return back();
        }

        $shop = Restaurant::findOrFail(Helpers::get_restaurant_id());
        $shop->name = $request->name[array_search('default', $request->lang)];
        $shop->address = $request->address[array_search('default', $request->lang)];
        $shop->phone = $request->contact;
        $shop->logo = $request->has('image') ? Helpers::update(dir: 'restaurant/',old_image:  $shop->logo ,format: 'png', image: $request->file('image')) : $shop->logo;
        $shop->cover_photo = $request->has('photo') ? Helpers::update(dir: 'restaurant/cover/',old_image:  $shop->cover_photo,  format:'png',image:  $request->file('photo')) : $shop->cover_photo;

        // 哪吒: 商家自助改地图定位 — 仅当坐标真的变了才校验+落库(留痕); 变动必须仍落在本店配送区内
        if ($request->filled('latitude') && $request->filled('longitude') && is_numeric($request->latitude) && is_numeric($request->longitude)) {
            $newLat = round((float) $request->latitude, 6);
            $newLng = round((float) $request->longitude, 6);
            $oldLat = round((float) $shop->latitude, 6);
            $oldLng = round((float) $shop->longitude, 6);
            if ($newLat !== $oldLat || $newLng !== $oldLng) {
                if ($newLat < -90 || $newLat > 90 || $newLng < -180 || $newLng > 180) {
                    Toastr::error(translate('坐标无效，定位未保存'));
                    return back();
                }
                // 服务端二次校验: 新坐标必须仍在本店当前配送区多边形内(不信任前端几何判断)
                $inZone = \App\Models\Zone::query()
                    ->whereContains('coordinates', new \MatanYadaev\EloquentSpatial\Objects\Point($newLat, $newLng, POINT_SRID))
                    ->where('id', $shop->zone_id)->first();
                if (!$inZone) {
                    Toastr::error(translate('图钉超出了你的配送区范围，定位未保存，请把它拖回配送区内'));
                    return back();
                }
                // 留痕: 记录 旧→新 坐标 + 操作者 + 时间(保留最近10条)
                $ad = json_decode((string) $shop->additional_data, true);
                if (!is_array($ad)) { $ad = []; }
                $edits = (isset($ad['location_edits']) && is_array($ad['location_edits'])) ? $ad['location_edits'] : [];
                $edits[] = [
                    'from' => ['lat' => (string) $shop->latitude, 'lng' => (string) $shop->longitude],
                    'to'   => ['lat' => (string) $newLat, 'lng' => (string) $newLng],
                    'by'   => 'vendor:' . $shop->vendor_id,
                    'at'   => now()->toDateTimeString(),
                ];
                $ad['location_edits'] = array_slice($edits, -10);
                $shop->additional_data = json_encode($ad, JSON_UNESCAPED_UNICODE);
                $shop->latitude = $newLat;
                $shop->longitude = $newLng;
            }
        }
        $shop?->save();
        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach($request->lang as $index=>$key)
        {
            if($default_lang == $key && !($request->name[$index])){
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\Restaurant',
                            'translationable_id' => $shop->id,
                            'locale' => $key,
                            'key' => 'name'
                        ],
                        ['value' => $shop->name]
                    );
                }
            }else{

                if ($request->name[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        ['translationable_type'  => 'App\Models\Restaurant',
                            'translationable_id'    => $shop->id,
                            'locale'                => $key,
                            'key'                   => 'name'],
                            ['value'                 => $request->name[$index]]
                        );
                }
            }
            if($default_lang == $key && !($request->address[$index])){
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\Restaurant',
                            'translationable_id' => $shop->id,
                            'locale' => $key,
                            'key' => 'address'
                        ],
                        ['value' => $shop->address]
                    );
                }
            }else{

                if ($request->address[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        ['translationable_type'  => 'App\Models\Restaurant',
                        'translationable_id'    => $shop->id,
                        'locale'                => $key,
                        'key'                   => 'address'],
                        ['value'                 => $request->address[$index]]
                    );
                }
            }
        }
        if($shop?->vendor?->userinfo) {
            $userinfo = $shop->vendor->userinfo;
            $userinfo->f_name = $shop->name;
            $userinfo->image = $shop->logo;
            $userinfo?->save();
        }

        Toastr::success(translate('messages.restaurant_data_updated'));
        return redirect()->route('vendor.shop.view');
    }

    public function logo_update(Request $request)
    {
        $request->validate([
            'logo' => 'nullable|max:2048',
        ], [
            'logo.max' => '图片太大了，请压缩到 2MB 以内再上传',
        ]);

        $shop = Restaurant::findOrFail(Helpers::get_restaurant_id());
        $shop->logo = $request->has('logo') ? Helpers::update(dir: 'restaurant/',old_image:  $shop->logo ,format: 'png', image: $request->file('logo')) : $shop->logo;
        $shop?->save();

        if($shop?->vendor?->userinfo) {
            $userinfo = $shop->vendor->userinfo;
            $userinfo->image = $shop->logo;
            $userinfo?->save();
        }

        Toastr::success(translate('messages.restaurant_logo_updated'));
        return back();
    }

    public function cover_update(Request $request)
    {
        $request->validate([
            'cover_photo' => 'nullable|max:2048',
        ], [
            'cover_photo.max' => '图片太大了，请压缩到 2MB 以内再上传',
        ]);

        $shop = Restaurant::findOrFail(Helpers::get_restaurant_id());
        $shop->cover_photo = $request->has('cover_photo') ? Helpers::update(dir: 'restaurant/cover',old_image:  $shop->cover_photo ,format: 'png', image: $request->file('cover_photo')) : $shop->cover_photo;
        $shop?->save();

        Toastr::success(translate('messages.restaurant_cover_photo_updated'));
        return back();
    }

    // 哪吒: 只更新分享缩略图(meta_image) — 独立小接口, 绝不走 updateStoreMetaData(那是餐厅配置大表单, 会连带清零 delivery 等设置)
    public function meta_image_update(Request $request)
    {
        $request->validate([
            'meta_image' => 'nullable|image|max:2048',
        ], [
            'meta_image.image' => '请上传图片文件（JPG / PNG / WebP）',
            'meta_image.max' => '图片太大了，请压缩到 2MB 以内再上传',
        ]);

        $shop = Restaurant::findOrFail(Helpers::get_restaurant_id());
        $shop->meta_image = $request->has('meta_image') ? Helpers::update(dir: 'restaurant/', old_image: $shop->meta_image, format: 'png', image: $request->file('meta_image')) : $shop->meta_image;
        $shop?->save();

        Toastr::success(translate('分享图已更新'));
        return back();
    }

    public function update_message(Request $request)
    {
        $request->validate([
            'announcement_message' => 'required|max:255',
        ]);
        $shop = Restaurant::findOrFail(Helpers::get_restaurant_id());
        $shop->announcement_message = $request->announcement_message;
        $shop->save();

        Toastr::success(translate('messages.restaurant_data_updated'));
        return redirect()->route('vendor.shop.view');
    }

    public function qr_view()
    {
        $restaurant = Helpers::get_restaurant_data();
        $data = json_decode($restaurant->qr_code, true);
        $code = isset($data)
            ? QrCode::size(180)->generate(
                $data['website']
                . (str_contains($data['website'], '?') ? ' ' : '?')
                . 'qrcode='
                . urlencode(base64_encode(json_encode($data)))
            )
            : '';
        return view('vendor-views.shop.qrcode', compact('restaurant','data', 'code'));
    }
    public function qr_store(Request $request)
    {
        $restaurant = Helpers::get_restaurant_data();
        $request->validate([
            'title' => 'required',
            'description' => 'required',
            'phone' => 'required',
            'website' => 'required'
        ]);


        $data = [];

        $data['title'] = $request->title;
        $data['description'] = $request->description;
        $data['phone'] = $request->phone;
        $data['website'] = $request->website;

        $restaurant->qr_code = json_encode($data);
        $restaurant->save();

        Toastr::success(translate('updated successfully'));
        return back();

    }

    public function qr_pdf()
    {
        $restaurant = Helpers::get_restaurant_data();
        $data = json_decode($restaurant->qr_code, true);
        $code = isset($data)?QrCode::size(180)->generate(json_encode($data)):'';

        $pdf = PDF::loadView('vendor-views.shop.qrcode-pdf', compact('restaurant','data', 'code'))->setOptions(['defaultFont' => 'sans-serif']);
        return $pdf->download('qr-code' . rand(00001, 99999) . '.pdf');
    }

    public function qr_print()
    {
        $restaurant = Helpers::get_restaurant_data();
        $data = json_decode($restaurant->qr_code, true);
        $code = isset($data)
            ? QrCode::size(180)->generate(
                $data['website']
                . (str_contains($data['website'], '?') ? ' ' : '?')
                . 'qrcode='
                . urlencode(base64_encode(json_encode($data)))
            )
            : '';
        return view('vendor-views.shop.qrcode-print', compact('restaurant','data', 'code'));
    }

}
