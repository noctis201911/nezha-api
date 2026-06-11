<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\CentralLogics\RestaurantLogic;
use App\Exports\DisbursementHistoryExport;
use App\Exports\RestauranrWiseFoodReviewExport;
use App\Exports\RestaurantListExport;
use App\Exports\RestaurantWiseCashCollectionExport;
use App\Exports\RestaurantWiseWithdrawOrderTransactionExport;
use App\Exports\RestaurantWiseWithdrawTransactionExport;
use App\Exports\RestaurantWithdrawTransactionExport;
use App\Http\Controllers\Controller;
use App\Models\AccountTransaction;
use App\Models\AddOn;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Cuisine;
use App\Models\DataSetting;
use App\Models\DisbursementDetails;
use App\Models\Food;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Restaurant;
use App\Models\RestaurantConfig;
use App\Models\RestaurantSchedule;
use App\Models\RestaurantWallet;
use App\Models\SubscriptionPackage;
use App\Models\Tag;
use App\Models\Translation;
use App\Models\UserInfo;
use App\Models\Vendor;
use App\Models\WithdrawRequest;
use App\Models\Zone;
use App\Scopes\RestaurantScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Maatwebsite\Excel\Facades\Excel;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Rap2hpoutre\FastExcel\FastExcel;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class VendorController extends Controller
{
    public function index()
    {
        $page_data = DataSetting::Where('type', 'restaurant')->where('key', 'restaurant_page_data')->first()?->value;
        $page_data = $page_data ? json_decode($page_data, true) : [];

        return view('admin-views.vendor.index', compact('page_data'));
    }

    public function store(Request $request)
    {

        // dd($request->all());
        $validator = Validator::make($request->all(), [
            'f_name' => 'required|max:100',
            'l_name' => 'nullable|max:100',
            'name' => 'required|max:191',
            'address' => 'required|max:1000',
            'latitude' => 'required|numeric|min:-90|max:90',
            'longitude' => 'required|numeric|min:-180|max:180',
            'email' => 'required|unique:vendors',
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:9|max:20|unique:vendors',
            'minimum_delivery_time' => 'required',
            'maximum_delivery_time' => 'required|gt:minimum_delivery_time',
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            // 'additional_documents' => 'nullable|array|max:5',
            // 'additional_documents.*' => 'nullable|max:2048',
            'zone_id' => 'required',
            'logo' => 'required|max:2048|image|mimes:' . IMAGE_FORMAT_FOR_VALIDATION,
            'cover_photo' => 'required|max:2048|image|mimes:' . IMAGE_FORMAT_FOR_VALIDATION,

            'delivery_time_type' => 'required',
        ], [
            'f_name.required' => translate('messages.first_name_is_required'),
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
            'password.custom' => translate('The password cannot contain white spaces.'),
            // 'additional_documents.max' => translate('You_can_chose_max_5_files_only'),
        ]);

        $page_data = DataSetting::Where('type', 'restaurant')->where('key', 'restaurant_page_data')->first()?->value;
        $page_data = $page_data ? json_decode($page_data, true) : [];

        if ($page_data) {
            foreach ($page_data['data'] as $item) {
                if ($item['field_type'] == 'file' && $item['is_required'] == 1) {
                    if (! isset($request->additional_documents[$item['input_data']]) || count($request->additional_documents[$item['input_data']]) < 1) {
                        $validator->getMessageBag()->add($item['input_data'], translate('messages.atleast_one_file_is_required_for_'.$item['input_data']));
                        return response()->json([
                            'errors' => $validator->errors(),
                        ], 422);
                    }
                }
            }
        }

        $cuisine_ids = [];
        $cuisine_ids = $request->cuisine_ids;
        if ($request->zone_id) {
            $zone = Zone::query()
                ->whereContains('coordinates', new Point($request->latitude, $request->longitude, POINT_SRID))->where('id', $request->zone_id)->first();
            if (!$zone) {
                $validator->getMessageBag()->add('latitude', translate('messages.Please_select_a_location_within_the_selected_zone'));

                return response()->json([
                    'errors' => $validator->errors()
                ], 422);
            }
        }


        if ($request->name[array_search('default', $request->lang)] == '') {
            $validator->getMessageBag()->add('address', translate('messages.default_restaurant_name_is_required'));

            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }
        if ($request->address[array_search('default', $request->lang)] == '') {
            $validator->getMessageBag()->add('address', translate('messages.default_restaurant_address_is_required'));

            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->delivery_time_type == 'min') {
            $minimum_delivery_time = (int) $request->input('minimum_delivery_time');
            if ($minimum_delivery_time < 10) {
                $validator->getMessageBag()->add('minimum_delivery_time', translate('messages.minimum_delivery_time_should_be_more_than_10_min'));

                return response()->json([
                    'errors' => $validator->errors()
                ], 422);
            }
        }
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $tag_ids = [];
        if ($request->tags != null) {
            $tags = explode(',', $request->tags);
        }
        if (isset($tags)) {
            foreach ($tags as $key => $value) {
                $tag = Tag::firstOrNew(
                    ['tag' => $value]
                );
                $tag->save();
                array_push($tag_ids, $tag->id);
            }
        }

        $vendor = new Vendor;
        $vendor->f_name = $request->f_name;
        $vendor->l_name = $request->l_name;
        $vendor->email = $request->email;
        $vendor->phone = $request->phone;
        $vendor->password = bcrypt($request->password);
        $vendor->save();

        $restaurant = new Restaurant;
        $restaurant->name = $request->name[array_search('default', $request->lang)];
        $restaurant->phone = $request->phone;
        $restaurant->email = $request->email;
        $restaurant->logo = Helpers::upload(dir: 'restaurant/', format: 'png', image: $request->file('logo'));
        $restaurant->cover_photo = Helpers::upload(dir: 'restaurant/cover/', format: 'png', image: $request->file('cover_photo'));
        $restaurant->address = $request->address[array_search('default', $request->lang)];
        $restaurant->latitude = $request->latitude;
        $restaurant->longitude = $request->longitude;
        $restaurant->vendor_id = $vendor->id;
        $restaurant->zone_id = $request->zone_id;

        $restaurant->restaurant_model = 'none';
        $restaurant->delivery_time = $request->minimum_delivery_time . '-' . $request->maximum_delivery_time . '-' . $request->delivery_time_type;

        if (isset($request->additional_data) && count($request->additional_data) > 0) {
            $restaurant->additional_data = json_encode($request->additional_data);
        }

        $additional_documents = [];
        if ($request->additional_documents) {
            foreach ($request->additional_documents as $key => $data) {
                $additional = [];
                foreach ($data as $file) {
                    if (is_file($file)) {
                        $file_name = Helpers::upload('additional_documents/', $file->getClientOriginalExtension(), $file);
                        $additional[] = ['file' => $file_name, 'storage' => Helpers::getDisk()];
                    }
                    $additional_documents[$key] = $additional;
                }
            }
            $restaurant->additional_documents = json_encode($additional_documents);
        }

        $restaurant->tin = $request->tin;
        $restaurant->tin_expire_date = $request->tin_expire_date;
        $extension = $request->has('tin_certificate_image') ? $request->file('tin_certificate_image')->getClientOriginalExtension() : 'png';
        $restaurant->tin_certificate_image = $request->has('tin_certificate_image') ? Helpers::upload('restaurant/', $extension, $request->file('tin_certificate_image')) : null;

        $restaurant->save();
        $restaurant->cuisine()->sync($cuisine_ids);
        $restaurant->tags()->sync($tag_ids);

        $default_lang = str_replace('_', '-', app()->getLocale());
        $data = [];
        foreach ($request->lang as $index => $key) {
            if ($default_lang == $key && !($request->name[$index])) {
                if ($key != 'default') {
                    array_push($data, [
                        'translationable_type' => 'App\Models\Restaurant',
                        'translationable_id' => $restaurant->id,
                        'locale' => $key,
                        'key' => 'name',
                        'value' => $restaurant->name,
                    ]);
                }
            } else {
                if ($request->name[$index] && $key != 'default') {
                    array_push($data, [
                        'translationable_type' => 'App\Models\Restaurant',
                        'translationable_id' => $restaurant->id,
                        'locale' => $key,
                        'key' => 'name',
                        'value' => $request->name[$index],
                    ]);
                }
            }
            if ($default_lang == $key && !($request->address[$index])) {
                if ($key != 'default') {
                    array_push($data, [
                        'translationable_type' => 'App\Models\Restaurant',
                        'translationable_id' => $restaurant->id,
                        'locale' => $key,
                        'key' => 'address',
                        'value' => $restaurant->address,
                    ]);
                }
            } else {
                if ($request->address[$index] && $key != 'default') {
                    array_push($data, [
                        'translationable_type' => 'App\Models\Restaurant',
                        'translationable_id' => $restaurant->id,
                        'locale' => $key,
                        'key' => 'address',
                        'value' => $request->address[$index],
                    ]);
                }
            }
        }
        Translation::insert($data);

        return response()->json([
            'message' => translate('messages.restaurant_added_successfully'),
            'redirect' => route('admin.restaurant.list'),
        ]);
    }

    public function edit($id)
    {
        if (env('APP_MODE') == 'demo' && $id == 2) {
            Toastr::warning(translate('messages.you_can_not_edit_this_restaurant_please_add_a_new_restaurant_to_edit'));

            return back();
        }
        $restaurant = Restaurant::withoutGlobalScope('translate')->with('translations')->find($id);
        $additional_documents_size = 0;

        if (!empty($restaurant['additional_documents'])) {
            $path = 'public/additional_documents/' . $restaurant['additional_documents'];

            if (Storage::exists($path)) {
                $sizeInBytes = Storage::size($path);
                $additional_documents_size = round($sizeInBytes / 1048576, 2);
            }
        }

        $page_data = DataSetting::where('type', 'restaurant')
            ->where('key', 'restaurant_page_data')
            ->first()?->value;
        $page_data = $page_data ? json_decode($page_data, true) : [];
        $additional_data = json_decode($restaurant->additional_data ?? '[]', true);

        return view('admin-views.vendor.edit', compact('restaurant', 'page_data', 'additional_data', 'additional_documents_size'));
    }

    public function update(Request $request, Restaurant $restaurant)
    {
        $validator = Validator::make($request->all(), [
            'f_name' => 'required|max:100',
            'l_name' => 'nullable|max:100',
            'name' => 'required|max:191',
            'email' => 'required|unique:vendors,email,' . $restaurant?->vendor?->id,
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:9|max:20|unique:vendors,phone,' . $restaurant?->vendor?->id,
            'zone_id' => 'required',
            'latitude' => 'required|min:-90|max:90',
            'longitude' => 'required|min:-180|max:180',
            'password' => ['nullable', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'minimum_delivery_time' => 'required',
            'maximum_delivery_time' => 'required|gt:minimum_delivery_time',
            'logo' => 'nullable|max:2048|image|mimes:' . IMAGE_FORMAT_FOR_VALIDATION,
            'cover_photo' => 'nullable|max:2048|image|mimes:' . IMAGE_FORMAT_FOR_VALIDATION,
            'delivery_time_type' => 'required',
        ], [
            'f_name.required' => translate('messages.first_name_is_required'),
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
            'password.custom' => translate('The password cannot contain white spaces.'),
        ]);

        $page_data = DataSetting::Where('type', 'restaurant')->where('key', 'restaurant_page_data')->first()?->value;
        $page_data = $page_data ? json_decode($page_data, true) : [];
        $additional_documents = json_decode($restaurant->additional_documents, true) ?? [];

        if ($page_data) {
            foreach ($page_data['data'] as $item) {
                if ($item['field_type'] == 'file' && $item['is_required'] == 1) {
                    $field_name = $item['input_data'];
                    $existing_count = isset($additional_documents[$field_name]) ? count($additional_documents[$field_name]) : 0;
                    $removed_count = isset($request->removed_additional_documents[$field_name]) ? count($request->removed_additional_documents[$field_name]) : 0;
                    $new_count = isset($request->additional_documents[$field_name]) ? count($request->additional_documents[$field_name]) : 0;
                    if (($existing_count - $removed_count + $new_count) < 1) {
                        $validator->getMessageBag()->add($field_name, translate('messages.atleast_one_file_is_required_for_'.$field_name));

                        return response()->json([
                            'errors' => $validator->errors(),
                        ], 422);
                    }
                }
            }
        }

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->name[array_search('default', $request->lang)] == '') {
            $validator->getMessageBag()->add('address', translate('messages.default_restaurant_name_is_required'));

            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }
        if ($request->address[array_search('default', $request->lang)] == '') {
            $validator->getMessageBag()->add('address', translate('messages.default_restaurant_address_is_required'));

            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }
        if ($request?->zone_id) {
            $zone = Zone::query()
                ->whereContains('coordinates', new Point($request->latitude, $request->longitude, POINT_SRID))
                ->where('id', $request->zone_id)
                ->first();

            if (!$zone) {
                $validator->getMessageBag()->add('latitude', translate('messages.Please_select_a_location_within_the_selected_zone'));

                return response()->json([
                    'errors' => $validator->errors(),
                ], 422);
            }
        }

        if ($request->delivery_time_type == 'min') {
            $minimum_delivery_time = (int) $request->input('minimum_delivery_time');
            if ($minimum_delivery_time < 10) {
                $validator->getMessageBag()->add('minimum_delivery_time', translate('messages.minimum_delivery_time_should_be_more_than_10_min'));

                return response()->json([
                    'errors' => $validator->errors(),
                ], 422);
            }
        }


        $tag_ids = [];
        if ($request->tags != null) {
            $tags = explode(',', $request->tags);
        }
        if (isset($tags)) {
            foreach ($tags as $key => $value) {
                $tag = Tag::firstOrNew(
                    ['tag' => $value]
                );
                $tag->save();
                array_push($tag_ids, $tag->id);
            }
        }

        $vendor = Vendor::findOrFail($restaurant?->vendor?->id);
        $vendor->f_name = $request->f_name;
        $vendor->l_name = $request->l_name;
        $vendor->email = $request->email;
        $vendor->phone = $request->phone;
        $vendor->password = strlen($request->password) > 1 ? bcrypt($request->password) : $restaurant->vendor->password;
        $vendor->save();

        $cuisine_ids = [];
        $cuisine_ids = $request->cuisine_ids;

        $slug = Str::slug($request->name[array_search('default', $request->lang)]);
        $restaurant->slug = $restaurant->slug ? $restaurant->slug : "{$slug}{$restaurant->id}";

        $restaurant->email = $request->email;
        $restaurant->phone = $request->phone;
        $restaurant->logo = $request->has('logo') ? Helpers::update(dir: 'restaurant/', old_image: $restaurant->logo, format: 'png', image: $request->file('logo')) : $restaurant->logo;
        $restaurant->cover_photo = $request->has('cover_photo') ? Helpers::update(dir: 'restaurant/cover/', old_image: $restaurant->cover_photo, format: 'png', image: $request->file('cover_photo')) : $restaurant->cover_photo;
        $restaurant->name = $request->name[array_search('default', $request->lang)];
        $restaurant->address = $request->address[array_search('default', $request->lang)];
        $restaurant->latitude = $request->latitude;
        $restaurant->longitude = $request->longitude;
        $restaurant->zone_id = $request->zone_id;
        $restaurant->delivery_time = $request->minimum_delivery_time . '-' . $request->maximum_delivery_time . '-' . $request->delivery_time_type;

        $restaurant->tin = $request->tin;
        $restaurant->tin_expire_date = $request->tin_expire_date;
        $extension = $request->has('tin_certificate_image') ? $request->file('tin_certificate_image')->getClientOriginalExtension() : 'png';
        $restaurant->tin_certificate_image = $request->has('tin_certificate_image') ? Helpers::update('restaurant/', $restaurant->tin_certificate_image, $extension, $request->file('tin_certificate_image')) : $restaurant->tin_certificate_image;

        if (isset($request->additional_data) && count($request->additional_data) > 0) {
            $restaurant->additional_data = json_encode($request->additional_data);
        }

        $additional_documents = json_decode($restaurant->additional_documents, true) ?? [];

        if ($request->removed_additional_documents) {
            foreach ($request->removed_additional_documents as $key => $files) {
                if (isset($additional_documents[$key])) {
                    foreach ($files as $file_to_remove) {
                        foreach ($additional_documents[$key] as $index => $doc) {
                            if ($doc['file'] === $file_to_remove) {
                                Helpers::check_and_delete('additional_documents/', $doc['file']);
                                unset($additional_documents[$key][$index]);
                            }
                        }
                        $additional_documents[$key] = array_values($additional_documents[$key]);
                    }
                }
            }
        }

        if ($request->additional_documents) {
            foreach ($request->additional_documents as $key => $data) {
                $additional = $additional_documents[$key] ?? [];
                foreach ($data as $file) {
                    if (is_file($file)) {
                        $file_name = Helpers::upload('additional_documents/', $file->getClientOriginalExtension(), $file);
                        $additional[] = ['file' => $file_name, 'storage' => Helpers::getDisk()];
                    }
                }
                $additional_documents[$key] = $additional;
            }
        }
        $restaurant->additional_documents = json_encode($additional_documents);

        $restaurant->save();
        $restaurant->tags()->sync($tag_ids);

        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach ($request->lang as $index => $key) {
            if ($default_lang == $key && !($request->name[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\Restaurant',
                            'translationable_id' => $restaurant->id,
                            'locale' => $key,
                            'key' => 'name',
                        ],
                        ['value' => $restaurant->name]
                    );
                }
            } else {

                if ($request->name[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\Restaurant',
                            'translationable_id' => $restaurant->id,
                            'locale' => $key,
                            'key' => 'name'
                        ],
                        ['value' => $request->name[$index]]
                    );
                }
            }
            if ($default_lang == $key && !($request->address[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\Restaurant',
                            'translationable_id' => $restaurant->id,
                            'locale' => $key,
                            'key' => 'address',
                        ],
                        ['value' => $restaurant->address]
                    );
                }
            } else {

                if ($request->address[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\Restaurant',
                            'translationable_id' => $restaurant->id,
                            'locale' => $key,
                            'key' => 'address'
                        ],
                        ['value' => $request->address[$index]]
                    );
                }
            }
        }
        $restaurant?->cuisine()?->sync($cuisine_ids);
        if ($vendor?->userinfo) {
            $userinfo = $vendor->userinfo;
            $userinfo->f_name = $restaurant->name;
            $userinfo->l_name = '';
            $userinfo->email = $request->email;
            $userinfo->image = $restaurant->logo;
            $userinfo->save();
        }
        Toastr::success(translate('messages.restaurant_updated_successfully'));

        if ($request?->new_join) {
            return to_route('admin.restaurant.pending');
        }

        return response()->json([
            'message' => translate('messages.restaurant_updated_successfully'),
            'redirect' => route('admin.restaurant.list'),
        ]);
    }

    public function destroy(Request $request, Restaurant $restaurant)
    {
        if (env('APP_MODE') == 'demo' && $restaurant->id == 2) {
            Toastr::warning(translate('messages.you_can_not_delete_this_restaurant_please_add_a_new_restaurant_to_delete'));

            return back();
        }
        Helpers::check_and_delete('restaurant/', $restaurant['logo']);
        $vendor = Vendor::findOrFail($restaurant?->vendor?->id);
        $restaurant?->delete();
        $vendor?->userinfo?->delete();
        $vendor?->delete();
        Toastr::success(translate('messages.restaurant_removed'));

        return back();
    }

    /**
     * 哪吒外卖 B方案: 管理员代录商家本人的收款信息.
     * 顾客付款直接付进这些账户, 平台不碰钱 (去二清).
     */
    public function updatePaymentInfo(Request $request, Restaurant $restaurant)
    {
        $validator = Validator::make($request->all(), [
            'rmb_qr_image' => 'nullable|max:2048|image|mimes:' . IMAGE_FORMAT_FOR_VALIDATION,
            'payee_name'   => 'nullable|string|max:100',
            'usdt_address' => 'nullable|string|max:191',
            'usdt_network' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            Toastr::error($validator->errors()->first());
            return back();
        }

        if ($request->has('rmb_qr_image')) {
            $restaurant->rmb_qr_image = Helpers::update(
                dir: 'restaurant/payment_qr/',
                old_image: $restaurant->rmb_qr_image,
                format: 'png',
                image: $request->file('rmb_qr_image')
            );
        }
        $restaurant->payee_name   = $request->payee_name;
        $restaurant->usdt_address = $request->usdt_address;
        $restaurant->usdt_network = $request->usdt_network;
        $restaurant->save();

        Toastr::success(translate('messages.收款信息已保存'));
        return back();
    }

    /**
     * 哪吒外卖 B方案 组4: 管理员给商家充值/调整保证金, 并写入保证金流水。
     * amount 可正(充值)可负(扣减/纠错). 写 restaurant_deposit_transactions 留账。
     */
    public function rechargeDeposit(Request $request, Restaurant $restaurant)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|not_in:0',
            'note'   => 'nullable|string|max:191',
        ]);
        if ($validator->fails()) {
            Toastr::error($validator->errors()->first());
            return back();
        }

        $vendor_id = $restaurant->vendor_id;
        if (!$vendor_id) {
            Toastr::error(translate('该餐馆未绑定商家账户, 无法操作保证金'));
            return back();
        }

        $amount = round((float) $request->amount, 2);

        try {
            DB::beginTransaction();
            $wallet = RestaurantWallet::firstOrCreate(['vendor_id' => $vendor_id]);
            $wallet->deposit_balance = round(((float) ($wallet->deposit_balance ?? 0)) + $amount, 2);
            $wallet->save();

            \App\Models\RestaurantDepositTransaction::create([
                'vendor_id'     => $vendor_id,
                'restaurant_id' => $restaurant->id,
                'order_id'      => null,
                'type'          => $amount >= 0 ? 'recharge' : 'adjustment',
                'amount'        => $amount,
                'commission'    => 0,
                'balance_after' => $wallet->deposit_balance,
                'note'          => $request->note,
                'created_by'    => auth('admin')->id(),
            ]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            info(['nezha_recharge_deposit', $e->getFile(), $e->getLine(), $e->getMessage()]);
            Toastr::error(translate('保证金操作失败, 请重试'));
            return back();
        }

        Toastr::success(translate('保证金已更新'));
        return back();
    }

    // 哪吒外卖: 更新平台美元兑人民币汇率 (platform-wide, 所有商家共用)
    public function updateRmbRate(Request $request)
    {
        $rate = (float)$request->input('nezha_usd_to_rmb_rate', 7.1);
        if ($rate <= 0 || $rate > 100) {
            Toastr::error(translate('汇率无效, 请填写合理值 (如 7.1)'));
            return back();
        }
        \App\CentralLogics\Helpers::businessUpdateOrInsert(
            ['key' => 'nezha_usd_to_rmb_rate'],
            ['value' => (string)$rate]
        );
        \Illuminate\Support\Facades\Cache::forget('business_settings_keys');
        \Illuminate\Support\Facades\Cache::forget('business_settings_all_data');
        Toastr::success(translate('汇率已更新: 1 USD = ' . $rate . ' CNY'));
        return back();
    }

    public function view($restaurant, Request $request, $tab = null, $sub_tab = 'cash')
    {
        $key = explode(' ', $request['search']);

        $restaurant = Restaurant::find($restaurant);
        $wallet = $restaurant?->vendor?->wallet;
        if (!$wallet) {
            $wallet = new RestaurantWallet;
            $wallet->vendor_id = $restaurant?->vendor?->id;
            $wallet->total_earning = 0.0;
            $wallet->total_withdrawn = 0.0;
            $wallet->pending_withdraw = 0.0;
            $wallet->created_at = now();
            $wallet->updated_at = now();
            $wallet->save();
        }
        if ($tab == 'settings') {
            return view('admin-views.vendor.view.settings', compact('restaurant'));
        } elseif ($tab == 'payment_info') {
            // 哪吒外卖 B方案: 收款信息(商家本人收款码/USDT地址) + 保证金概览/充值/流水
            $depositMode = BusinessSetting::where('key', 'nezha_deposit_mode_status')->first()?->value;
            $depositThreshold = BusinessSetting::where('key', 'nezha_min_deposit_threshold')->first()?->value ?? 0;
            $depositBalance = $restaurant?->wallet?->deposit_balance ?? 0;
            $depositLogs = \App\Models\RestaurantDepositTransaction::where('restaurant_id', $restaurant->id)
                ->latest()->limit(20)->get();
            $rmbRate = (float)(BusinessSetting::where('key', 'nezha_usd_to_rmb_rate')->first()?->value ?? 7.1);
            return view('admin-views.vendor.view.payment-info', compact('restaurant', 'depositMode', 'depositThreshold', 'depositBalance', 'depositLogs', 'rmbRate'));
        } elseif ($tab == 'order') {
            $orders = Order::where('restaurant_id', $restaurant->id)->with('customer')
                ->when(isset($key), function ($q) use ($key) {
                    $q->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->orWhere('id', 'like', "%{$value}%");
                        }
                    });
                })
                ->latest()->Notpos()
                ->paginate(config('default_pagination'));

            return view('admin-views.vendor.view.order', compact('restaurant', 'orders'));
        } elseif ($tab == 'product') {

            $foods = Food::withoutGlobalScope(\App\Scopes\RestaurantScope::class)
                ->with(['category.parent', 'taxVats.tax'])
                ->where('restaurant_id', $restaurant->id)
                ->when(isset($key), function ($q) use ($key) {
                    $q->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->where('name', 'like', "%{$value}%");
                        }
                    });
                })
                ->latest()->paginate(config('default_pagination'));
            $addonIds = collect($foods->items())
                ->pluck('add_ons')
                ->filter()
                ->flatMap(fn($json) => json_decode($json, true) ?? [])
                ->unique()
                ->values();
            $taxData = Helpers::getTaxSystemType(getTaxVatList: false);
            $productWiseTax = $taxData['productWiseTax'];

            $addons = \App\Models\AddOn::withOutGlobalScope(\App\Scopes\RestaurantScope::class)
                ->whereIn('id', $addonIds)
                ->pluck('name', 'id');

            return view('admin-views.vendor.view.product', compact('restaurant', 'foods', 'addons', 'productWiseTax'));
        } elseif ($tab == 'discount') {
            return view('admin-views.vendor.view.discount', compact('restaurant'));
        } elseif ($tab == 'transaction') {
            return view('admin-views.vendor.view.transaction', compact('restaurant', 'sub_tab'));
        } elseif ($tab == 'reviews') {
            return view('admin-views.vendor.view.review', compact('restaurant', 'sub_tab'));
        } elseif ($tab == 'conversations') {
            $user = UserInfo::where(['vendor_id' => $restaurant?->vendor?->id])->first();

            if ($request->query('type')) {
                $type = $request->query('type');
            } else {
                $type = 'customer';
            }

            $conversations = Conversation::with(['sender', 'receiver', 'last_message'])->WhereUser($user?->id)->WhereUserType($type);

            if ($request->query('key')) {
                $key = explode(' ', $request->get('key'));
                $conversations = $conversations->where(function ($qu) use ($key) {
                    $qu->whereHas('sender', function ($query) use ($key) {
                        foreach ($key as $value) {
                            $query->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%")->orWhere('phone', 'like', "%{$value}%");
                        }
                    })
                        ->orWhereHas('receiver', function ($query1) use ($key) {
                            foreach ($key as $value) {
                                $query1->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%")->orWhere('phone', 'like', "%{$value}%");
                            }
                        });
                });
            }
            $conversations = $conversations->orderBy('last_message_time', 'DESC')
                ->paginate(8);

            if ($request->ajax()) {
                $view = view('admin-views.vendor.view.partials._conversation_list', compact('conversations', 'tab', 'type'))->render();

                return response()->json(['html' => $view]);
            }

            return view('admin-views.vendor.view.conversations', compact('restaurant', 'sub_tab', 'conversations', 'type', 'tab'));
        } elseif ($tab == 'business_plan') {

            $restaurant = Restaurant::where('id', $restaurant->id)->with([
                'restaurant_sub_update_application.package',
                'vendor',
                'restaurant_sub_update_application.last_transcations',
            ])->withcount('foods')
                ->first();
            $packages = SubscriptionPackage::where('status', 1)->latest()->get();
            $admin_commission = BusinessSetting::where('key', 'admin_commission')->first()?->value;
            $business_name = BusinessSetting::where('key', 'business_name')->first()?->value;
            try {
                $index = $restaurant->restaurant_model == 'commission' ? 0 : 1 + array_search($restaurant?->restaurant_sub_update_application?->package_id ?? 1, array_column($packages->toArray(), 'id'));
            } catch (\Throwable $th) {
                $index = 2;
            }

            return view('admin-views.vendor.view.subscriptions', compact('restaurant', 'packages', 'business_name', 'admin_commission', 'index'));

        } elseif ($tab == 'meta-data') {
            $meta = $restaurant->meta_data ?? [];

            return view('admin-views.vendor.view.meta-data', compact('restaurant', 'sub_tab', 'meta'));
        } elseif ($tab == 'qr-code') {
            $data = json_decode($restaurant->qr_code, true);
        $code = isset($data)
                ? QrCode::size(180)->generate(
                    $data['website']
                    . (str_contains($data['website'], '?') ? ' ' : '?')
                    . 'qrcode='
                    . urlencode(base64_encode(json_encode($data)))
                )
                : '';

            return view('admin-views.vendor.view.qrcode', compact('restaurant', 'data', 'code'));
        } elseif ($tab == 'disbursements') {
            $disbursements = DisbursementDetails::where('restaurant_id', $restaurant->id)
                ->when(isset($key), function ($q) use ($key) {
                    $q->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->orWhere('disbursement_id', 'like', "%{$value}%")
                                ->orWhere('status', 'like', "%{$value}%");
                        }
                    });
                })
                ->latest()->paginate(config('default_pagination'));

            return view('admin-views.vendor.view.disbursement', compact('restaurant', 'disbursements'));

        } elseif ($tab == 'pending-list') {
            $admin_commission = BusinessSetting::where('key', 'admin_commission')->first()?->value;

            return view('admin-views.vendor.pending_list_view', compact('restaurant', 'admin_commission'));
        }

        return view('admin-views.vendor.view.index', compact('restaurant', 'wallet'));
    }

    public function view_tab(Restaurant $restaurant)
    {
        Toastr::error(translate('messages.unknown_tab'));

        return back();
    }

    public function list(Request $request)
    {
        $key = explode(' ', $request['search']);
        $zone_id = $request->query('zone_id', 'all');
        $cuisine_id = $request->query('cuisine_id', 'all');
        $type = $request->query('type', 'all');
        $typ = $request->query('restaurant_model', '');
        $restaurants = Restaurant::when(is_numeric($zone_id), function ($query) use ($zone_id) {
            return $query->where('zone_id', $zone_id);
        })
            ->with(['zone', 'cuisine', 'vendor'])
            ->withSum('reviews', 'rating')
            ->withCount('reviews')
            ->whereHas('vendor', function ($q) {
                $q->where('status', 1);
            })
            ->when(isset($key), function ($q) use ($key) {
                $q->where(function ($query) use ($key) {
                    $query->orWhereHas('vendor', function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->orWhere('f_name', 'like', "%{$value}%")
                                ->orWhere('l_name', 'like', "%{$value}%")
                                ->orWhere('email', 'like', "%{$value}%")
                                ->orWhere('phone', 'like', "%{$value}%");
                        }
                    })
                        ->where(function ($q) use ($key) {
                            foreach ($key as $value) {
                                $q->orWhere('name', 'like', "%{$value}%")
                                    ->orWhere('email', 'like', "%{$value}%")
                                    ->orWhere('phone', 'like', "%{$value}%");
                            }
                        });
                });
            })
            ->cuisine($cuisine_id)
            ->type($type)->RestaurantModel($typ)->latest()->paginate(config('default_pagination'));
        $zone = is_numeric($zone_id) ? Zone::findOrFail($zone_id) : null;

        return view('admin-views.vendor.list', compact('restaurants', 'zone', 'type', 'typ', 'cuisine_id'));
    }

    public function pending(Request $request)
    {
        $key = explode(' ', (string) $request->input('search', ''));
        // Legacy single filters (backward compatibility)
        $zone_id_legacy = $request->query('zone_id', 'all');
        $type_legacy = $request->query('type', '');
        $typ_legacy = $request->query('restaurant_model', '');

        // New multi-select filters from offcanvas
        $zoneIds = (array) $request->query('zone_ids', []);
        if (is_numeric($zone_id_legacy) && count($zoneIds) === 0) {
            $zoneIds = [(int) $zone_id_legacy];
        }

        $types = (array) $request->query('types', []);
        $type = count($types) === 1 ? $types[0] : $type_legacy;

        $restaurantModels = (array) $request->query('restaurant_models', []);
        // map UI values to DB values
        $restaurantModelsMapped = array_values(array_filter(array_map(function ($m) {
            if ($m === 'subscribed') {
                return 'subscription';
            }

            return $m;
        }, $restaurantModels)));

        $restaurants = Restaurant::when(is_array($zoneIds) && count($zoneIds) > 0, function ($query) use ($zoneIds) {
            return $query->whereIn('zone_id', $zoneIds);
        })
            ->when(is_numeric($zone_id_legacy) && count($zoneIds) === 0, function ($query) use ($zone_id_legacy) {
                return $query->where('zone_id', $zone_id_legacy);
            })
            ->when(isset($key), function ($query) use ($key) {
                $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%")
                            ->orWhere('email', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%");
                    }
                });
            })
            ->with(['vendor', 'zone'])
            ->whereHas('vendor', function ($q) {
                $q->where('status', null);
            })
            ->type($type)
            ->when(count($restaurantModelsMapped) > 0, function ($q) use ($restaurantModelsMapped) {
                $q->whereIn('restaurant_model', $restaurantModelsMapped);
            }, function ($q) use ($typ_legacy) {
                $q->RestaurantModel($typ_legacy);
            })
            ->latest()
            ->paginate(config('default_pagination'));

        $zone = is_numeric($zone_id_legacy) ? Zone::find($zone_id_legacy) : null;
        $zonesList = Zone::orderBy('name')->get(['id', 'name']);

        return view('admin-views.vendor.pending_list', compact('restaurants', 'zone', 'type', 'zonesList'));
    }

    public function denied(Request $request)
    {
        $key = explode(' ', (string) $request->input('search', ''));
        // Legacy single filters (backward compatibility)
        $zone_id_legacy = $request->query('zone_id', 'all');
        $type_legacy = $request->query('type', '');
        $typ_legacy = $request->query('restaurant_model', '');

        // New multi-select filters from offcanvas
        $zoneIds = (array) $request->query('zone_ids', []);
        if (is_numeric($zone_id_legacy) && count($zoneIds) === 0) {
            $zoneIds = [(int) $zone_id_legacy];
        }

        $types = (array) $request->query('types', []);
        $type = count($types) === 1 ? $types[0] : $type_legacy;

        $restaurantModels = (array) $request->query('restaurant_models', []);
        // map UI values to DB values
        $restaurantModelsMapped = array_values(array_filter(array_map(function ($m) {
            if ($m === 'subscribed') {
                return 'subscription';
            }

            return $m;
        }, $restaurantModels)));

        $restaurants = Restaurant::when(is_array($zoneIds) && count($zoneIds) > 0, function ($query) use ($zoneIds) {
            return $query->whereIn('zone_id', $zoneIds);
        })
            ->when(is_numeric($zone_id_legacy) && count($zoneIds) === 0, function ($query) use ($zone_id_legacy) {
                return $query->where('zone_id', $zone_id_legacy);
            })
            ->when(isset($key), function ($query) use ($key) {
                $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%")
                            ->orWhere('email', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%");
                    }
                });
            })
            ->with(['vendor', 'zone'])
            ->whereHas('vendor', function ($q) {
                $q->where('status', 0);
            })
            ->type($type)
            ->when(count($restaurantModelsMapped) > 0, function ($q) use ($restaurantModelsMapped) {
                $q->whereIn('restaurant_model', $restaurantModelsMapped);
            }, function ($q) use ($typ_legacy) {
                $q->RestaurantModel($typ_legacy);
            })
            ->latest()
            ->paginate(config('default_pagination'));

        $zone = is_numeric($zone_id_legacy) ? Zone::find($zone_id_legacy) : null;
        $zonesList = Zone::orderBy('name')->get(['id', 'name']);

        return view('admin-views.vendor.denied', compact('restaurants', 'zone', 'type', 'zonesList'));
    }

    public function get_restaurants(Request $request)
    {

        $zone_ids = isset($request->zone_ids) ? (count($request->zone_ids) > 0 ? $request->zone_ids : []) : 0;
        $zone_id = $request->zone_id ?? null;
        $data = Restaurant::with('zone')
            ->whereHas('vendor', function ($q) {
                $q->where('status', 1);
            })
            ->when($zone_ids, function ($query) use ($zone_ids) {
                $query->whereIn('restaurants.zone_id', $zone_ids);
            })
            ->when($zone_id, function ($query) use ($zone_id) {
                $query->where('restaurants.zone_id', $zone_id);
            })
            ->where('restaurants.name', 'like', '%' . $request->q . '%')
            ->limit(8)->get()
            ->map(function ($restaurant) {
                return [
                    'id' => $restaurant->id,
                    'text' => $restaurant->name . ' (' . $restaurant->zone?->name . ')',
                ];
            });

        if (isset($request->all)) {
            $data[] = (object) ['id' => 'all', 'text' => 'All'];
        }

        return response()->json($data);
    }

    public function get_restaurant_ratings(Request $request)
    {

        $data = ['review' => 4.7, 'rating' => 2];

        if (!$request->restaurant_id) {
            return response()->json($data);
        }

        $restaurant = Restaurant::where('id', $request->restaurant_id)->first();
        if (!$restaurant) {
            return response()->json($data);
        }
        $review = (int) $restaurant->reviews_comments()->count();
        $reviewsInfo = $restaurant->reviews()
            ->selectRaw('avg(reviews.rating) as average_rating, count(reviews.id) as total_reviews, food.restaurant_id')
            ->groupBy('food.restaurant_id')
            ->first();

        $rating = (float) $reviewsInfo?->average_rating ?? 0;

        $data = ['review' => round($review, 1), 'rating' => round($rating, 1)];

        return response()->json($data);
    }

    public function status(Restaurant $restaurant, Request $request)
    {
        $restaurant->status = !$restaurant->status;
        $restaurant?->save();
        $vendor = $restaurant?->vendor;

        try {
            if ($restaurant->status  == 0) {

                 $vendor->auth_token = null;
                 $vendor?->save();

                $reataurant_push_notification_status = Helpers::getRestaurantNotificationStatusData($restaurant->id, 'restaurant_account_block');

                $message =Helpers::getPushNotificationMessage(status:'restaurant_account_block',userType: 'restaurant' , lang:$vendor->current_language_key, restaurantName:$restaurant->name);
                if ($reataurant_push_notification_status?->push_notification_status == 'active' && $message && isset($vendor->firebase_token)) {
                    $data= Helpers::makeDataForPushNotification(title:translate('suspended'), message:$message,orderId: '', type: 'block', orderStatus: '');
                    Helpers::send_push_notif_to_device($vendor->firebase_token, $data);
                    Helpers::insertDataOnNotificationTable($data , 'vendor', $vendor->id);
                }

                $mail_status = Helpers::get_mail_status('suspend_mail_status_restaurant');
                $notification_status = Helpers::getNotificationStatusData('restaurant', 'restaurant_account_block');
                $restaurant_notification_status = Helpers::getRestaurantNotificationStatusData($restaurant?->id, 'restaurant_account_block');

                if ($notification_status?->mail_status == 'active' && $restaurant_notification_status?->mail_status == 'active' && config('mail.status') && $mail_status == '1') {
                    Mail::to($restaurant?->vendor?->getRawOriginal('email'))->send(new \App\Mail\VendorStatus('suspended', $restaurant?->vendor?->f_name . ' ' . $restaurant?->vendor?->l_name));
                }
            } else {
                $reataurant_push_notification_status = Helpers::getRestaurantNotificationStatusData($restaurant?->id, 'restaurant_account_unblock');


                $message =Helpers::getPushNotificationMessage(status:'restaurant_account_unblock',userType: 'restaurant' , lang:$vendor->current_language_key, restaurantName:$restaurant->name);
                if ($reataurant_push_notification_status?->push_notification_status == 'active' && $message && isset($vendor->firebase_token)) {
                    $data= Helpers::makeDataForPushNotification(title:translate('Account_Activation'), message:$message,orderId: '', type: 'unblock', orderStatus: '');
                    Helpers::send_push_notif_to_device($vendor->firebase_token, $data);
                    Helpers::insertDataOnNotificationTable($data , 'vendor', $vendor->id);
                }

                $notification_status = Helpers::getNotificationStatusData('restaurant', 'restaurant_account_unblock');

                $restaurant_notification_status = Helpers::getRestaurantNotificationStatusData($restaurant?->id, 'restaurant_account_unblock');
                $mail_status = Helpers::get_mail_status('unsuspend_mail_status_restaurant');
                if ($notification_status?->mail_status == 'active' && $restaurant_notification_status?->mail_status == 'active' && config('mail.status') && $mail_status == '1') {
                    Mail::to($restaurant?->vendor?->getRawOriginal('email'))->send(new \App\Mail\VendorStatus('unsuspended', $restaurant?->vendor?->f_name . ' ' . $restaurant?->vendor?->l_name));
                }
            }
        } catch (\Exception $ex) {
            info($ex->getMessage());
        }

        Toastr::success(translate('messages.restaurant_status_updated'));

        return back();
    }

    public function restaurant_status(Restaurant $restaurant, Request $request)
    {
        if ($request->menu == 'schedule_order' && !Helpers::schedule_order()) {
            Toastr::warning(translate('messages.schedule_order_disabled_warning'));

            return back();
        }
        $home_delivery = BusinessSetting::where('key', 'home_delivery')->first()?->value ?? null;
        if ($request->menu == 'delivery' && !$home_delivery) {
            Toastr::warning(translate('messages.Home_delivery_is_disabled_by_admin'));

            return back();
        }
        $take_away = BusinessSetting::where('key', 'take_away')->first()?->value ?? null;
        if ($request->menu == 'take_away' && !$take_away) {
            Toastr::warning(translate('messages.Take_away_is_disabled_by_admin'));

            return back();
        }

        $instant_order = BusinessSetting::where('key', 'instant_order')->first()?->value ?? null;
        if ($request->menu == 'instant_order' && !$instant_order && $request->status == 1) {
            Toastr::warning(translate('messages.instant_order_is_disabled_by_admin'));

            return back();
        }
        $dine_in_order_option = BusinessSetting::where('key', 'dine_in_order_option')->first()?->value ?? null;
        if ($request->menu == 'dine_in' && !$dine_in_order_option && $request->status == 1) {
            Toastr::warning(translate('messages.dine_in_order_option_is_disabled_by_admin'));

            return back();
        }

        if ((($request->menu == 'delivery' && $restaurant->take_away == 0) || ($request->menu == 'take_away' && $restaurant->delivery == 0)) && $request->status == 0) {
            Toastr::warning(translate('messages.can_not_disable_both_take_away_and_delivery'));

            return back();
        }

        if ((($request->menu == 'veg' && $restaurant->non_veg == 0) || ($request->menu == 'non_veg' && $restaurant->veg == 0)) && $request->status == 0) {
            Toastr::warning(translate('messages.veg_non_veg_disable_warning'));

            return back();
        }
        if ($request->menu == 'self_delivery_system' && $request->status == '0') {
            $restaurant['free_delivery'] = 0;
            $restaurant?->coupon()?->where('created_by', 'vendor')->where('coupon_type', 'free_delivery')?->delete();
        }

        if ((($request->menu == 'instant_order' && $restaurant->schedule_order == 0) || (isset($restaurant->restaurant_config) && ($request->menu == 'schedule_order' && $restaurant?->restaurant_config?->instant_order == 0))) && $request->status == 0 && $instant_order) {
            Toastr::warning(translate('messages.can_not_disable_both_instant_order_and_schedule_order'));

            return back();
        }

        if (in_array($request->menu, ['instant_order', 'customer_date_order_sratus', 'halal_tag_status', 'dine_in'])) {
            $conf = RestaurantConfig::firstOrNew(
                ['restaurant_id' => $restaurant->id]
            );
            $conf[$request->menu] = $request->status;
            $conf->save();
            Toastr::success(translate('messages.Restaurant_settings_updated!'));

            return back();
        }

        $restaurant[$request->menu] = $request->status;
        $restaurant?->save();
        Toastr::success(translate('messages.Restaurant_settings_updated'));

        return back();
    }

    public function discountSetup(Restaurant $restaurant, Request $request)
    {
        $message = translate('messages.discount');
        $message .= $restaurant->discount ? translate('messages.updated_successfully') : translate('messages.added_successfully');
        $restaurant?->discount()?->updateOrinsert(
            [
                'restaurant_id' => $restaurant->id,
            ],
            [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'min_purchase' => $request->min_purchase != null ? $request->min_purchase : 0,
                'max_discount' => $request->max_discount != null ? $request->max_discount : 0,
                'discount' => $request->discount_type == 'amount' ? $request->discount : $request['discount'],
                'discount_type' => 'percent',
            ]
        );
        Toastr::success($message);
        return back();

    }

    public function updateRestaurantSettings(Restaurant $restaurant, Request $request)
    {

        if ($request?->tab == 'business_plan') {
            $restaurant->comission = $request->comission_status ? $request->comission : null;
            $restaurant->save();
            Toastr::success(translate('messages.Commission_updated'));

            return back();
        }

        $request->validate([
            'minimum_order' => 'required',
            // 'comission' => 'required',
            'minimum_delivery_time' => 'required',
            'maximum_delivery_time' => 'required|gt:minimum_delivery_time',
            'delivery_time_type' => 'required',

        ]);

        if ($request->delivery_time_type == 'min') {
            $minimum_delivery_time = (int) $request->input('minimum_delivery_time');
            if ($minimum_delivery_time < 10) {
                Toastr::error(translate('messages.restaurant_minimum_delivery_time_should_be_more_than_10_min'));

                return back();
            }
        }
        if ($request->schedule_advance_dine_in_booking_duration_time_format == 'min' && $request->schedule_advance_dine_in_booking_duration > 60) {
            Toastr::error(translate('messages.Dine_in_dine_in_booking_duration_time_must_be_in_60_minute'));

            return back();
        } elseif ($request->schedule_advance_dine_in_booking_duration_time_format == 'hour' && $request->schedule_advance_dine_in_booking_duration > 24) {
            Toastr::error(translate('messages.Dine_in_dine_in_booking_duration_time_must_be_in_24_hour'));

            return back();
        } elseif ($request->schedule_advance_dine_in_booking_duration_time_format == 'day' && $request->schedule_advance_dine_in_booking_duration > 365) {
            Toastr::error(translate('messages.Dine_in_dine_in_booking_duration_time_must_be_in_356_days'));

            return back();
        }

        $restaurant->minimum_order = $request->minimum_order;
        $restaurant->opening_time = $request->opening_time;
        $restaurant->closeing_time = $request->closeing_time;
        $restaurant->delivery_time = $request->minimum_delivery_time . '-' . $request->maximum_delivery_time . '-' . $request->delivery_time_type;
        if ($request->menu == 'veg') {
            $restaurant->veg = 1;
            $restaurant->non_veg = 0;
        } elseif ($request->menu == 'non-veg') {
            $restaurant->veg = 0;
            $restaurant->non_veg = 1;
        } elseif ($request->menu == 'both') {
            $restaurant->veg = 1;
            $restaurant->non_veg = 1;
        }
        $restaurant->save();

        $conf = RestaurantConfig::firstOrNew(
            ['restaurant_id' => $restaurant->id]
        );
        $conf->customer_order_date = $request->customer_order_date ?? 0;
        $conf->schedule_advance_dine_in_booking_duration = $request->schedule_advance_dine_in_booking_duration ?? 0;
        $conf->schedule_advance_dine_in_booking_duration_time_format = $request->schedule_advance_dine_in_booking_duration_time_format ?? 'min';
        $conf->can_edit_order = $request->can_edit_order ?? 0;
        $conf->save();

        Toastr::success(translate('messages.Restaurant_settings_updated'));

        return back();
    }

    public function update_application($id, $status)
    {
        $restaurant = Restaurant::findOrFail($id);
        $restaurant->vendor->status = $status;
        $restaurant?->vendor?->save();
        if ($status) {
            $restaurant->status = 1;
        }
        $add_days = 1;
        if ($restaurant?->restaurant_sub_update_application) {
            if ($restaurant?->restaurant_sub_update_application && ($restaurant?->restaurant_sub_update_application->is_trial == 1 || $restaurant?->restaurant_sub_trans?->payment_method == 'free_trial')) {
                $add_days = BusinessSetting::where(['key' => 'subscription_free_trial_days'])->first()?->value ?? 1;
            } elseif ($restaurant?->restaurant_sub_update_application && $restaurant?->restaurant_sub_update_application->is_trial == 0) {
                $add_days = $restaurant?->restaurant_sub_update_application->validity;
            }
            $restaurant?->restaurant_sub_update_application->update([
                'expiry_date' => Carbon::now()->addDays((int) $add_days)->format('Y-m-d'),
                'status' => 1,
            ]);
            $restaurant->restaurant_model = 'subscription';
        }
        $restaurant?->save();
        try {
            if ($status == 1) {
                $notification_status = Helpers::getNotificationStatusData('restaurant', 'restaurant_registration_approval');

                $mail_status = Helpers::get_mail_status('approve_mail_status_restaurant');
                if ($notification_status?->mail_status == 'active' && config('mail.status') && $mail_status == '1') {
                    Mail::to($restaurant?->vendor?->getRawOriginal('email'))->send(new \App\Mail\VendorSelfRegistration('approved', $restaurant?->vendor?->f_name . ' ' . $restaurant?->vendor?->l_name));
                }
            } else {
                $notification_status = null;
                $notification_status = Helpers::getNotificationStatusData('restaurant', 'restaurant_registration_deny');
                $mail_status = Helpers::get_mail_status('deny_mail_status_restaurant');
                if ($notification_status?->mail_status == 'active' && config('mail.status') && $mail_status == '1') {
                    Mail::to($restaurant?->vendor?->getRawOriginal('email'))->send(new \App\Mail\VendorSelfRegistration('denied', $restaurant?->vendor?->f_name . ' ' . $restaurant?->vendor?->l_name));
                }
            }
        } catch (\Exception $ex) {
            info($ex->getMessage());
        }
        Toastr::success(translate('messages.application_status_updated_successfully'));

        return back();
    }

    public function cleardiscount(Restaurant $restaurant)
    {
        $restaurant?->discount?->delete();
        Toastr::success(translate('messages.restaurant_discount_cleared'));

        return back();
    }

    public function withdraw(Request $request)
    {
        $key = isset($request['search']) ? explode(' ', $request['search']) : [];

        $all = session()->has('withdraw_status_filter') && session('withdraw_status_filter') == 'all' ? 1 : 0;
        $active = session()->has('withdraw_status_filter') && session('withdraw_status_filter') == 'approved' ? 1 : 0;
        $denied = session()->has('withdraw_status_filter') && session('withdraw_status_filter') == 'denied' ? 1 : 0;
        $pending = session()->has('withdraw_status_filter') && session('withdraw_status_filter') == 'pending' ? 1 : 0;

        $withdraw_req = WithdrawRequest::with(['vendor', 'vendor.restaurants'])
            ->when($all, function ($query) {
                return $query;
            })
            ->when($active, function ($query) {
                return $query->where('approved', 1);
            })
            ->when($denied, function ($query) {
                return $query->where('approved', 2);
            })
            ->when($pending, function ($query) {
                return $query->where('approved', 0);
            })
            ->when(isset($key), function ($query) use ($key) {
                return $query->whereHas('vendor', function ($query) use ($key) {
                    $query->whereHas('restaurants', function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->where('name', 'like', "%{$value}%");
                        }
                    });
                });
            })
            ->latest()
            ->paginate(config('default_pagination'));

        return view('admin-views.wallet.withdraw', compact('withdraw_req'));
    }

    public function withdraw_view($withdraw_id, $seller_id)
    {
        $wr = WithdrawRequest::with(['vendor', 'method:id,method_name'])->where(['id' => $withdraw_id])->first();

        return view('admin-views.wallet.withdraw-view', compact('wr'));
    }

    public function status_filter(Request $request)
    {
        session()->put('withdraw_status_filter', $request['withdraw_status_filter']);

        return response()->json(session('withdraw_status_filter'));
    }

    public function withdrawStatus(Request $request, $id)
    {
        $withdraw = WithdrawRequest::findOrFail($id);
        $withdraw->approved = $request->approved;
        $withdraw->transaction_note = $request['note'];
        $wallet = RestaurantWallet::where('vendor_id', $withdraw->vendor_id)->first();
        $vendor = $withdraw->vendor;
        if ($wallet->total_earning < ($wallet->total_withdrawn + $wallet->pending_withdraw)) {
            Toastr::error(translate('messages.Blalnce_mismatched_total_earning_is_too_low'));

            return redirect()->route('admin.restaurant.withdraw_list');
        }

        if ($request->approved == 1) {
            $wallet->increment('total_withdrawn', $withdraw->amount);
            $wallet->decrement('pending_withdraw', $withdraw->amount);
            $withdraw->save();
            try {

                $reataurant_push_notification_status = Helpers::getRestaurantNotificationStatusData($vendor?->restaurants[0]?->id, 'restaurant_withdraw_approve');
                $message =Helpers::getPushNotificationMessage(status:'restaurant_withdraw_approve',userType: 'restaurant' , lang:$vendor->current_language_key, restaurantName:$vendor?->restaurants[0]?->name);
                if ($reataurant_push_notification_status?->push_notification_status == 'active' && $message && isset($vendor->firebase_token)) {
                    $data= Helpers::makeDataForPushNotification(title:translate('Withdraw_approved'), message:$message,orderId: '', type: 'withdraw', orderStatus: '');
                    Helpers::send_push_notif_to_device($vendor->firebase_token, $data);
                    Helpers::insertDataOnNotificationTable($data , 'vendor', $vendor->id);
                }

                $notification_status = Helpers::getNotificationStatusData('restaurant', 'restaurant_withdraw_approve');
                $restaurant_notification_status = Helpers::getRestaurantNotificationStatusData($withdraw->vendor?->restaurants[0]?->id, 'restaurant_withdraw_approve');

                if ($notification_status?->mail_status == 'active' && $restaurant_notification_status?->mail_status == 'active' && config('mail.status') && Helpers::get_mail_status('withdraw_approve_mail_status_restaurant') == '1') {
                    Mail::to($withdraw->vendor?->getRawOriginal('email'))->send(new \App\Mail\WithdrawRequestMail('approved', $withdraw));
                }
            } catch (\Exception $e) {
                info($e->getMessage());
            }
            Toastr::success(translate('messages.seller_payment_approved'));

            return redirect()->route('admin.restaurant.withdraw_list');
        } elseif ($request->approved == 2) {
            try {

                $reataurant_push_notification_status = Helpers::getRestaurantNotificationStatusData($withdraw->vendor?->restaurants[0]?->id, 'restaurant_withdraw_rejaction');
                $message =Helpers::getPushNotificationMessage(status:'restaurant_withdraw_rejaction',userType: 'restaurant' , lang:$vendor->current_language_key, restaurantName:$vendor?->restaurants[0]?->name);
                if ($reataurant_push_notification_status?->push_notification_status == 'active' && $message && isset($vendor->firebase_token)) {
                    $data= Helpers::makeDataForPushNotification(title:translate('Withdraw_rejected'), message:$message,orderId: '', type: 'withdraw', orderStatus: '');
                    Helpers::send_push_notif_to_device($vendor->firebase_token, $data);
                    Helpers::insertDataOnNotificationTable($data , 'vendor', $vendor->id);
                }

                $notification_status = Helpers::getNotificationStatusData('restaurant', 'restaurant_withdraw_rejaction');
                $restaurant_notification_status = Helpers::getRestaurantNotificationStatusData($withdraw->vendor?->restaurants[0]?->id, 'restaurant_withdraw_rejaction');

                if ($notification_status?->mail_status == 'active' && $restaurant_notification_status?->mail_status == 'active' && config('mail.status') && Helpers::get_mail_status('withdraw_deny_mail_status_restaurant') == '1') {
                    Mail::to($withdraw->vendor?->getRawOriginal('email'))->send(new \App\Mail\WithdrawRequestMail('denied', $withdraw));
                }
            } catch (\Exception $e) {
                info($e->getMessage());
            }
            $wallet->decrement('pending_withdraw', $withdraw->amount);
            $withdraw->save();
            Toastr::info(translate('messages.seller_payment_denied'));

            return redirect()->route('admin.restaurant.withdraw_list');
        } else {
            Toastr::error(translate('messages.not_found'));

            return back();
        }
    }

    public function get_addons(Request $request)
    {
        $cat = AddOn::withoutGlobalScope(RestaurantScope::class)->where(['restaurant_id' => $request->restaurant_id])->active()->get();
        $res = '';
        foreach ($cat as $row) {
            $res .= '<option value="' . $row->id . '"';
            if (count($request->data)) {
                $res .= in_array($row->id, $request->data) ? 'selected' : '';
            }
            $res .= '>' . $row->name . '</option>';
        }

        return response()->json([
            'options' => $res,
        ]);
    }

    public function get_restaurant_data(Restaurant $restaurant)
    {
        return response()->json($restaurant);
    }

    public function restaurant_filter($id)
    {
        if ($id == 'all') {
            if (session()->has('restaurant_filter')) {
                session()->forget('restaurant_filter');
            }
        } else {
            session()->put('restaurant_filter', Restaurant::where('id', $id)->first(['id', 'name']));
        }

        return back();
    }

    public function get_account_data(Restaurant $restaurant)
    {
        $wallet = $restaurant?->vendor?->wallet;
        $cash_in_hand = 0;
        $balance = 0;

        if ($wallet) {
            $cash_in_hand = $wallet->collected_cash;
            $balance = $wallet->balance;
            // $balance = $wallet->total_earning - $wallet->total_withdrawn - $wallet->pending_withdraw - $wallet->collected_cash;
        }

        return response()->json(['cash_in_hand' => $cash_in_hand, 'earning_balance' => $balance], 200);
    }

    public function bulk_import_index()
    {
        return view('admin-views.vendor.bulk-import');
    }

    public function bulk_import_data(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'upload_excel' => 'required|max:2048',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        try {
            $collections = (new FastExcel)->import($request->file('upload_excel'));
        } catch (\Exception $exception) {
            info(["line___{$exception->getLine()}", $exception->getMessage()]);
            Toastr::error(translate('messages.you_have_uploaded_a_wrong_format_file'));

            return back();
        }
        $duplicate_phones = $collections->duplicates('phone');
        $duplicate_emails = $collections->duplicates('email');

        if ($duplicate_emails->isNotEmpty()) {
            Toastr::error(translate('messages.duplicate_data_on_column', ['field' => translate('messages.email')]));

            return back();
        }

        if ($duplicate_phones->isNotEmpty()) {
            Toastr::error(translate('messages.duplicate_data_on_column', ['field' => translate('messages.phone')]));

            return back();
        }
        $vendors = [];
        $restaurants = [];
        if ($request->button === 'import') {

            $email = $collections->pluck('email')->toArray();
            $phone = $collections->pluck('phone')->toArray();

            if (
                Restaurant::whereIn('email', $email)->orWhereIn('phone', $phone)->exists()
            ) {
                Toastr::error(translate('messages.duplicate_email_or_phone_exists_at_the_database'));

                return back();
            }

            $vendor = Vendor::orderBy('id', 'desc')->first('id');
            $vendor_id = $vendor ? $vendor->id : 0;
            try {
                foreach ($collections as $key => $collection) {
                    if (
                        !isset($collection['id']) || $collection['id'] === '' || $collection['ownerFirstName'] === '' || $collection['restaurantName'] === '' || $collection['phone'] === '' || $collection['Comission'] === ''
                        || $collection['email'] === '' || $collection['latitude'] === '' || $collection['longitude'] === ''
                        || $collection['zone_id'] === '' || $collection['DeliveryTime'] === '' || $collection['RestaurantModel'] === ''
                    ) {
                        Toastr::error(translate('messages.please_fill_all_required_fields'));

                        return back();
                    }
                    if (isset($collection['DeliveryTime']) && explode('-', (string) $collection['DeliveryTime'])[0] > explode('-', (string) $collection['DeliveryTime'])[1]) {
                        Toastr::error(translate('messages.max_delivery_time_must_be_greater_than_min_delivery_time_on_id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['Comission']) && ($collection['Comission'] < 0 || $collection['Comission'] > 100)) {
                        Toastr::error(translate('messages.Comission_must_be_in_0_to_100_on_id') . ' ' . $collection['id']);

                        return back();
                    }

                    if (isset($collection['latitude']) && ($collection['latitude'] < -90 || $collection['latitude'] > 90)) {
                        Toastr::error(translate('messages.latitude_must_be_in_-90_to_90_on_Id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['longitude']) && ($collection['longitude'] < -180 || $collection['longitude'] > 180)) {
                        Toastr::error(translate('messages.longitude_must_be_in_-180_to_180_on_Id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['MinimumDeliveryFee']) && ($collection['MinimumDeliveryFee'] < 0)) {
                        Toastr::error(translate('messages.Enter_valid_Minimum_Delivery_Fee_on_Id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['MinimumOrderAmount']) && ($collection['MinimumOrderAmount'] < 0)) {
                        Toastr::error(translate('messages.Enter_valid_Minimum_Order_Amount_on_Id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['PerKmDeliveryFee']) && ($collection['PerKmDeliveryFee'] < 0)) {
                        Toastr::error(translate('messages.Enter_valid_Per_Km_Delivery_Fee_on_Id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['MaximumDeliveryFee']) && ($collection['MaximumDeliveryFee'] < 0)) {
                        Toastr::error(translate('messages.Enter_valid_Maximum_Delivery_Fee_on_Id') . ' ' . $collection['id']);

                        return back();
                    }

                    array_push($vendors, [
                        'id' => $vendor_id + $key + 1,
                        'f_name' => $collection['ownerFirstName'],
                        'l_name' => $collection['ownerLastName'],
                        'password' => bcrypt(12345678),
                        'phone' => $collection['phone'],
                        'email' => $collection['email'],
                        'status' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    array_push($restaurants, [
                        'name' => $collection['restaurantName'],
                        'logo' => $collection['logo'] ?? null,
                        'cover_photo' => $collection['CoverPhoto'] ?? null,
                        'phone' => $collection['phone'],
                        'email' => $collection['email'],
                        'latitude' => $collection['latitude'],
                        'longitude' => $collection['longitude'],
                        'vendor_id' => $vendor_id + $key + 1,
                        'zone_id' => $collection['zone_id'],
                        'address' => $collection['Address'] ?? null,

                        'minimum_order' => $collection['MinimumOrderAmount'] ?? 0,
                        'delivery_time' => $collection['DeliveryTime'] ?? '15-30',
                        'comission' => $collection['Comission'] ?? 'comission',
                        'minimum_shipping_charge' => $collection['MinimumDeliveryFee'] ?? 0,
                        'per_km_shipping_charge' => $collection['PerKmDeliveryFee'] ?? 0,
                        'maximum_shipping_charge' => $collection['MaximumDeliveryFee'] ?? 0,
                        'restaurant_model' => $collection['RestaurantModel'] == 'subscription' ? 'unsubscribed' : 'commission',
                        'schedule_order' => $collection['ScheduleOrder'] == 'yes' ? 1 : 0,
                        'take_away' => $collection['TakeAway'] == 'yes' ? 1 : 0,
                        'free_delivery' => $collection['FreeDelivery'] == 'yes' ? 1 : 0,
                        'veg' => $collection['Veg'] == 'yes' ? 1 : 0,
                        'non_veg' => $collection['NonVeg'] == 'yes' ? 1 : 0,
                        'order_subscription_active' => $collection['OrderSubscription'] == 'yes' ? 1 : 0,

                        'delivery' => $collection['Delivery'] == 'yes' ? 1 : 0,
                        'status' => $collection['Status'] == 'active' ? 1 : 0,
                        'food_section' => $collection['FoodSection'] == 'active' ? 1 : 0,
                        'reviews_section' => $collection['ReviewsSection'] == 'active' ? 1 : 0,
                        'pos_system' => $collection['PosSystem'] == 'active' ? 1 : 0,
                        'self_delivery_system' => $collection['SelfDeliverySystem'] == 'active' ? 1 : 0,
                        'active' => $collection['RestaurantOpen'] == 'yes' ? 1 : 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $restaurant_ids[] = $vendor_id + $key + 1;
                $data = array_map(function ($id) {
                    return array_map(function ($item) use ($id) {
                        return ['restaurant_id' => $id, 'day' => $item, 'opening_time' => '00:00:00', 'closing_time' => '23:59:59'];
                    }, [0, 1, 2, 3, 4, 5, 6]);
                }, $restaurant_ids);

            } catch (\Exception $e) {
                info(["line___{$e->getLine()}", $e->getMessage()]);
                Toastr::error(translate('messages.failed_to_import_data'));

                return back();
            }
            try {
                $chunkSize = 100;
                $chunk_restaurants = array_chunk($restaurants, $chunkSize);
                $chunk_vendors = array_chunk($vendors, $chunkSize);

                DB::beginTransaction();
                foreach ($chunk_restaurants as $key => $chunk_restaurant) {
                    DB::table('vendors')->insert($chunk_vendors[$key]);
                    //                    DB::table('restaurants')->insert($chunk_restaurant);
                    foreach ($chunk_restaurant as $restaurant) {
                        $insertedId = DB::table('restaurants')->insertGetId($restaurant);
                        Helpers::updateStorageTable(get_class(new Restaurant), $insertedId, $restaurant['logo']);
                        Helpers::updateStorageTable(get_class(new Restaurant), $insertedId, $restaurant['cover_photo']);
                    }
                }

                DB::table('restaurant_schedule')->insert(array_merge(...$data));
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                info(["line___{$e->getLine()}", $e->getMessage()]);
                Toastr::error(translate('messages.failed_to_import_data'));

                return back();
            }

            Toastr::success(translate('messages.restaurant_imported_successfully', ['count' => count($restaurants)]));

            return back();
        }

        if ($request->button === 'update') {

            $email = $collections->pluck('email')->toArray();
            $phone = $collections->pluck('phone')->toArray();
            if (
                Restaurant::whereIn('email', $email)->orWhereIn('phone', $phone)->doesntExist()
            ) {
                Toastr::error(translate('messages.email_or_phone_doesnt_exist_at_the_database'));

                return back();
            }
            try {
                foreach ($collections as $key => $collection) {
                    if (
                        !isset($collection['id']) || $collection['id'] === '' || $collection['ownerFirstName'] === '' || $collection['restaurantName'] === '' || $collection['phone'] === '' || $collection['Comission'] === ''
                        || $collection['email'] === '' || $collection['latitude'] === '' || $collection['longitude'] === ''
                        || $collection['zone_id'] === '' || $collection['DeliveryTime'] === '' || $collection['RestaurantModel'] === ''
                    ) {
                        Toastr::error(translate('messages.please_fill_all_required_fields'));

                        return back();
                    }
                    if (isset($collection['DeliveryTime']) && explode('-', (string) $collection['DeliveryTime'])[0] > explode('-', (string) $collection['DeliveryTime'])[1]) {
                        Toastr::error(translate('messages.max_delivery_time_must_be_greater_than_min_delivery_time_on_id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['Comission']) && ($collection['Comission'] < 0 || $collection['Comission'] > 100)) {
                        Toastr::error(translate('messages.Comission_must_be_in_0_to_100_on_id') . ' ' . $collection['id']);

                        return back();
                    }

                    if (isset($collection['latitude']) && ($collection['latitude'] < -90 || $collection['latitude'] > 90)) {
                        Toastr::error(translate('messages.latitude_must_be_in_-90_to_90_on_id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['longitude']) && ($collection['longitude'] < -180 || $collection['longitude'] > 180)) {
                        Toastr::error(translate('messages.longitude_must_be_in_-180_to_180_on_id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['MinimumDeliveryFee']) && ($collection['MinimumDeliveryFee'] < 0)) {
                        Toastr::error(translate('messages.Enter_valid_Minimum_Delivery_Fee_on_id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['MinimumOrderAmount']) && ($collection['MinimumOrderAmount'] < 0)) {
                        Toastr::error(translate('messages.Enter_valid_Minimum_Order_Amount_on_id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['PerKmDeliveryFee']) && ($collection['PerKmDeliveryFee'] < 0)) {
                        Toastr::error(translate('messages.Enter_valid_Per_Km_Delivery_Fee_on_id') . ' ' . $collection['id']);

                        return back();
                    }
                    if (isset($collection['MaximumDeliveryFee']) && ($collection['MaximumDeliveryFee'] < 0)) {
                        Toastr::error(translate('messages.Enter_valid_Maximum_Delivery_Fee_on_id') . ' ' . $collection['id']);

                        return back();
                    }

                    array_push($vendors, [
                        'id' => $collection['ownerID'],
                        'f_name' => $collection['ownerFirstName'],
                        'l_name' => $collection['ownerLastName'],
                        'phone' => $collection['phone'],
                        'email' => $collection['email'],
                        'status' => 1,
                        'password' => bcrypt(12345678),
                        'updated_at' => now(),
                    ]);
                    array_push($restaurants, [
                        'id' => $collection['id'],
                        'name' => $collection['restaurantName'],
                        'logo' => $collection['logo'] ?? null,
                        'cover_photo' => $collection['CoverPhoto'] ?? null,
                        'phone' => $collection['phone'],
                        'email' => $collection['email'],
                        'latitude' => $collection['latitude'],
                        'longitude' => $collection['longitude'],
                        'vendor_id' => $collection['ownerID'],
                        'zone_id' => $collection['zone_id'],
                        'address' => $collection['Address'] ?? null,

                        'minimum_order' => $collection['MinimumOrderAmount'] ?? 0,
                        'delivery_time' => $collection['DeliveryTime'] ?? '15-30',
                        'comission' => $collection['Comission'] ?? 'comission',
                        'minimum_shipping_charge' => $collection['MinimumDeliveryFee'] ?? 0,
                        'per_km_shipping_charge' => $collection['PerKmDeliveryFee'] ?? 0,
                        'maximum_shipping_charge' => $collection['MaximumDeliveryFee'] ?? 0,
                        'restaurant_model' => $collection['RestaurantModel'] == 'subscription' ? 'unsubscribed' : 'commission',
                        'order_subscription_active' => $collection['OrderSubscription'] == 'yes' ? 1 : 0,
                        'schedule_order' => $collection['ScheduleOrder'] == 'yes' ? 1 : 0,
                        'take_away' => $collection['TakeAway'] == 'yes' ? 1 : 0,
                        'free_delivery' => $collection['FreeDelivery'] == 'yes' ? 1 : 0,
                        'veg' => $collection['Veg'] == 'yes' ? 1 : 0,
                        'non_veg' => $collection['NonVeg'] == 'yes' ? 1 : 0,
                        'delivery' => $collection['Delivery'] == 'yes' ? 1 : 0,
                        'status' => $collection['Status'] == 'active' ? 1 : 0,
                        'food_section' => $collection['FoodSection'] == 'active' ? 1 : 0,
                        'reviews_section' => $collection['ReviewsSection'] == 'active' ? 1 : 0,
                        'pos_system' => $collection['PosSystem'] == 'active' ? 1 : 0,
                        'self_delivery_system' => $collection['SelfDeliverySystem'] == 'active' ? 1 : 0,
                        'active' => $collection['RestaurantOpen'] == 'yes' ? 1 : 0,
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Exception $e) {
                info(["line___{$e->getLine()}", $e->getMessage()]);
                Toastr::error(translate('messages.failed_to_update_data'));

                return back();
            }

            try {

                $chunkSize = 100;
                $chunk_restaurants = array_chunk($restaurants, $chunkSize);
                $chunk_vendors = array_chunk($vendors, $chunkSize);
                DB::beginTransaction();
                foreach ($chunk_restaurants as $key => $chunk_restaurant) {
                    DB::table('vendors')->upsert($chunk_vendors[$key], ['id', 'email', 'phone', 'password'], ['f_name', 'l_name']);
                    //                    DB::table('restaurants')->upsert($chunk_restaurant,['id','email','phone','vendor_id',],['name','logo','cover_photo','latitude','longitude','address','zone_id','minimum_order','comission','tax','delivery_time','minimum_shipping_charge','per_km_shipping_charge','maximum_shipping_charge','schedule_order','status','self_delivery_system','veg','non_veg','free_delivery','take_away','delivery','reviews_section','pos_system','active','restaurant_model','food_section','order_subscription_active']);
                    foreach ($chunk_restaurant as $restaurant) {
                        if (isset($restaurant['id']) && DB::table('food')->where('id', $restaurant['id'])->exists()) {
                            DB::table('restaurants')->where('id', $restaurant['id'])->update($restaurant);
                            Helpers::updateStorageTable(get_class(new Restaurant), $restaurant['id'], $restaurant['logo']);
                            Helpers::updateStorageTable(get_class(new Restaurant), $restaurant['id'], $restaurant['cover_photo']);
                        } else {
                            $insertedId = DB::table('restaurants')->insertGetId($restaurant);
                            Helpers::updateStorageTable(get_class(new Restaurant), $insertedId, $restaurant['logo']);
                            Helpers::updateStorageTable(get_class(new Restaurant), $insertedId, $restaurant['cover_photo']);
                        }
                    }
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                info(["line___{$e->getLine()}", $e->getMessage()]);
                Toastr::error(translate('messages.failed_to_update_data'));

                return back();
            }
            Toastr::success(translate('messages.restaurant_update_successfully', ['count' => count($restaurants)]));

            return back();
        }

    }

    public function bulk_export_index()
    {
        return view('admin-views.vendor.bulk-export');
    }

    public function bulk_export_data(Request $request)
    {
        $request->validate([
            'type' => 'required',
            'start_id' => 'required_if:type,id_wise',
            'end_id' => 'required_if:type,id_wise',
            'from_date' => 'required_if:type,date_wise',
            'to_date' => 'required_if:type,date_wise',
        ]);
        $query = Vendor::with('restaurants')->has('restaurants')
            ->when($request['type'] == 'date_wise', function ($query) use ($request) {
                $query->whereBetween('created_at', [$request['from_date'] . ' 00:00:00', $request['to_date'] . ' 23:59:59']);
            })
            ->when($request['type'] == 'id_wise', function ($query) use ($request) {
                $query->whereBetween('id', [$request['start_id'], $request['end_id']]);
            })
            ->when($request->type == 'all', function ($q) {
                $q->where('status', 1);
            });

            if (!$query->exists()) {
                if($request['type'] == 'date_wise') {
                    Toastr::error(translate('messages.there_are_no_restaurants_in_between_these_dates'));
                } elseif($request['type'] == 'id_wise') {
                    Toastr::error(translate('Invalid ID. No file generated'));
                } else
                {
                    Toastr::error(translate('messages.no_data_to_export'));
                }
                return back();
            }

            $vendors = $query->get();

        return (new FastExcel(RestaurantLogic::format_export_restaurants(Helpers::Export_generator($vendors))))->download('Restaurants.xlsx');
    }

    public function add_schedule(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'start_time'=>'required|date_format:H:i',
            'end_time'=>'required|date_format:H:i|after:start_time',
            'day'=>'required',
        ],[
            'end_time.after'=>translate('messages.End time must be after the start time')
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }
        $restaurant = Restaurant::find($request->restaurant_id);

        $temp = RestaurantSchedule::where('day', $request->day)->where('restaurant_id',$restaurant->id)
        ->where(function($q)use($request){
            return $q->where(function($query)use($request){
                return $query->where('opening_time', '<=' , $request->start_time)->where('closing_time', '>=', $request->start_time);
            })->orWhere(function($query)use($request){
                return $query->where('opening_time', '<=' , $request->end_time)->where('closing_time', '>=', $request->end_time);
            });
        })
        ->first();

        if(isset($temp))
        {
            return response()->json(['errors' => [
                ['code'=>'time', 'message'=>translate('messages.Time overlap detected.')]
            ]]);
        }


        $restaurant_schedule = RestaurantSchedule::insert(['restaurant_id'=>$restaurant->id,'day'=>$request->day,'opening_time'=>$request->start_time,'closing_time'=>$request->end_time]);

        $restaurantConfig = RestaurantConfig::where('restaurant_id', $restaurant->id)->first();

        if ($restaurantConfig->same_time_for_every_day) {

            $restaurantId = $restaurantConfig->restaurant_id;
            $dayOneSchedules = RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', 1)
                ->get();

            if ($dayOneSchedules->isEmpty()) {
                RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->delete();
                return response()->json();
            }
            RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', '!=', 1)
                ->delete();

            $insertData = [];

            foreach ($dayOneSchedules as $schedule) {
                foreach (range(0, 6) as $day) {
                    if ($day == 1) {
                        continue;
                    }

                    $insertData[] = [
                        'restaurant_id' => $restaurantId,
                        'day'           => $day,
                        'opening_time'  => $schedule->opening_time,
                        'closing_time'  => $schedule->closing_time,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
            }

            if (!empty($insertData)) {
                RestaurantSchedule::insert($insertData);
            }
        }

        return response()->json([
            'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
        ]);
    }

    public function remove_schedule(Request $request, $restaurant_schedule)
    {
        $restaurant = Restaurant::findOrFail($request->id);
        $schedule = RestaurantSchedule::where('restaurant_id', $restaurant->id)->find($restaurant_schedule);
        if(!$schedule)
        {
            return response()->json([],404);
        }
        $schedule->delete();

        $restaurantConfig = RestaurantConfig::where('restaurant_id', $restaurant->id)->first();

        if ($restaurantConfig->same_time_for_every_day) {

            $restaurantId = $restaurantConfig->restaurant_id;
            $dayOneSchedules = RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', 1)
                ->get();

            if ($dayOneSchedules->isEmpty()) {
                RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->delete();
                return response()->json([
                    'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
                ]);
            }
            RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', '!=', 1)
                ->delete();

            $insertData = [];

            foreach ($dayOneSchedules as $schedule) {
                foreach (range(0, 6) as $day) {
                    if ($day == 1) {
                        continue;
                    }

                    $insertData[] = [
                        'restaurant_id' => $restaurantId,
                        'day'           => $day,
                        'opening_time'  => $schedule->opening_time,
                        'closing_time'  => $schedule->closing_time,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
            }

            if (!empty($insertData)) {
                RestaurantSchedule::insert($insertData);
            }
        }

        return response()->json([
            'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
        ]);
    }




    public function updateOpeningClosingStatus(Request $request, $id)
    {
        $restaurant = Restaurant::findOrFail($id);

        $config = RestaurantConfig::firstOrNew(['restaurant_id' => $id]);


        $config->opening_closing_status = $request->opening_closing_status ? 1 : 0;
        $config->same_time_for_every_day = $request->same_time_for_every_day ? 1 : 0;
        $config->save();

        if ($config->opening_closing_status) {

            RestaurantSchedule::where('restaurant_id', $restaurant->id)->delete();

            $insertData = [];

            foreach (range(0, 6) as $day) {
                $insertData[] = [
                    'restaurant_id' => $restaurant->id,
                    'day'           => $day,
                    'opening_time'  => '00:00:00',
                    'closing_time'  => '23:59:59',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }

            RestaurantSchedule::insert($insertData);

            return [
                'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
            ];
        }

        if ($config->same_time_for_every_day) {

            $restaurantId = $config->restaurant_id;
            $dayOneSchedules = RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', 1)
                ->get();

            if ($dayOneSchedules->isEmpty()) {
                RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->delete();
                return response()->json([
                    'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
                ]);
            }
            RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', '!=', 1)
                ->delete();

            $insertData = [];

            foreach ($dayOneSchedules as $schedule) {
                foreach (range(0, 6) as $day) {
                    if ($day == 1) {
                        continue;
                    }

                    $insertData[] = [
                        'restaurant_id' => $restaurantId,
                        'day'           => $day,
                        'opening_time'  => $schedule->opening_time,
                        'closing_time'  => $schedule->closing_time,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
            }

            if (!empty($insertData)) {
                RestaurantSchedule::insert($insertData);
            }
        }

        return response()->json([
            'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
        ]);
    }


    public function conversation_list(Request $request)
    {
        $type = $request->type ?? 'customer';
        $user = UserInfo::where('vendor_id', $request->user_id)->first();
        $restaurant = Restaurant::where('vendor_id', $request->user_id)->first();
        $conversations = Conversation::WhereUser($user->id)->WhereUserType($type);
        if ($request->query('key') != null) {
            $key = explode(' ', $request->get('key'));
            $conversations = $conversations->where(function ($qu) use ($key) {
                $qu->whereHas('sender', function ($query) use ($key) {
                    foreach ($key as $value) {
                        $query->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%")->orWhere('phone', 'like', "%{$value}%");
                    }
                })->orWhereHas('receiver', function ($query1) use ($key) {
                    foreach ($key as $value) {
                        $query1->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%")->orWhere('phone', 'like', "%{$value}%");
                    }
                });
            });
        }
        $conversations = $conversations->paginate(8);
        $view = view('admin-views.vendor.view.partials._conversation_list', compact('conversations', 'restaurant', 'type'))->render();

        return response()->json(['html' => $view]);
    }

    public function conversation_view($conversation_id, $user_id)
    {
        // $convs = Message::where(['conversation_id' => $conversation_id])->get();
        // $conversation = Conversation::find($conversation_id);
        // $receiver = UserInfo::find($conversation->receiver_id);
        // $sender = UserInfo::find($conversation->sender_id);
        // $user = UserInfo::find($user_id);
        // return response()->json([
        //     'view' => view('admin-views.vendor.view.partials._conversations', compact('convs', 'user', 'receiver'))->render()
        // ]);
    }

    public function cash_transaction_export(Request $request)
    {
        try {
            $transaction = AccountTransaction::where('from_type', 'restaurant')->where('type', 'collected')->where('from_id', $request->restaurant)->get();
            $data = [
                'data' => $transaction,
                'search' => $request['search'] ?? null,
            ];
            if ($request->type == 'csv') {
                return Excel::download(new RestaurantWiseCashCollectionExport($data), 'CashTransaction.csv');
            }

            return Excel::download(new RestaurantWiseCashCollectionExport($data), 'CashTransaction.xlsx');
        } catch (\Exception $e) {
            Toastr::error("line___{$e->getLine()}", $e->getMessage());
            info(["line___{$e->getLine()}", $e->getMessage()]);

            return back();
        }

    }

    public function digital_transaction_export(Request $request)
    {
        try {
            $transaction = OrderTransaction::where('vendor_id', $request->restaurant)->latest()->get();

            $data = [
                'data' => $transaction,
                'search' => $request['search'] ?? null,
            ];
            if ($request->type == 'csv') {
                return Excel::download(new RestaurantWiseWithdrawOrderTransactionExport($data), 'AdminOrderTransaction.csv');
            }

            return Excel::download(new RestaurantWiseWithdrawOrderTransactionExport($data), 'AdminOrderTransaction.xlsx');
        } catch (\Exception $e) {
            Toastr::error("line___{$e->getLine()}", $e->getMessage());
            info(["line___{$e->getLine()}", $e->getMessage()]);

            return back();
        }

    }

    public function withdraw_transaction_export(Request $request)
    {
        try {
            $withdraw_transaction = WithdrawRequest::where('vendor_id', $request->restaurant)->get();
            $data = [
                'data' => $withdraw_transaction,
                'search' => $request['search'] ?? null,
            ];
            if ($request->type == 'csv') {
                return Excel::download(new RestaurantWiseWithdrawTransactionExport($data), 'WithdrawTransaction.csv');
            }

            return Excel::download(new RestaurantWiseWithdrawTransactionExport($data), 'WithdrawTransaction.xlsx');
        } catch (\Exception $e) {
            Toastr::error("line___{$e->getLine()}", $e->getMessage());
            info(["line___{$e->getLine()}", $e->getMessage()]);

            return back();
        }
    }

    public function withdraw_search(Request $request)
    {
        $key = explode(' ', $request['search']);
        $withdraw_req = WithdrawRequest::whereHas('vendor', function ($query) use ($key) {
            $query->whereHas('restaurants', function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->where('name', 'like', "%{$value}%");
                }
            });
        })->get();

        return response()->json([
            'view' => view('admin-views.wallet.partials._table', compact('withdraw_req'))->render(),
            'total' => $withdraw_req?->count(),
        ]);
    }

    public function restaurants_export(Request $request, $type)
    {
        try {
            $key = explode(' ', $request['search']);
            $zone_id = $request->query('zone_id', 'all');
            $cuisine_id = $request->query('cuisine_id', 'all');
            $type = $request->query('type', 'all');
            $typ = $request->query('restaurant_model', null);
            $restaurants = Restaurant::when(is_numeric($zone_id), function ($query) use ($zone_id) {
                return $query->where('zone_id', $zone_id);
            })
                ->with(['zone', 'cuisine', 'vendor'])
                ->withSum('reviews', 'rating')
                ->withCount('reviews', 'foods')
                ->whereHas('vendor', function ($q) {
                    $q->where('status', 1);
                })
                ->when(isset($key), function ($q) use ($key) {
                    $q->where(function ($query) use ($key) {
                        $query->orWhereHas('vendor', function ($q) use ($key) {
                            foreach ($key as $value) {
                                $q->orWhere('f_name', 'like', "%{$value}%")
                                    ->orWhere('l_name', 'like', "%{$value}%")
                                    ->orWhere('email', 'like', "%{$value}%")
                                    ->orWhere('phone', 'like', "%{$value}%");
                            }
                        })
                            ->where(function ($q) use ($key) {
                                foreach ($key as $value) {
                                    $q->orWhere('name', 'like', "%{$value}%")
                                        ->orWhere('email', 'like', "%{$value}%")
                                        ->orWhere('phone', 'like', "%{$value}%");
                                }
                            });
                    });
                })
                ->cuisine($cuisine_id)
                ->type($type)->RestaurantModel($typ)->latest()->get();

            $data = [
                'data' => $restaurants,
                'zone' => is_numeric($zone_id) ? Helpers::get_zones_name($zone_id) : null,
                'search' => $request['search'] ?? null,
                'model' => $typ ?? null,
                'type' => $type ?? null,
                'cuisine' => is_numeric($cuisine_id) ? Cuisine::where('id', $cuisine_id)->first()?->name : null,

            ];
            if ($request->type == 'csv') {
                return Excel::download(new RestaurantListExport($data), 'Restaurants.csv');
            }

            return Excel::download(new RestaurantListExport($data), 'Restaurants.xlsx');

        } catch (\Exception $e) {
            Toastr::error("line___{$e->getLine()}", $e->getMessage());
            info(["line___{$e->getLine()}", $e->getMessage()]);

            return back();
        }
    }

    public function restaurant_wise_reviwe_export(Request $request)
    {
        try {
            $restaurant = Restaurant::where('id', $request->id)->first();
            $reviews = $restaurant->reviews()->with('food', function ($query) {
                $query->withoutGlobalScope(\App\Scopes\RestaurantScope::class);
            })->get();

            $user_rating = null;
            $total_rating = 0;
            $total_reviews = 0;
            foreach ($reviews as $key => $value) {
                $user_rating += $value->rating;
                $total_rating += 1;
                $total_reviews += 1;
            }
            $user_rating = isset($user_rating) ? ($user_rating) / count($reviews) : 0;
            $data = [
                'restaurant_name' => $restaurant->name,
                'restaurant_id' => $restaurant->id,
                'rating' => number_format($user_rating, 1),
                'total_reviews' => $total_reviews,
                'data' => $reviews,
            ];
            if ($request->type == 'csv') {
                return Excel::download(new RestauranrWiseFoodReviewExport($data), 'RestaurantWiseFoodReview.csv');
            }

            return Excel::download(new RestauranrWiseFoodReviewExport($data), 'RestaurantWiseFoodReview.xlsx');
        } catch (\Exception $e) {
            Toastr::error("line___{$e->getLine()}", $e->getMessage());
            info(["line___{$e->getLine()}", $e->getMessage()]);

            return back();
        }
    }

    public function withdraw_list_export(Request $request)
    {
        try {
            $key = isset($request['search']) ? explode(' ', $request['search']) : [];

            $all = session()->has('withdraw_status_filter') && session('withdraw_status_filter') == 'all' ? 1 : 0;
            $active = session()->has('withdraw_status_filter') && session('withdraw_status_filter') == 'approved' ? 1 : 0;
            $denied = session()->has('withdraw_status_filter') && session('withdraw_status_filter') == 'denied' ? 1 : 0;
            $pending = session()->has('withdraw_status_filter') && session('withdraw_status_filter') == 'pending' ? 1 : 0;

            $withdraw_req = WithdrawRequest::with(['vendor'])
                ->when($all, function ($query) {
                    return $query;
                })
                ->when($active, function ($query) {
                    return $query->where('approved', 1);
                })
                ->when($denied, function ($query) {
                    return $query->where('approved', 2);
                })
                ->when($pending, function ($query) {
                    return $query->where('approved', 0);
                })
                ->when(isset($key), function ($query) use ($key) {
                    return $query->whereHas('vendor', function ($query) use ($key) {
                        $query->whereHas('restaurants', function ($q) use ($key) {
                            foreach ($key as $value) {
                                $q->where('name', 'like', "%{$value}%");
                            }
                        });
                    });
                })
                ->latest()
                ->get();
            $data = [
                'withdraw_requests' => $withdraw_req,
                'search' => $request->search ?? null,
                'request_status' => session()->has('withdraw_status_filter') ? session('withdraw_status_filter') : null,

            ];

            if ($request->type == 'excel') {
                return Excel::download(new RestaurantWithdrawTransactionExport($data), 'WithdrawRequests.xlsx');
            } elseif ($request->type == 'csv') {
                return Excel::download(new RestaurantWithdrawTransactionExport($data), 'WithdrawRequests.csv');
            }
        } catch (\Exception $e) {
            Toastr::error("line___{$e->getLine()}", $e->getMessage());
            info(["line___{$e->getLine()}", $e->getMessage()]);

            return back();
        }
    }

    public function disbursement_export(Request $request, $id, $type)
    {
        $key = explode(' ', $request['search']);

        $restaurant = Restaurant::find($id);
        $disbursements = DisbursementDetails::where('restaurant_id', $restaurant->id)
            ->when(isset($key), function ($q) use ($key) {
                $q->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('disbursement_id', 'like', "%{$value}%")
                            ->orWhere('status', 'like', "%{$value}%");
                    }
                });
            })
            ->latest()->get();
        $data = [
            'disbursements' => $disbursements,
            'search' => $request->search ?? null,
            'restaurant' => $restaurant->name,
            'type' => 'restaurant',
        ];

        if ($request->type == 'excel') {
            return Excel::download(new DisbursementHistoryExport($data), 'Disbursementlist.xlsx');
        } elseif ($request->type == 'csv') {
            return Excel::download(new DisbursementHistoryExport($data), 'Disbursementlist.csv');
        }
    }

    public function updateStoreMetaData(Restaurant $restaurant, Request $request)
    {
        $request->validate([
            'meta_title.0' => 'required',
            'meta_title.*' => 'max:100',
            'meta_description.0' => 'required',
        ], [
            'meta_title.0.required' => translate('default_meta_title_is_required'),
            'meta_description.0.required' => translate('default_meta_description_is_required'),
            'meta_title.max' => translate('Title_must_be_within_100_character'),
        ]);

        $restaurant->meta_image = $request->has('meta_image') ? Helpers::update('restaurant/', $restaurant->meta_image, 'png', $request->file('meta_image')) : $restaurant->meta_image;
        $restaurant->meta_title = $request->meta_title[array_search('default', $request->lang)];
        $restaurant->meta_description = $request->meta_description[array_search('default', $request->lang)];
        $restaurant->meta_data = Helpers::formatMetaData($request->all(), $restaurant->meta_data);

        $restaurant->save();

        // Helpers::add_or_update_translations(request:$request,key_data: 'meta_title', name_field:'meta_title' , model_name:'Restaurant' ,data_id:$restaurant->id,data_value:$restaurant->meta_title);
        // Helpers::add_or_update_translations(request:$request,key_data: 'meta_description', name_field:'meta_description' , model_name:'Restaurant' ,data_id:$restaurant->id,data_value:$restaurant->meta_description);

        Toastr::success(translate('messages.meta_data_updated'));

        return back();
    }

    public function qr_store(Restaurant $restaurant, Request $request)
    {
        $request->validate([
            'title' => 'required',
            'description' => 'required',
            'phone' => 'required',
            'website' => 'required',
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

    public function download_pdf(Restaurant $restaurant)
    {
        $data = json_decode($restaurant->qr_code, true);
        $code = isset($data) ? QrCode::size(180)->generate(json_encode($data)) : '';
        $pdf = PDF::loadView('admin-views.vendor.view.qrcode-pdf', compact('restaurant', 'data', 'code'))->setOptions(['defaultFont' => 'sans-serif']);

        return $pdf->download('qr-code' . rand(00001, 99999) . '.pdf');
    }

    public function print_qrcode(Restaurant $restaurant)
    {
        $data = json_decode($restaurant->qr_code, true);
        $code = isset($data)
            ? QrCode::size(180)->generate(
                $data['website']
                . (str_contains($data['website'], '?') ? ' ' : '?')
                . 'qrcode='
                . urlencode(base64_encode(json_encode($data)))
            )
            : '';

        return view('admin-views.vendor.view.qrcode-print', compact('restaurant', 'data', 'code'));
    }
}
