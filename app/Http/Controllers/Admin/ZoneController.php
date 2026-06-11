<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Exports\ZoneExport;
use App\Http\Controllers\Controller;
use App\Models\Incentive;
use App\Models\Zone;
use App\Models\ZoneDeliveryOption;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use MatanYadaev\EloquentSpatial\Objects\LineString;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Objects\Polygon;

class ZoneController extends Controller
{
    public function index(Request $request)
    {
        $key = explode(' ', $request['search'] ?? null);
        $zones = Zone::withCount(['restaurants', 'deliverymen'])
            ->when(isset($key), function ($query) use ($key) {
                $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })
            ->latest()->paginate(config('default_pagination'));

        return view('admin-views.zone.index', compact('zones'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:zones|max:191',
            'display_name' => 'nullable|unique:zones|max:255',
            'coordinates' => 'required',
        ]);

        if ($request->name[array_search('default', $request->lang)] == '') {
            $validator->getMessageBag()->add('title', translate('messages.default_Business_zone_name_is_required'));

            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }
        $value = $request->coordinates;
        foreach (explode('),(', trim($value, '()')) as $index => $single_array) {
            if ($index == 0) {
                $lastcord = explode(',', $single_array);
            }
            $coords = explode(',', $single_array);
            $polygon[] = new Point($coords[0], $coords[1]);
        }
        $zone_id = Zone::all()->count() + 1;
        $polygon[] = new Point($lastcord[0], $lastcord[1]);
        $zone = new Zone;
        $zone->name = $request->name[array_search('default', $request->lang)];
        $zone->display_name = $request->display_name[array_search('default', $request->lang)];
        $zone->coordinates = new Polygon([new LineString($polygon)]);
        $zone->restaurant_wise_topic = 'zone_'.$zone_id.'_restaurant';
        $zone->customer_wise_topic = 'zone_'.$zone_id.'_customer';
        $zone->deliveryman_wise_topic = 'zone_'.$zone_id.'_delivery_man';
        $zone->per_km_shipping_charge = $request->per_km_delivery_charge ?? 0;
        $zone->minimum_shipping_charge = $request->minimum_delivery_charge ?? 0;
        $zone->maximum_shipping_charge = $request->maximum_shipping_charge ?? null;
        $zone->max_cod_order_amount = $request->max_cod_order_amount ?? null;
        $zone->save();

        Helpers::add_or_update_translations(request: $request, key_data: 'name', name_field: 'name', model_name: 'Zone', data_id: $zone->id, data_value: $zone->name);
        Helpers::add_or_update_translations(request: $request, key_data: 'display_name', name_field: 'display_name', model_name: 'Zone', data_id: $zone->id, data_value: $zone->display_name);

        $new_data = 1;
        $zones = Zone::withCount(['restaurants', 'deliverymen'])->latest()->paginate(config('default_pagination'));

        return response()->json([
            'view' => view('admin-views.zone.partials._table', compact('zones', 'new_data'))->render(),
            'total' => $zones->count(),
        ]);
    }

    public function edit($id)
    {
        if (env('APP_MODE') == 'demo' && $id == 1) {
            Toastr::warning(translate('messages.you_can_not_edit_this_zone_please_add_a_new_zone_to_edit'));

            return back();
        }
        $zone = Zone::selectRaw('*,ST_AsText(ST_Centroid(`coordinates`)) as center')->withoutGlobalScope('translate')->with('translations')->findOrFail($id);
        $area = json_decode($zone->coordinates[0]->toJson(), true);

        return view('admin-views.zone.edit', compact(['zone', 'area']));
    }

    public function latest_zone_settings()
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::warning(translate('messages.you_can_not_edit_this_zone_please_add_a_new_zone_to_edit'));

