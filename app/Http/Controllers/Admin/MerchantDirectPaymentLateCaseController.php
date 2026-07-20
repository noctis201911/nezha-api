<?php

namespace App\Http\Controllers\Admin;

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
            ->with(['events' => fn ($query) => $query->orderBy('sequence')])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->latest('id')
            ->limit(100)
            ->get();
        $data = $cases->map(fn ($case) => $this->service->staffProjection($case))->values();

        if (! $request->expectsJson() && ! $request->boolean('json')) {
            return view('admin-views.nezha-refund.late-payment', [
                'cases' => $data,
                'featureEnabled' => MerchantDirectPaymentLateCaseService::enabled(),
            ]);
        }

        return response()->json(['data' => $data]);
    }

    public function show(string $caseId)
    {
        return response()->json(['data' => $this->service->staffProjection($this->case($caseId))]);
    }

    public function attribute(Request $request, string $caseId)
    {
        $validator = Validator::make($request->all(), [
            'received_amount_atomic' => 'required|string|max:78|regex:/^[1-9][0-9]*$/',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        $case = $this->case($caseId);
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
                'admin',
                (int) auth('admin')->id()
            );

            return response()->json(['data' => $this->service->customerProjection($case)]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function closeNoPayment(Request $request, string $caseId)
    {
        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:1000']);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        try {
            $case = $this->service->closeNoPayment(
                $this->case($caseId),
                'admin',
                (int) auth('admin')->id(),
                (string) $request->input('reason')
            );

            return response()->json(['data' => $this->service->customerProjection($case)]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function retryUsdt(Request $request, string $caseId)
    {
        $case = $this->case($caseId);
        if (! MerchantDirectPaymentLateCasePolicy::isUsdt($case->payment_channel)) {
            return $this->error('usdt_refund_not_pending_verification', 422);
        }
        if (! MerchantDirectPaymentLateCaseService::enabled()) {
            return $this->error('direct_payment_late_v2_disabled', 422);
        }
        if ($case->status !== MerchantDirectPaymentLateCasePolicy::STATE_USDT_REFUND_VERIFICATION_PENDING) {
            return $this->error('usdt_refund_not_pending_verification', 422);
        }
        $observation = $this->gateway->observe($case->payment_channel, (string) $case->late_refund_tx_hash);
        try {
            $case = $this->service->retryUsdtRefundVerification(
                $case,
                $observation,
                'admin',
                (int) auth('admin')->id()
            );

            return response()->json(['data' => $this->service->customerProjection($case)]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    private function case(string $caseId): MerchantDirectPaymentLateCase
    {
        return MerchantDirectPaymentLateCase::where('case_public_id', $caseId)
            ->where('source_domain', MerchantDirectPaymentLateCaseService::SOURCE)
            ->firstOrFail();
    }

    private function error(string $code, int $status)
    {
        return response()->json(['errors' => [['code' => $code, 'message' => $code]]], $status);
    }
}
