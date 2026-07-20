<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCasePolicy;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCaseService;
use App\Http\Controllers\Controller;
use App\Models\MerchantDirectPaymentLateCase;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class MerchantDirectPaymentLateCaseController extends Controller
{
    public function __construct(private readonly MerchantDirectPaymentLateCaseService $service) {}

    public function report(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|integer|min:1',
            'guest_id' => $request->user ? 'nullable' : 'required|integer|min:1',
            'contact_number' => $request->user ? 'nullable' : 'required|string|max:40',
            'channel' => 'required|in:alipay,usdt_trc20,usdt_bep20',
            'method_id' => 'required|integer|min:1',
            'wallet_type' => 'nullable|in:self_custody,exchange',
            'payment_reference' => 'required|string|max:120',
            'address_credential_token' => 'nullable|string|max:512',
            'proof_image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:5120',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }

        $ownerId = (int) ($request->user?->id ?? $request->input('guest_id'));
        $isGuest = ! $request->user;
        $order = $this->ownedOrder($request, $request->integer('order_id'));
        if (! $order) {
            return $this->error('late_payment_order_not_found', 404);
        }

        $proofPath = null;
        try {
            if ($request->hasFile('proof_image')) {
                $proofPath = 'late_payment/'.Helpers::upload('late_payment/', 'png', $request->file('proof_image'));
            }
            $case = $this->service->report(
                $order,
                (string) $request->input('channel'),
                $request->integer('method_id'),
                (string) ($request->input('wallet_type') ?: MerchantDirectPaymentLateCasePolicy::WALLET_SELF_CUSTODY),
                (string) $request->input('payment_reference'),
                $request->input('address_credential_token'),
                $isGuest ? 'guest' : 'customer',
                $ownerId,
                $proofPath
            );
            if ($proofPath !== null && $case->refund_proof_image !== $proofPath) {
                Storage::disk('public')->delete($proofPath);
            }

            return response()->json(['data' => $this->service->customerProjection($case)], 201);
        } catch (InvalidArgumentException|\DomainException $exception) {
            if ($proofPath !== null) {
                Storage::disk('public')->delete($proofPath);
            }

            return $this->error($exception->getMessage(), 422);
        }
    }

    public function lookup(Request $request, int $orderId)
    {
        $validator = Validator::make($request->all(), [
            'guest_id' => $request->user ? 'nullable' : 'required|integer|min:1',
            'contact_number' => $request->user ? 'nullable' : 'required|string|max:40',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        $order = $this->ownedOrder($request, $orderId);
        if (! $order) {
            return $this->error('late_payment_order_not_found', 404);
        }
        $case = MerchantDirectPaymentLateCase::where('order_id', $order->id)
            ->where('source_domain', MerchantDirectPaymentLateCaseService::SOURCE)
            ->first();

        return response()->json([
            'data' => $case ? $this->service->customerProjection($case) : null,
            'feature_enabled' => MerchantDirectPaymentLateCaseService::enabled(),
        ]);
    }

    public function show(Request $request, string $caseId)
    {
        $case = $this->ownedCase($request, $caseId);
        if (! $case) {
            return $this->error('late_payment_case_not_found', 404);
        }

        return response()->json(['data' => $this->service->customerProjection($case)]);
    }

    public function dispute(Request $request, string $caseId)
    {
        $validator = Validator::make($request->all(), [
            'guest_id' => $request->user ? 'nullable' : 'required|integer|min:1',
            'contact_number' => $request->user ? 'nullable' : 'required|string|max:40',
            'reason' => 'required|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        $case = $this->ownedCase($request, $caseId);
        if (! $case) {
            return $this->error('late_payment_case_not_found', 404);
        }
        $ownerId = (int) ($request->user?->id ?? $request->input('guest_id'));
        try {
            $case = $this->service->disputeClosedRefund(
                $case,
                $request->user ? 'customer' : 'guest',
                $ownerId,
                (string) $request->input('reason')
            );

            return response()->json(['data' => $this->service->customerProjection($case)]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    private function ownedCase(Request $request, string $caseId): ?MerchantDirectPaymentLateCase
    {
        $ownerId = (int) ($request->user?->id ?? $request->input('guest_id'));

        $query = MerchantDirectPaymentLateCase::where('case_public_id', $caseId)
            ->where('source_domain', MerchantDirectPaymentLateCaseService::SOURCE)
            ->when(
                $request->user,
                fn ($query) => $query->where('user_id', $ownerId)->whereNull('guest_id'),
                fn ($query) => $query->where('guest_id', (string) $ownerId)->whereNull('user_id')
            );
        if (! $request->user) {
            $contact = $this->normalizedContact((string) $request->input('contact_number'));
            $query->whereHas('order', fn ($order) => $order->whereJsonContains(
                'delivery_address->contact_person_number',
                $contact
            ));
        }

        return $query->first();
    }

    private function ownedOrder(Request $request, int $orderId): ?Order
    {
        $ownerId = (int) ($request->user?->id ?? $request->input('guest_id'));
        $query = Order::whereKey($orderId)
            ->where('user_id', $ownerId)
            ->where('is_guest', $request->user ? 0 : 1);
        if (! $request->user) {
            $query->whereJsonContains(
                'delivery_address->contact_person_number',
                $this->normalizedContact((string) $request->input('contact_number'))
            );
        }

        return $query->first();
    }

    private function normalizedContact(string $contact): string
    {
        $contact = trim($contact);

        return $contact !== '' && ! str_starts_with($contact, '+') ? '+'.$contact : $contact;
    }

    private function error(string $code, int $status)
    {
        return response()->json(['errors' => [['code' => $code, 'message' => $code]]], $status);
    }
}