            return back();
        }
        $zone = Zone::with('incentives')->selectRaw('*,ST_AsText(ST_Centroid(`coordinates`)) as center')->latest()->first();

        return view('admin-views.zone.settings', compact('zone'));
    }

    public function zone_settings($id)
    {
        if (env('APP_MODE') == 'demo' && $id == 1) {
            Toastr::warning(translate('messages.you_can_not_edit_this_zone_please_add_a_new_zone_to_edit'));

            return back();
        }
        $zone = Zone::with('incentives', 'deliveryOptions')->selectRaw('*,ST_AsText(ST_Centroid(`coordinates`)) as center')->findOrFail($id);

        return view('admin-views.zone.settings', compact('zone'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|max:191|unique:zones,name,'.$id,
            'display_name' => 'nullable|max:255|unique:zones,display_name,'.$id,
            'coordinates' => 'required',
        ]);

        if ($request->name[array_search('default', $request->lang)] == '') {
            Toastr::error(translate('default_Business_zone_name_is_required'));

            return back();

        }

        $value = $request->coordinates;
        foreach (explode('),(', trim($value, '()')) as $index => $single_array) {
            if ($index == 0) {
                $lastcord = explode(',', $single_array);
            }
            $coords = explode(',', $single_array);
            $polygon[] = new Point((float) $coords[0], (float) $coords[1]);
        }
        $polygon[] = new Point((float) $lastcord[0], (float) $lastcord[1]);
        $zone = Zone::findOrFail($id);
        $zone->name = $request->name[array_search('default', $request->lang)];
        $zone->display_name = $request->display_name[array_search('default', $request->lang)];
        $zone->restaurant_wise_topic = 'zone_'.$id.'_restaurant';
        $zone->customer_wise_topic = 'zone_'.$id.'_customer';
        $zone->deliveryman_wise_topic = 'zone_'.$id.'_delivery_man';
        $zone->save();

        Helpers::add_or_update_translations(request: $request, key_data: 'name', name_field: 'name', model_name: 'Zone', data_id: $zone->id, data_value: $zone->name);
        Helpers::add_or_update_translations(request: $request, key_data: 'display_name', name_field: 'display_name', model_name: 'Zone', data_id: $zone->id, data_value: $zone->display_name);

        try {
            $zone->coordinates = new Polygon([new LineString($polygon)]);
            $zone->save();
        } catch (\Exception $exception) {

        }

        Toastr::success(translate('messages.zone_updated_successfully'));

        return redirect()->route('admin.zone.home');
    }

    public function zone_settings_update(Request $request, $id)
    {
        $request->validate([
            'per_km_delivery_charge' => 'required|numeric|between:0.001,999999999999.99',
            'minimum_shipping_charge' => 'required|numeric|between:0.001,999999999999.99',
            'minimum_delivery_charge' => 'required_if:additional_delivery_option_status,1|numeric|between:0,999999999999.99',
            'maximum_shipping_charge' => 'nullable|numeric|between:0,999999999999.99|gt:minimum_shipping_charge',
            'max_cod_order_amount' => 'nullable|numeric|between:0,999999999999.99',
            'increased_delivery_fee' => 'nullable|numeric|between:0,999999999.99|required_if:increased_delivery_fee_status,1',

            'additional_delivery_option_status' => 'sometimes|boolean',

            'minimum_delivery_time' => 'required_if:additional_delivery_option_status,1|integer|min:1',

            'delivery_type' => 'nullable|array',
            'delivery_type.*' => 'in:standard,express,slightly_delay',

            'extra_charge' => 'required_if:additional_delivery_option_status,1|numeric|min:0|max:9999999999.9999',

            'reduce_charge' => 'required_if:additional_delivery_option_status,1|numeric|min:0|max:9999999999.9999',

            'add_delivery_time' => 'required_if:additional_delivery_option_status,1|integer|min:0',

            'reduce_delivery_time' => 'required_if:additional_delivery_option_status,1|integer|min:0',
        ], [
            'increased_delivery_fee.required_if' => translate('messages.increased_delivery_fee_is_required'),
            'minimum_delivery_time.required_if' => translate('messages.minimum_delivery_time_is_required'),
            'extra_charge.required_if' => translate('messages.extra_charge_is_required'),
            'reduce_charge.required_if' => translate('messages.reduce_charge_is_required'),
            'add_delivery_time.required_if' => translate('messages.add_delivery_time_is_required'),
            'reduce_delivery_time.required_if' => translate('messages.reduce_delivery_time_is_required'),
        ]);


        $zone = Zone::findOrFail($id);

        \DB::transaction(function () use ($zone, $request) {
            $zone->restaurant_wise_topic = 'zone_' . $zone->id . '_restaurant';
            $zone->customer_wise_topic = 'zone_' . $zone->id . '_customer';
            $zone->deliveryman_wise_topic = 'zone_' . $zone->id . '_delivery_man';
            $zone->per_km_shipping_charge = $request->per_km_delivery_charge;
            $zone->minimum_shipping_charge = $request->minimum_shipping_charge;
            $zone->minimum_delivery_charge = $request->minimum_delivery_charge;
            $zone->maximum_shipping_charge = $request->maximum_shipping_charge ?? null;
            $zone->max_cod_order_amount = $request->max_cod_order_amount ?? null;
            $zone->increased_delivery_fee = $request->increased_delivery_fee ?? 0;
            $zone->increased_delivery_fee_status = $request->increased_delivery_fee_status ?? 0;
            $zone->increase_delivery_charge_message = $request->increase_delivery_charge_message ?? null;
            $this->updateDeliveryOptions($request,$zone->id);
            $zone->save();
        });

        Toastr::success(translate('messages.zone_settings_updated_successfully'));

        return back();
    }

    public function destroy(Zone $zone)
    {
        if (env('APP_MODE') == 'demo' && $zone->id == 1) {
            Toastr::warning(translate('messages.you_can_not_delete_this_zone_please_add_a_new_zone_to_delete'));

            return back();
        }
        $zone->delete();
        Toastr::success(translate('messages.zone_deleted_successfully'));

        return back();
    }

    public function status(Request $request)
    {
        if (env('APP_MODE') == 'demo' && $request->id == 1) {
            Toastr::warning('Sorry!You can not inactive this zone!');

            return back();
        }
        $zone = Zone::findOrFail($request->id);
        if ($zone->is_default && $request->status == 0) {
            Toastr::warning('Sorry! This zone is set as default.You can not inactive this zone!');

            return back();
        }
        $zone->status = $request->status;
        $zone->save();
        Toastr::success(translate('messages.zone_status_updated'));

        return back();
    }

    public function defaultStatus(Request $request)
    {
        $zone = Zone::findOrFail($request->id);
        $zone->is_default = 1;
        $zone->status = 1;
        $zone->save();
        Zone::where('id', '!=', $request->id)->update(['is_default' => 0]);
        Toastr::success(translate('messages.zone_default_status_updated'));

        return back();
    }

    public function get_coordinates($id)
    {
        $zone = Zone::withoutGlobalScopes()->selectRaw('*,ST_AsText(ST_Centroid(`coordinates`)) as center')->findOrFail($id);
        $area = json_decode($zone->coordinates[0]->toJson(), true);
        $data = Helpers::format_coordiantes($area['coordinates']);
        $center = (object) ['lat' => (float) trim(explode(' ', $zone->center)[1], 'POINT()'), 'lng' => (float) trim(explode(' ', $zone->center)[0], 'POINT()')];

        return response()->json(['coordinates' => $data, 'center' => $center]);
    }

    public function get_zone(Request $request)
    {
        $zone = Helpers::getCoordinatesZone($request->lat,$request->lng);

        return response()->json($zone);
    }

    public function zone_filter($id)
    {
        if ($id == 'all') {
            if (session()->has('zone_id')) {
                session()->forget('zone_id');
            }
        } else {
            session()->put('zone_id', $id);
        }

        return back();
    }

    public function get_all_zone_cordinates($id = 0)
    {
        $zones = Zone::where('id', '<>', $id)->active()->get();
        $data = [];
        foreach ($zones as $zone) {
            $area = json_decode($zone->coordinates[0]->toJson(), true);
            $data[] = Helpers::format_coordiantes($area['coordinates']);
        }

        return response()->json($data, 200);
    }

    public function export_zones(Request $request, $type)
    {

        $key = explode(' ', $request['search']);
        $collection = Zone::withCount(['restaurants', 'deliverymen'])
            ->when(isset($key), function ($q) use ($key) {
                $q->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })
            ->get();
        $data = [
            'data' => $collection,
            'search' => $request['search'] ?? null,
        ];
        if ($type == 'csv') {
            return Excel::download(new ZoneExport($data), 'Zone.csv');
        }

        return Excel::download(new ZoneExport($data), 'Zone.xlsx');
    }

    public function store_incentive(Request $request, $zone_id)
    {
        $request->validate([
            'earning' => [
                'required',
                'numeric',
                'between:1,999999999999.99',
                Rule::unique('incentives', 'earning')->where('zone_id', $zone_id),
            ],
            'incentive' => 'required|numeric|between:1,999999999999.99',
        ], [
            'earning.unique' => translate('This_earning_amount_already_exists'),
        ]);

        $incentive = new Incentive;
        $incentive->earning = $request->earning;
        $incentive->incentive = $request->incentive;
        $incentive->zone_id = $zone_id;
        $incentive->save();
        Toastr::success(translate('messages.incentive_inserted_successfully'));

        return back();
    }

    public function destroy_incentive(Request $request, $id)
    {
        $incentive = Incentive::findOrFail($id);
        $incentive?->delete();
        Toastr::success(translate('messages.incentive_deleted_successfully'));

        return back();
    }
    public function checkLocation(Request $request)
    {
        if (! $request->filled(['latitude', 'longitude', 'zone_id'])) {
            return response()->json([
                'errors' => [
                    ['code' => 'validation', 'message' => translate('messages.Please_select_a_location_within_the_selected_zone.')]
                ]
            ], 200);
        }

        $zone = Zone::query()
            ->where('id', $request->zone_id)
            ->whereContains(
                'coordinates',
                new Point($request->latitude, $request->longitude, POINT_SRID)
            )
            ->first();

        if (! $zone) {
            return response()->json([
                'errors' => [
                    [
                        'code' => 'coordinates',
                        'message' => translate('messages.Please_select_a_location_within_the_selected_zone.')
                    ]
                ]
            ], 200);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    public function updateDeliveryOptions(Request $request, $zone_id)
    {
        $zone = Zone::findOrFail($zone_id);

        \DB::transaction(function () use ($zone, $request) {

            $zone->additional_delivery_option_status = $request->boolean('additional_delivery_option_status', false);
            $zone->minimum_delivery_time = [
                'value' => $request->minimum_delivery_time,
                'unit' => $request->minimum_delivery_time_unit,
            ];
            $zone->save();

                $deliveryTypes = $request->input('delivery_type', ['standard','express','slightly_delay']);
                $extraCharge = $request->input('extra_charge', 0);
                $reduceCharge = $request->input('reduce_charge', 0);
                $addDeliveryTime = $request->input('add_delivery_time', 0);
                $reduceDeliveryTime = $request->input('reduce_delivery_time', 0);
                $conditions = [
                    'standard' => [
                        'extra_charge' => null,
                        'reduce_charge' => null,
                        'add_delivery_time' => ['value' => 0, 'unit' => 'min'],
                        'reduce_delivery_time' => ['value' => 0, 'unit' => 'min'],
                    ],
                    'express' => [
                        'extra_charge' => $extraCharge ?: 0,
                        'reduce_charge' => null,
                        'add_delivery_time' => ['value' => 0, 'unit' => 'min'],
                        'reduce_delivery_time' => [
                            'value' => $reduceDeliveryTime ?: 0,
                            'unit' => $request->reduce_delivery_time_unit ?: 'min',
                        ],
                    ],
                    'slightly_delay' => [
                        'extra_charge' => null,
                        'reduce_charge' => $reduceCharge ?: 0,
                        'add_delivery_time' => [
                            'value' => $addDeliveryTime ?: 0,
                            'unit' => $request->add_delivery_time_unit ?: 'min',
                        ],
                        'reduce_delivery_time' => ['value' => 0, 'unit' => 'min'],
                    ],
                ];

                foreach ($deliveryTypes as $type) {
                    if (!isset($conditions[$type])) {
                        continue;
                    }

                    ZoneDeliveryOption::updateOrCreate(
                        [
                            'zone_id' => $zone->id,
                            'delivery_type' => $type,
                        ],
                        [
                            'extra_charge' => $conditions[$type]['extra_charge'],
                            'reduce_charge' => $conditions[$type]['reduce_charge'],
                            'add_delivery_time' => $conditions[$type]['add_delivery_time'],
                            'reduce_delivery_time' => $conditions[$type]['reduce_delivery_time'],
                        ]
                    );
                }
            
        });

        return true;
    }

}
