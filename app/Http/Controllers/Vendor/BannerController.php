<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Banner;
use Brian2694\Toastr\Facades\Toastr;


class BannerController extends Controller
{
    function list()
    {
        $banners=Banner::latest()->paginate(config('default_pagination'));
        return view('vendor-views.banner.list',compact('banners'));
    }


    public function status(Request $request)
    {
        $banner = Banner::findOrFail($request->id);
        $restaurantId = Helpers::get_restaurant_id();
        $restaurantIds = array_map('intval', json_decode($banner->restaurant_ids, true) ?: []);
        if(in_array($restaurantId, $restaurantIds, true))
        {
            unset($restaurantIds[array_search($restaurantId, $restaurantIds, true)]);
        }
        else
        {
            $restaurantIds[] = $restaurantId;
        }

        $banner->restaurant_ids = json_encode(array_values($restaurantIds));
        $banner->save();
        Toastr::success(translate('messages.capmaign_participation_updated'));
        return back();
    }

}
