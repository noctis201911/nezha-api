<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCasePolicy;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCaseService;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentUsdtObservationGateway;
use App\Http\Controllers\Controller;
use App\Models\MerchantDirectPaymentLateCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class MerchantDirectPaymentLateCaseController extends Controller
{
    public function __construct(
        private readonly MerchantDirectPaymentLateCaseService $service,
        private readonly MerchantDirectPaymentUsdtObservationGateway $gateway
    ) {}

    public function index(Request $request)
    {
        $cases = MerchantDirectPaymentLateCase::where('source_domain', MerchantDirectPaymentLateCaseService::SOURCE)
            ->where('restaurant_id', Helpers::get_restaurant_id())
            ->with(['events' => fn ($query) => $query->orderBy('sequence')])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->latest('id')
            ->limit(100)
            ->get();
        $data = $cases->map(fn ($case) => $this->service->staffProjection($case))->values();

        if (! $request->expectsJson() && ! $request->boolean('json')) {
            return view('vendor-views.order.late-payment', [
                'cases' => $data,
                'featureEnabled' => MerchantDirectPaymentLateCaseService::enabled(),
            ]);
        }

        return response()->json(['data' => $data]);
    }

    public function show(string $caseId)
    {
        $case = $this->caseForRestaurant($caseId);

        return $case
            ? response()->json(['data' => $this->service->staffProjection($case)])
            : $this->error('late_payment_case_not_found', 404);
    }

    public function attribute(Request $request, string $caseId)
    {
        $validator = Validator::make($request->all(), [
            'received_amount_atomic' => 'required|string|max:78|regex:/^[1-9][0-9]*$/',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        $case = $this->caseForRestaurant($caseId);
        if (! $case) {
            return $this->error('late_payment_case_not_found', 404);
        }
        if (! MerchantDirectPaymentLateCaseService::enabled()) {
            return $this->error('direct_payment_late_v2_disabled', 422);
        }
        if ($case->status !== MerchantDirectPaymentLateCasePolicy::STATE_REVIEW_PENDING) {
            return $this->error('late_payment_case_not_reviewable', 422);
        }
        $observation = MerchantDirectPaymentLateCasePolicy::isUsdt($case->payment_channel)
            ? $this->gateway->observe($case->payment_channel, (string) $case->late_payment_tx_hash)
            : [];
        try {
            $case = $this->service->attributePayment(
                $case,
                (string) $request->input('received_amount_atomic'),
                $observation,
                'merchant',
                (int) Helpers::get_vendor_id()
            );

            return response()->json(['data' => $this->service->customerProjection($case)]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function terms(Request $request, string $caseId)
    {
        $validator = Validator::make($request->all(), [
            'refund_amount_atomic' => 'required|string|max:78|regex:/^[1-9][0-9]*$/',
            'refund_destination' => 'nullable|string|max:120',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        $case = $this->caseForRestaurant($caseId);
        if (! $case) {
            return $this->error('late_payment_case_not_found', 404);
        }
        try {
            $case = $this->service->setRefundTerms(
                $case,
                (string) $request->input('refund_amount_atomic'),
                $request->input('refund_destination'),
                (int) Helpers::get_restaurant_id(),
                (int) Helpers::get_vendor_id()
            );

            return response()->json(['data' => $this->service->customerProjection($case)]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function submitRefund(Request $request, string $caseId)
    {
        $validator = Validator::make($request->all(), [
            'refund_reference' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        $case = $this->caseForRestaurant($caseId);
        if (! $case) {
            return $this->error('late_payment_case_not_found', 404);
        }
        if (! MerchantDirectPaymentLateCaseService::enabled()) {
            return $this->error('direct_payment_late_v2_disabled', 422);
        }
        if ($case->status !== MerchantDirectPaymentLateCasePolicy::STATE_REFUND_REQUIRED
            || ! $case->refund_amount_atomic) {
            return $this->error('late_payment_refund_terms_missing', 422);
        }
        $reference = $request->input('refund_reference');
        $observation = MerchantDirectPaymentLateCasePolicy::isUsdt($case->payment_channel)
            ? $this->gateway->observe($case->payment_channel, (string) $reference)
            : [];
        try {
            $case = $this->service->submitRefund(
                $case,
                $reference,
                $observation,
                (int) Helpers::get_restaurant_id(),
                (int) Helpers::get_vendor_id(),
                $request->input('note')
            );

            return response()->json(['data' => $this->service->customerProjection($case)]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    private function caseForRestaurant(string $caseId): ?MerchantDirectPaymentLateCase
    {
        return MerchantDirectPaymentLateCase::where('case_public_id', $caseId)
            ->where('source_domain', MerchantDirectPaymentLateCaseService::SOURCE)
            ->where('restaurant_id', Helpers::get_restaurant_id())
            ->first();
    }

    private function error(string $code, int $status)
    {
        return response()->json(['errors' => [['code' => $code, 'message' => $code]]], $status);
    }
}
