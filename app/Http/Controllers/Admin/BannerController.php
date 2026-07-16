<?php

namespace App\Http\Controllers\Admin;

use App\Models\Banner;
use App\Models\DataSetting;
use App\Models\Translation;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaPromotionalBanner;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BannerController extends Controller
{
    function index(Request $request)
    {
        $key = explode(' ', $request['search']);
        $type = $request['type'] ?? null;
        $banners = Banner::with('storage')->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('title', 'like', "%{$value}%");
            }
        })
        ->when($type, function ($query) use ($type) {
            return $query->where('type', $type);
        })
        ->latest()->paginate(config('default_pagination'));
        return view('admin-views.banner.index', compact('banners'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|max:191',
            'image' => 'required|max:2048',
            'banner_type' => 'required',
            'zone_id' => 'required',
            'restaurant_id' => 'required_if:banner_type,restaurant_wise',
            'item_id' => 'required_if:banner_type,item_wise',
        ], [
            'zone_id.required' => translate('messages.select_a_zone'),
            'restaurant_id.required_if'=> translate('messages.Restaurant is required when banner type is restaurant wise'),
            'item_id.required_if'=> translate('messages.Food is required when banner type is food wise'),
        ]);

        if($request->title[array_search('default', $request->lang)] == '' ){
            $validator->getMessageBag()->add('title', translate('messages.default_title_is_required'));
                    return response()->json(['errors' => Helpers::error_processor($validator)]);
            }
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        $banner = new Banner;
        $banner->title = $request->title[array_search('default', $request->lang)];
        $banner->type = $request->banner_type;
        $banner->zone_id = $request->zone_id;
        $banner->image = Helpers::upload(dir:'banner/',  format:'png', image: $request->file('image'));
        $banner->data = ($request->banner_type == 'restaurant_wise')?$request->restaurant_id:$request->item_id;
        $banner->save();
        $data=[];
        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach ($request->lang as $index => $key) {
            if($default_lang == $key && !($request->title[$index])){
                if ($key != 'default') {
                    $data[] = array(
                        'translationable_type' => 'App\Models\Banner',
                        'translationable_id' => $banner->id,
                        'locale' => $key,
                        'key' => 'title',
                        'value' => $banner->title,
                    );
                }
            }else{
                if ($request->title[$index] && $key != 'default') {
                    $data[] = array(
                        'translationable_type' => 'App\Models\Banner',
                        'translationable_id' => $banner->id,
                        'locale' => $key,
                        'key' => 'title',
                        'value' => $request->title[$index],
                    );
                }
            }
        }
        Translation::insert($data);
        return response()->json([], 200);
    }

    public function edit(Banner $banner)
    {
        $banner->load('translations')->withoutGlobalScope('translations');
        return view('admin-views.banner.edit', compact('banner'));
    }


    public function status(Request $request)
    {
        $banner = Banner::findOrFail($request->id);
        $banner->status = $request->status;
        $banner->save();
        Toastr::success(translate('messages.banner_status_updated'));
        return back();
    }

    public function update(Request $request, Banner $banner)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|max:191',
            'banner_type' => 'required',
            'zone_id' => 'required',
            'image' => 'nullable|max:2048',
            'restaurant_id' => 'required_if:banner_type,restaurant_wise',
            'item_id' => 'required_if:banner_type,item_wise',
        ], [
            'zone_id.required' => translate('messages.select_a_zone'),
            'restaurant_id.required_if'=> translate('messages.Restaurant is required when banner type is restaurant wise'),
            'item_id.required_if'=> translate('messages.Food is required when banner type is food wise'),
        ]);


        if($request->title[array_search('default', $request->lang)] == '' ){
            $validator->getMessageBag()->add('title', translate('messages.default_title_is_required'));
                    return response()->json(['errors' => Helpers::error_processor($validator)]);
            }

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        $banner->title = $request->title[array_search('default', $request->lang)];;
        $banner->type = $request->banner_type;
        $banner->zone_id = $request->zone_id;
        $banner->image = $request->has('image') ? Helpers::update(dir:'banner/',old_image: $banner->image, format:'png', image: $request->file('image')) : $banner->image;
        $banner->data = $request->banner_type=='restaurant_wise'?$request->restaurant_id:$request->item_id;
        $banner->save();
        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach ($request->lang as $index => $key) {
            if($default_lang == $key && !($request->title[$index])){
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\Banner',
                            'translationable_id' => $banner->id,
                            'locale' => $key,
                            'key' => 'title'
                        ],
                        ['value' => $banner->title]
                    );
                }
            }else{

                if ($request->title[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\Banner',
                            'translationable_id' => $banner->id,
                            'locale' => $key,
                            'key' => 'title'
                        ],
                        ['value' => $request->title[$index]]
                    );
                }
            }
        }
        return response()->json([], 200);
    }

    public function delete(Banner $banner)
    {
        Helpers::check_and_delete('banner/' , $banner['image']);
        $banner?->translations()?->delete();
        $banner->delete();
        Toastr::success(translate('messages.banner_deleted_successfully'));
        return back();
    }

    public function search(Request $request){
        $key = explode(' ', $request['search']);
        $banners=Banner::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('title', 'like', "%{$value}%");
            }
        })->limit(50)->get();
        return response()->json([
            'view'=>view('admin-views.banner.partials._table',compact('banners'))->render(),
            'count'=>$banners->count()
        ]);
    }





    public function promotional_banner(){
        $banner_title =  DataSetting::where('type','promotional_banner')->where('key' ,'promotional_banner_title')->withoutGlobalScope('translate')->with('translations')->first();
        $banner_image =  DataSetting::where('type','promotional_banner')->where('key', 'promotional_banner_image')->withoutGlobalScope('translate')->with('translations')->first();
        $banner_status = DataSetting::where('type', 'promotional_banner')
            ->where('key', 'promotional_banner_status')
            ->withoutGlobalScope('translate')
            ->first();

        return view('admin-views.banner.promotional_banner', compact('banner_title', 'banner_image', 'banner_status'));
    }

    public function promotional_banner_update(Request $request){

        $validated = $request->validate([
            'promotional_banner_status' => 'required|in:0,1',
        ]);
        $request->merge([
            'promotional_banner_status' => (string) $validated['promotional_banner_status'],
        ]);

        $defaultTitle = null;
        if ($request->promotional_banner_status === '1') {
            $request->validate([
                'promotional_banner_title' => 'required|array',
                'promotional_banner_title.*' => 'nullable|string|max:191',
                'promotional_banner_title.0' => 'required',
                'lang' => 'required|array',
                'lang.*' => 'string',
                'promotional_banner_image' => 'nullable|mimes:'.IMAGE_FORMAT_FOR_VALIDATION.'|max:2048',
            ], [
                'promotional_banner_title.required' => translate('messages.Title is required!'),
                'promotional_banner_title.0.required' => translate('default_Title_is_required'),
            ]);
            $defaultIndex = array_search('default', $request->lang, true);
            if ($defaultIndex === false) {
                throw ValidationException::withMessages([
                    'promotional_banner_title.0' => translate('default_Title_is_required'),
                ]);
            }
            $defaultTitle = $request->promotional_banner_title[$defaultIndex];
        }

        if ($request->promotional_banner_status === '1'
            && ! NezhaPromotionalBanner::hasPublishableTitle($defaultTitle)) {
            throw ValidationException::withMessages([
                'promotional_banner_title.0' => translate('messages.invalid_data'),
            ]);
        }

        if ($request->promotional_banner_status === '1' && ! $request->hasFile('promotional_banner_image')) {
            $imageSetting = DataSetting::where('type', 'promotional_banner')
                ->where('key', 'promotional_banner_image')
                ->withoutGlobalScope('translate')
                ->first();
            $storage = $imageSetting?->storage[0]?->value ?? 'public';

            if (! NezhaPromotionalBanner::imageExists($imageSetting?->getRawOriginal('value'), $storage)) {
                throw ValidationException::withMessages([
                    'promotional_banner_image' => translate('messages.invalid_data'),
                ]);
            }
        }

        $newImage = null;
        $newImageStorage = null;
        if ($request->promotional_banner_status === '1'
            && $request->hasFile('promotional_banner_image')) {
            $oldImage = DataSetting::where('type', 'promotional_banner')
                ->where('key', 'promotional_banner_image')
                ->withoutGlobalScope('translate')
                ->first()?->getRawOriginal('value');
            $newImage = Helpers::update(
                dir: 'banner/',
                old_image: $oldImage,
                format: 'png',
                image: $request->file('promotional_banner_image')
            );
            $newImageStorage = Helpers::getDisk();
        }

        DB::transaction(function () use ($request, $defaultTitle, $newImage, $newImageStorage): void {
            if ($request->promotional_banner_status === '1') {
                $titleIds = $this->updateMatchingDataSettingRows(
                    'promotional_banner_title',
                    $defaultTitle
                );
                $this->updateDataSettingTranslations(
                    $request,
                    $titleIds,
                    'promotional_banner_title'
                );

                if ($newImage !== null) {
                    $imageIds = $this->updateMatchingDataSettingRows(
                        'promotional_banner_image',
                        $newImage
                    );
                    foreach ($imageIds as $imageId) {
                        DB::table('storages')->updateOrInsert([
                            'data_type' => DataSetting::class,
                            'data_id' => $imageId,
                        ], [
                            'value' => $newImageStorage,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            $this->updateMatchingDataSettingRows(
                'promotional_banner_status',
                $request->promotional_banner_status
            );
        });
        NezhaPromotionalBanner::forgetLegacyCache();
        Toastr::success(translate('messages.banner_updated_successfully'));
        return back();

    }

    private function updateMatchingDataSettingRows(string $key, ?string $value): array
    {
        $query = DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->where('key', $key);
        $ids = $query->lockForUpdate()->pluck('id')->all();
        $timestamp = now();

        if ($ids === []) {
            $ids[] = DB::table('data_settings')->insertGetId([
                'type' => 'promotional_banner',
                'key' => $key,
                'value' => $value,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } else {
            DB::table('data_settings')
                ->whereIn('id', $ids)
                ->update([
                    'value' => $value,
                    'updated_at' => $timestamp,
                ]);
        }

        return $ids;
    }


    private function updateDataSettingTranslations(Request $request, array $dataSettingIds, string $translationKey): void
    {
        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach ($request->lang as $index => $lang) {
            foreach ($dataSettingIds as $dataSettingId) {
                if ($default_lang == $lang && ! $request->promotional_banner_title[$index]) {
                    if ($lang != 'default') {
                        DB::table('translations')->updateOrInsert(
                            [
                                'translationable_type' => DataSetting::class,
                                'translationable_id' => $dataSettingId,
                                'locale' => $lang,
                                'key' => $translationKey,
                            ],
                            ['value' => $request->promotional_banner_title[array_search('default', $request->lang)]]
                        );
                    }
                } elseif ($request->promotional_banner_title[$index] && $lang != 'default') {
                    DB::table('translations')->updateOrInsert(
                        [
                            'translationable_type' => DataSetting::class,
                            'translationable_id' => $dataSettingId,
                            'locale' => $lang,
                            'key' => $translationKey,
                        ],
                        ['value' => $request->promotional_banner_title[$index]]
                    );
                }
            }
        }
    }
}
