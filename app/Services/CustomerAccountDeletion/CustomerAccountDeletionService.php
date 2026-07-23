<?php

namespace App\Services\CustomerAccountDeletion;

use App\CentralLogics\Helpers;
use App\Exceptions\AccountDeletionException;
use App\Mail\CustomerAccountDeletionCompletedMail;
use App\Models\CustomerAccountDeletionEvent;
use App\Models\CustomerAccountDeletionNotice;
use App\Models\CustomerAccountDeletionState;
use App\Models\NezhaPaymentAddressCredential;
use App\Models\NezhaRefundRecord;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerAccountDeletionService
{
    public const COPY_CHECKOUT = 'checkout-auto-delete-v6';

    public const PURGE_MATRIX = 'customer-account-purge-v5-20260723';

    public const BLOCK_ONGOING_ORDER = 1;

    public const BLOCK_ACTIVE_SUBSCRIPTION = 2;

    public const BLOCK_UNRESOLVED_REFUND = 4;

    public const BLOCK_PAYMENT_REVIEW = 8;

    public const BLOCK_CASH_WALLET_BALANCE = 16;

    public const BLOCK_DELIVERY_APPEAL = 32;

    public const BLOCK_TRANSACTION_SUPPORT_TICKET = 64;

    public const BLOCK_LEGAL_HOLD = 128;

    public const ACTIVE_STATUSES = [
        'waiting_obligations',
        'ready_for_countdown',
        'countdown',
        'legal_hold',
        'executing',
        'purging',
        'failed_retryable',
    ];

    public const CANCELLABLE_STATUSES = [
        'waiting_obligations',
        'ready_for_countdown',
        'countdown',
        'legal_hold',
        'failed_retryable',
    ];

    public const TERMINAL_ORDER_STATUSES = [
        'delivered',
        'canceled',
        'failed',
        'refunded',
        'refund_request_canceled',
    ];

    public function directFlag(string $key): bool
    {
        if (! Schema::hasTable('business_settings')) {
            return false;
        }

        return (string) DB::table('business_settings')->where('key', $key)->value('value') === '1';
    }

    public function assertValidCheckoutRequest(User $user, bool $requested, ?string $copyVersion): void
    {
        if (! $requested) {
            return;
        }
        if (! $this->directFlag('nezha_account_deletion_intake_enabled')) {
            throw new AccountDeletionException(
                'ACCOUNT_DELETION_TEMPORARILY_UNAVAILABLE',
                '自动注销暂未开放，请关闭该选项后继续下单。',
                503
            );
        }
        if ($copyVersion !== self::COPY_CHECKOUT) {
            throw new AccountDeletionException(
                'ACCOUNT_DELETION_TEMPORARILY_UNAVAILABLE',
                '页面版本已更新，请刷新后重试。',
                409
            );
        }
        $email = trim((string) $user->getRawOriginal('email'));
        if ((int) $user->is_email_verified !== 1 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AccountDeletionException(
                'ACCOUNT_DELETION_NO_NOTICE_CHANNEL',
                '请先在“我的资料”中补充并验证邮箱。注销完成通知必须送达已验证邮箱。',
                422
            );
        }
    }

    public function lockForOrder(?User $user, bool $requested): ?CustomerAccountDeletionState
    {
        if (! Schema::hasTable('customer_account_deletion_states')) {
            if ($requested) {
                throw new AccountDeletionException(
                    'ACCOUNT_DELETION_TEMPORARILY_UNAVAILABLE',
                    '自动注销暂时不可用，请关闭该选项后继续下单。',
                    503
                );
            }

            return null;
        }
        if (! $user) {
            if ($requested) {
                throw new AccountDeletionException(
                    'ACCOUNT_DELETION_LOGIN_REQUIRED',
                    '游客不能预约自动注销，请先登录。',
                    422
                );
            }

            return null;
        }

        if ($requested) {
            $state = $this->lockOrCreateState((int) $user->id);
        } else {
            $existing = CustomerAccountDeletionState::query()->where('user_id', $user->id)->first();
            if (! $existing) {
                return null;
            }
            $state = CustomerAccountDeletionState::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
        }

        $this->assertStateAllowsOrder($state);

        return $state;
    }

    public function finalizeCreatedOrder(
        ?User $user,
        Order $order,
        ?CustomerAccountDeletionState $state,
        bool $requested,
        ?string $copyVersion = null,
        string $locale = 'zh-CN'
    ): ?CustomerAccountDeletionState {
        if (! $user || ! Schema::hasTable('customer_account_deletion_states')) {
            return null;
        }

        $state = $state
            ? CustomerAccountDeletionState::query()->whereKey($state->id)->lockForUpdate()->first()
            : CustomerAccountDeletionState::query()->where('user_id', $user->id)->lockForUpdate()->first();
        if (! $state && $requested) {
            $state = $this->lockOrCreateState((int) $user->id);
        }
        if (! $state) {
            return null;
        }

        $this->assertStateAllowsOrder($state);
        if ($requested) {
            return $this->attachCheckoutRequest(
                $user,
                $order,
                $state,
                (string) $copyVersion,
                $locale
            );
        }
        if (in_array($state->status, self::CANCELLABLE_STATUSES, true)) {
            $epoch = (int) $state->obligation_epoch + 1;
            $state->forceFill([
                'status' => 'waiting_obligations',
                'blocker_mask' => ((int) $state->blocker_mask) | self::BLOCK_ONGOING_ORDER,
                'obligation_epoch' => $epoch,
                'state_version' => $state->state_version + 1,
                'source_order_id' => $order->id,
                'countdown_started_at' => null,
                'scheduled_for' => null,
                'execution_owner_token' => null,
                'obligation_epoch_at_claim' => null,
                'challenge_hash' => null,
                'challenge_expires_at' => null,
                'challenge_auth_context' => null,
            ])->save();
            $this->event(
                $state,
                'obligation_created_by_order',
                'obligation-created-by-order:'.$state->request_id.':'.$order->id,
                ['source_order_id' => $order->id]
            );
        }

        return $state->fresh();
    }

    public function attachCheckoutRequest(
        User $user,
        Order $order,
        CustomerAccountDeletionState $state,
        string $copyVersion,
        string $locale
    ): CustomerAccountDeletionState {
        $dedupe = 'checkout-request:'.$order->id;
        if (CustomerAccountDeletionEvent::query()->where('dedupe_key', $dedupe)->exists()) {
            return $state->fresh();
        }

        $this->assertStateAllowsOrder($state);
        if ($state->request_id && in_array($state->status, self::CANCELLABLE_STATUSES, true)) {
            $mask = $this->calculateBlockerMask((int) $user->id, $state) | self::BLOCK_ONGOING_ORDER;
            $state->forceFill([
                'source_order_id' => $order->id,
                'status' => 'waiting_obligations',
                'blocker_mask' => $mask,
                'obligation_epoch' => $state->obligation_epoch + 1,
                'state_version' => $state->state_version + 1,
                'purge_matrix_version' => self::PURGE_MATRIX,
                'copy_version' => $copyVersion,
                'copy_locale' => substr($locale ?: 'zh-CN', 0, 16),
                'countdown_started_at' => null,
                'scheduled_for' => null,
                'sessions_revoke_requested_at' => null,
                'sessions_revoked_at' => null,
                'execution_owner_token' => null,
                'obligation_epoch_at_claim' => null,
                'next_retry_at' => null,
                'failure_code' => null,
            ])->save();
            $this->ensureNotice($state, $user);
            $this->event($state, 'request_reaffirmed_by_order', $dedupe, ['source_order_id' => $order->id]);

            return $state->fresh();
        }
        $requestId = Str::uuid()->toString();
        $mask = $this->calculateBlockerMask((int) $user->id, $state) | self::BLOCK_ONGOING_ORDER;
        $state->forceFill([
            'request_id' => $requestId,
            'source_order_id' => $order->id,
            'source' => 'checkout',
            'status' => 'waiting_obligations',
            'blocker_mask' => $mask,
            'obligation_epoch' => $state->obligation_epoch + 1,
            'state_version' => $state->state_version + 1,
            'purge_matrix_version' => self::PURGE_MATRIX,
            'copy_version' => $copyVersion,
            'copy_locale' => substr($locale ?: 'zh-CN', 0, 16),
            'requested_at' => now(),
            'last_blocker_cleared_at' => null,
            'countdown_started_at' => null,
            'scheduled_for' => null,
            'sessions_revoke_requested_at' => null,
            'sessions_revoked_at' => null,
            'cancelled_at' => null,
            'execution_started_at' => null,
            'account_closed_at' => null,
            'purge_completed_at' => null,
            'obligation_epoch_at_claim' => null,
            'execution_owner_token' => null,
            'attempt_count' => 0,
            'next_retry_at' => null,
            'failure_code' => null,
            'challenge_hash' => null,
            'challenge_expires_at' => null,
            'challenge_auth_context' => null,
        ])->save();
        $this->ensureNotice($state, $user);
        $this->event($state, 'request_accepted', $dedupe, ['source' => 'checkout']);

        return $state->fresh();
    }

    public function currentForUser(int $userId): ?CustomerAccountDeletionState
    {
        if (! Schema::hasTable('customer_account_deletion_states')) {
            return null;
        }

        return CustomerAccountDeletionState::query()->where('user_id', $userId)->first();
    }

    public function projection(?CustomerAccountDeletionState $state): array
    {
        if (! $state || ! $state->request_id) {
            return ['active' => false, 'status' => 'none'];
        }

        return [
            'active' => in_array($state->status, self::ACTIVE_STATUSES, true),
            'request_id' => $state->request_id,
            'status' => $state->status,
            'blockers' => $this->blockerLabels((int) $state->blocker_mask),
            'requested_at' => $state->requested_at?->toIso8601String(),
            'countdown_started_at' => $state->countdown_started_at?->toIso8601String(),
            'scheduled_for' => $state->scheduled_for?->toIso8601String(),
            'notification_channel' => 'verified_email',
            'can_cancel' => ! $state->account_closed_at
                && in_array($state->status, self::CANCELLABLE_STATUSES, true),
            'formal_request' => [
                'email' => 'support@nezha.am',
                'privacy_policy_path' => '/privacy-policy',
                'product_flow_only' => true,
            ],
        ];
    }

    public function cancelForUser(User $user, string $source = 'customer'): CustomerAccountDeletionState
    {
        return DB::transaction(function () use ($user, $source) {
            $state = CustomerAccountDeletionState::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $state || ! $state->request_id || ! in_array($state->status, self::ACTIVE_STATUSES, true)) {
                throw new AccountDeletionException('ACCOUNT_DELETION_ACTIVE', '当前没有可取消的注销预约。', 404);
            }
            if ($state->account_closed_at || ! in_array($state->status, self::CANCELLABLE_STATUSES, true)) {
                throw new AccountDeletionException(
                    'ACCOUNT_DELETION_EXECUTING',
                    '账号注销已进入不可撤销阶段，请联系平台客服。',
                    409
                );
            }

            $state->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'scheduled_for' => null,
                'execution_owner_token' => null,
                'challenge_hash' => null,
                'challenge_expires_at' => null,
                'challenge_auth_context' => null,
                'blocker_mask' => 0,
                'state_version' => $state->state_version + 1,
            ])->save();
            $this->cancelNotice((string) $state->request_id);
            $this->event($state, 'request_cancelled', 'request-cancelled:'.$state->request_id, ['source' => $source]);

            return $state->fresh();
        }, 5);
    }

    public function assertContactChangeAllowed(User $user): void
    {
        $state = $this->currentForUser((int) $user->id);
        if ($state && in_array($state->status, self::ACTIVE_STATUSES, true)) {
            throw new AccountDeletionException(
                'ACCOUNT_DELETION_CANCEL_BEFORE_CONTACT_CHANGE',
                '自动注销期间不能修改手机号或邮箱，请先取消注销。',
                409,
                $this->projection($state)
            );
        }
    }

    public function withContactChangeGuard(User $user, callable $write): mixed
    {
        if (! Schema::hasTable('customer_account_deletion_states')) {
            return $write();
        }

        return DB::transaction(function () use ($user, $write) {
            $state = $this->lockOrCreateState((int) $user->id);
            if ($state->status === 'completed') {
                throw new AccountDeletionException('ACCOUNT_DELETION_EXECUTING', '该账号已注销，不能修改联系方式。', 409);
            }
            if (in_array($state->status, self::ACTIVE_STATUSES, true)) {
                throw new AccountDeletionException(
                    'ACCOUNT_DELETION_CANCEL_BEFORE_CONTACT_CHANGE',
                    '自动注销期间不能修改手机号或邮箱，请先取消注销。',
                    409,
                    $this->projection($state)
                );
            }

            return $write();
        }, 5);
    }

    public function issueLoginChallenge(User $user, string $authContext): ?array
    {
        if (! Schema::hasTable('customer_account_deletion_states')) {
            return null;
        }

        return DB::transaction(function () use ($user, $authContext) {
            $state = CustomerAccountDeletionState::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $state) {
                return null;
            }
            if ($state->status === 'completed' || $state->account_closed_at) {
                throw new AccountDeletionException('ACCOUNT_DELETION_EXECUTING', '该账号已注销，已禁止登录。', 409);
            }
            if (! in_array($state->status, self::CANCELLABLE_STATUSES, true)) {
                if (in_array($state->status, ['executing', 'purging'], true)) {
                    throw new AccountDeletionException('ACCOUNT_DELETION_EXECUTING', '账号注销正在执行，已禁止登录。', 409);
                }

                return null;
            }

            $raw = Str::random(64);
            $state->forceFill([
                'challenge_hash' => hash('sha256', $raw),
                'challenge_expires_at' => now()->addMinutes(10),
                'challenge_auth_context' => $authContext,
            ])->save();
            $this->event($state, 'login_challenge_issued', 'challenge-issued:'.Str::uuid());

            return [
                'required' => true,
                'request_id' => $state->request_id,
                'challenge' => $raw,
                'expires_in' => 600,
                'status' => $state->status,
                'actions' => ['cancel_and_login', 'keep_and_exit'],
            ];
        }, 5);
    }

    public function resolveLoginChallenge(string $requestId, string $raw, string $authContext, bool $cancel): User
    {
        return DB::transaction(function () use ($requestId, $raw, $authContext, $cancel) {
            $state = CustomerAccountDeletionState::query()->where('request_id', $requestId)->lockForUpdate()->first();
            if (! $state || ! $state->challenge_hash) {
                throw new AccountDeletionException('ACCOUNT_DELETION_CHALLENGE_REPLAYED', '登录确认已使用，请重新登录。', 409);
            }
            if (! $state->challenge_expires_at || $state->challenge_expires_at->isPast()) {
                $state->forceFill(['challenge_hash' => null, 'challenge_expires_at' => null, 'challenge_auth_context' => null])->save();
                throw new AccountDeletionException('ACCOUNT_DELETION_CHALLENGE_EXPIRED', '登录确认已过期，请重新登录。', 409);
            }
            if (! hash_equals((string) $state->challenge_hash, hash('sha256', $raw))
                || ! hash_equals((string) $state->challenge_auth_context, $authContext)) {
                throw new AccountDeletionException('ACCOUNT_DELETION_CHALLENGE_REPLAYED', '登录确认无效，请重新登录。', 409);
            }

            $state->forceFill(['challenge_hash' => null, 'challenge_expires_at' => null, 'challenge_auth_context' => null])->save();
            if (! $cancel) {
                $this->event($state, 'login_challenge_kept', 'challenge-kept:'.Str::uuid());

                return User::query()->findOrFail($state->user_id);
            }
            if ($state->account_closed_at || ! in_array($state->status, self::CANCELLABLE_STATUSES, true)) {
                throw new AccountDeletionException('ACCOUNT_DELETION_EXECUTING', '账号注销已进入不可撤销阶段。', 409);
            }

            $state->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'scheduled_for' => null,
                'blocker_mask' => 0,
                'state_version' => $state->state_version + 1,
            ])->save();
            $this->cancelNotice((string) $state->request_id);
            $this->event($state, 'request_cancelled', 'request-cancelled-login:'.$state->request_id, ['source' => 'login_challenge']);

            return User::query()->findOrFail($state->user_id);
        }, 5);
    }

    public function noteNewObligation(int $userId, int $bit, callable $businessWrite): mixed
    {
        if (! Schema::hasTable('customer_account_deletion_states')) {
            return $businessWrite();
        }

        return DB::transaction(function () use ($userId, $bit, $businessWrite) {
            $state = CustomerAccountDeletionState::query()->where('user_id', $userId)->lockForUpdate()->first();
            if (! $state || ! in_array($state->status, self::ACTIVE_STATUSES, true)) {
                return $businessWrite();
            }

            $result = $businessWrite();
            if ($result === false) {
                return false;
            }
            $epoch = $state->obligation_epoch + 1;
            $state->forceFill([
                'status' => 'waiting_obligations',
                'blocker_mask' => ((int) $state->blocker_mask) | $bit,
                'scheduled_for' => null,
                'countdown_started_at' => null,
                'execution_owner_token' => null,
                'obligation_epoch_at_claim' => null,
                'obligation_epoch' => $epoch,
                'state_version' => $state->state_version + 1,
            ])->save();
            $this->event($state, 'obligation_created', 'obligation:'.$state->request_id.':'.$epoch, ['blocker_bit' => $bit]);

            return $result;
        }, 5);
    }

    public function reconcileActive(int $limit = 100): int
    {
        if (! Schema::hasTable('customer_account_deletion_states')) {
            return 0;
        }
        $ids = CustomerAccountDeletionState::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('request_id')
            ->orderBy('requested_at')
            ->limit($limit)
            ->pluck('request_id');
        foreach ($ids as $id) {
            $this->reconcileOne((string) $id);
        }

        return $ids->count();
    }

    public function reconcileOne(string $requestId): void
    {
        $snapshot = CustomerAccountDeletionState::query()->where('request_id', $requestId)->first();
        if (! $snapshot || ! in_array($snapshot->status, self::ACTIVE_STATUSES, true)) {
            return;
        }
        $mask = $this->calculateBlockerMask((int) $snapshot->user_id, $snapshot);

        DB::transaction(function () use ($requestId, $mask) {
            $state = CustomerAccountDeletionState::query()->where('request_id', $requestId)->lockForUpdate()->first();
            if (! $state || ! in_array($state->status, self::ACTIVE_STATUSES, true)) {
                return;
            }
            $oldMask = (int) $state->blocker_mask;
            $state->blocker_mask = $mask;

            if ($mask !== 0) {
                if (in_array($state->status, ['countdown', 'executing', 'purging'], true)) {
                    $state->status = ($mask & self::BLOCK_LEGAL_HOLD) ? 'legal_hold' : 'waiting_obligations';
                    $state->scheduled_for = null;
                    $state->countdown_started_at = null;
                    $state->execution_owner_token = null;
                    $state->obligation_epoch_at_claim = null;
                    $state->obligation_epoch++;
                    $this->event($state, 'countdown_paused', 'countdown-paused:'.$state->request_id.':'.$state->obligation_epoch, ['blocker_mask' => $mask]);
                }
            } elseif (in_array($state->status, ['waiting_obligations', 'ready_for_countdown', 'legal_hold'], true)) {
                $state->last_blocker_cleared_at = now();
                if ($this->directFlag('nezha_account_deletion_countdown_enabled')) {
                    $state->status = 'countdown';
                    $state->countdown_started_at = now();
                    $state->scheduled_for = now()->addHours(72);
                    $state->sessions_revoke_requested_at = now();
                    $this->event($state, 'sessions_revoke_requested', 'sessions-revoke:'.$state->request_id.':'.$state->obligation_epoch);
                    $this->event($state, 'countdown_started', 'countdown-started:'.$state->request_id.':'.$state->obligation_epoch, ['hours' => 72]);
                } else {
                    $state->status = 'ready_for_countdown';
                    $state->countdown_started_at = null;
                    $state->scheduled_for = null;
                    $state->sessions_revoke_requested_at = null;
                    $this->event($state, 'countdown_waiting_for_gate', 'countdown-waiting-for-gate:'.$state->request_id.':'.$state->obligation_epoch);
                }
            }
            if ($oldMask !== $mask) {
                $state->state_version++;
            }
            $state->save();
        }, 5);
    }

    public function revokePendingSessions(int $limit = 100): int
    {
        if (! Schema::hasTable('customer_account_deletion_states')) {
            return 0;
        }
        $rows = CustomerAccountDeletionState::query()
            ->whereNotNull('sessions_revoke_requested_at')
            ->whereNull('sessions_revoked_at')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $this->revokeTokensForUser((int) $row->user_id);
            DB::transaction(function () use ($row) {
                $state = CustomerAccountDeletionState::query()->whereKey($row->id)->lockForUpdate()->first();
                if (! $state || $state->request_id !== $row->request_id || $state->sessions_revoked_at) {
                    return;
                }
                DB::table('users')->where('id', $state->user_id)->update(['cm_firebase_token' => null]);
                $state->sessions_revoked_at = now();
                $state->save();
                $this->event($state, 'sessions_revoked', 'sessions-revoked:'.$state->request_id);
            }, 5);
        }

        return $rows->count();
    }

    public function executeDue(int $limit = 25): int
    {
        if (! Schema::hasTable('customer_account_deletion_states')
            || ! $this->directFlag('nezha_account_deletion_execution_enabled')
            || ! $this->directFlag('nezha_account_deletion_purge_enabled')) {
            return 0;
        }

        $done = 0;
        $resumable = CustomerAccountDeletionState::query()
            ->whereIn('status', ['executing', 'purging'])
            ->whereNotNull('execution_owner_token')
            ->orderBy('execution_started_at')
            ->limit($limit)
            ->get(['request_id', 'execution_owner_token']);
        foreach ($resumable as $state) {
            $this->runExecutionSafely((string) $state->request_id, (string) $state->execution_owner_token);
            $done++;
        }

        $remaining = max(0, $limit - $done);
        if ($remaining === 0) {
            return $done;
        }

        $retryIds = CustomerAccountDeletionState::query()
            ->where('status', 'failed_retryable')
            ->whereNotNull('sessions_revoked_at')
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('next_retry_at')
            ->limit($remaining)
            ->pluck('request_id');
        foreach ($retryIds as $id) {
            $owner = $this->claimExecution((string) $id, 'failed_retryable');
            if ($owner) {
                $this->runExecutionSafely((string) $id, $owner);
                $done++;
            }
        }

        $remaining = max(0, $limit - $done);
        if ($remaining === 0) {
            return $done;
        }

        $ids = CustomerAccountDeletionState::query()
            ->where('status', 'countdown')
            ->whereNotNull('sessions_revoked_at')
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->limit($remaining)
            ->pluck('request_id');
        foreach ($ids as $id) {
            $owner = $this->claimExecution((string) $id, 'countdown');
            if ($owner) {
                $this->runExecutionSafely((string) $id, $owner);
                $done++;
            }
        }

        return $done;
    }

    public function deliverPendingNotices(int $limit = 100): int
    {
        if (! Schema::hasTable('customer_account_deletion_notices')) {
            return 0;
        }

        CustomerAccountDeletionNotice::query()
            ->where('status', 'sending')
            ->where('claimed_at', '<=', now()->subMinutes(10))
            ->update([
                'status' => 'failed_retryable',
                'owner_token' => null,
                'next_retry_at' => now(),
                'last_error_code' => 'STALE_CLAIM',
                'updated_at' => now(),
            ]);

        $ids = CustomerAccountDeletionNotice::query()
            ->whereIn('status', ['pending_send', 'failed_retryable'])
            ->where(fn ($query) => $query->whereNull('send_due_at')->orWhere('send_due_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('legal_due_at')
            ->limit($limit)
            ->pluck('id');

        $sent = 0;
        foreach ($ids as $id) {
            $owner = Str::uuid()->toString();
            $notice = DB::transaction(function () use ($id, $owner) {
                $row = CustomerAccountDeletionNotice::query()->whereKey($id)->lockForUpdate()->first();
                if (! $row || ! in_array($row->status, ['pending_send', 'failed_retryable'], true)
                    || ($row->next_retry_at && $row->next_retry_at->isFuture())) {
                    return null;
                }
                $row->forceFill([
                    'status' => 'sending',
                    'claimed_at' => now(),
                    'owner_token' => $owner,
                ])->save();

                return $row->fresh();
            }, 5);
            if (! $notice) {
                continue;
            }

            try {
                $recipient = Crypt::decryptString((string) $notice->recipient_ciphertext);
                if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('Invalid completion-notice email');
                }
                Mail::to($recipient)->send(new CustomerAccountDeletionCompletedMail(
                    $notice->purge_completed_at?->toIso8601String() ?? now()->toIso8601String()
                ));
                DB::transaction(function () use ($notice, $owner) {
                    $row = CustomerAccountDeletionNotice::query()->whereKey($notice->id)->lockForUpdate()->first();
                    if (! $row || $row->status !== 'sending' || $row->owner_token !== $owner) {
                        return;
                    }
                    $row->forceFill([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'recipient_ciphertext' => null,
                        'recipient_cleared_at' => now(),
                        'owner_token' => null,
                        'claimed_at' => null,
                        'next_retry_at' => null,
                        'last_error_code' => null,
                    ])->save();
                }, 5);
                $sent++;
            } catch (\Throwable $e) {
                DB::transaction(function () use ($notice, $owner, $e) {
                    $row = CustomerAccountDeletionNotice::query()->whereKey($notice->id)->lockForUpdate()->first();
                    if (! $row || $row->status !== 'sending' || $row->owner_token !== $owner) {
                        return;
                    }
                    $attempt = (int) $row->attempt_count + 1;
                    $row->forceFill([
                        'status' => 'failed_retryable',
                        'attempt_count' => $attempt,
                        'next_retry_at' => now()->addMinutes(min(360, 2 ** min($attempt, 8))),
                        'owner_token' => null,
                        'claimed_at' => null,
                        'last_error_code' => class_basename($e),
                    ])->save();
                }, 5);
                Log::error('customer account deletion completion notice failed', [
                    'notice_id' => $notice->id,
                    'request_id' => $notice->request_id,
                    'exception' => $e::class,
                ]);
            }
        }

        $overdue = CustomerAccountDeletionNotice::query()
            ->whereNotIn('status', ['sent', 'cancelled'])
            ->whereNotNull('legal_due_at')
            ->where('legal_due_at', '<', now())
            ->count();
        if ($overdue > 0) {
            Log::critical('customer account deletion completion notices overdue', ['count' => $overdue]);
        }

        return $sent;
    }

    public function replayCompleted(bool $dryRun = true, int $limit = 500): int
    {
        if (! Schema::hasTable('customer_account_deletion_states')) {
            return 0;
        }
        $rows = CustomerAccountDeletionState::query()
            ->where('status', 'completed')
            ->orderBy('purge_completed_at')
            ->limit($limit)
            ->get();
        $needsReplay = $rows->filter(function (CustomerAccountDeletionState $state) {
            $user = User::query()->without('storage')->find($state->user_id);
            if (! $user) {
                return false;
            }

            return (bool) $user->status
                || $user->email !== null
                || $user->phone !== null
                || $user->f_name !== null
                || (Schema::hasTable('customer_addresses') && DB::table('customer_addresses')->where('user_id', $user->id)->exists())
                || (Schema::hasTable('carts') && DB::table('carts')->where('user_id', $user->id)->exists());
        });

        if ($dryRun || $needsReplay->isEmpty()) {
            return $needsReplay->count();
        }
        if (! $this->directFlag('nezha_account_deletion_execution_enabled')
            || ! $this->directFlag('nezha_account_deletion_purge_enabled')) {
            throw new AccountDeletionException(
                'ACCOUNT_DELETION_TEMPORARILY_UNAVAILABLE',
                '恢复重放需要 execution 与 purge 两个数据库开关同时开启。',
                503
            );
        }

        foreach ($needsReplay as $snapshot) {
            DB::transaction(function () use ($snapshot) {
                $state = CustomerAccountDeletionState::query()->whereKey($snapshot->id)->lockForUpdate()->first();
                if (! $state || $state->status !== 'completed' || $this->calculateBlockerMask((int) $state->user_id, $state) !== 0) {
                    return;
                }
                $userId = (int) $state->user_id;
                $this->revokeTokensForUser($userId);
                $this->clearOrderPii($userId);
                $this->deleteConversations($userId);
                $this->deleteCustomerData($userId);
                $this->anonymizeUser($userId);
                $this->event($state, 'restore_replay_completed', 'restore-replay:'.$state->request_id.':'.now()->format('YmdH'));
            }, 5);
        }

        return $needsReplay->count();
    }

    public function calculateBlockerMask(int $userId, ?CustomerAccountDeletionState $state = null): int
    {
        $mask = 0;
        if (Order::query()->where('user_id', $userId)->where('is_guest', 0)->whereNotIn('order_status', self::TERMINAL_ORDER_STATUSES)->exists()) {
            $mask |= self::BLOCK_ONGOING_ORDER;
        }
        if (Schema::hasTable('subscriptions') && DB::table('subscriptions')->where('user_id', $userId)->where('status', 'active')->exists()) {
            $mask |= self::BLOCK_ACTIVE_SUBSCRIPTION;
        }
        if (Order::query()->where('user_id', $userId)->where('is_guest', 0)->where('order_status', 'refund_requested')->exists()) {
            $mask |= self::BLOCK_UNRESOLVED_REFUND;
        }
        if (Schema::hasTable('nezha_refund_records')
            && DB::table('nezha_refund_records')->where('user_id', $userId)->whereIn('status', NezhaRefundRecord::STATUS_UNRESOLVED)->exists()) {
            $mask |= self::BLOCK_UNRESOLVED_REFUND;
        }
        if (Schema::hasTable('offline_payments')
            && DB::table('offline_payments')->join('orders', 'orders.id', '=', 'offline_payments.order_id')
                ->where('orders.user_id', $userId)->where('orders.is_guest', 0)->where('offline_payments.status', 'pending')->exists()) {
            $mask |= self::BLOCK_PAYMENT_REVIEW;
        }
        $wallet = (float) (User::query()->whereKey($userId)->value('wallet_balance') ?? 0);
        if (abs($wallet) > 0.000001) {
            $mask |= self::BLOCK_CASH_WALLET_BALANCE;
        }
        if (Schema::hasTable('nezha_delivery_appeals')
            && DB::table('nezha_delivery_appeals')->where('user_id', $userId)->whereIn('status', ['open', 'merchant_contacted'])->exists()) {
            $mask |= self::BLOCK_DELIVERY_APPEAL;
        }
        if (Schema::hasTable('nezha_cs_tickets')
            && DB::table('nezha_cs_tickets')->where('user_id', $userId)->where('status', 'open')->exists()) {
            $mask |= self::BLOCK_TRANSACTION_SUPPORT_TICKET;
        }
        if (($state?->legal_hold_scope && (! $state->legal_hold_expires_at || $state->legal_hold_expires_at->isFuture()))
            || (Schema::hasTable('local_life_posts') && Schema::hasColumn('local_life_posts', 'legal_hold')
                && DB::table('local_life_posts')->where('user_id', $userId)->where('legal_hold', 1)->exists())) {
            $mask |= self::BLOCK_LEGAL_HOLD;
        }

        return $mask;
    }

    public function blockerLabels(int $mask): array
    {
        $map = [
            self::BLOCK_ONGOING_ORDER => 'orders',
            self::BLOCK_ACTIVE_SUBSCRIPTION => 'subscriptions',
            self::BLOCK_UNRESOLVED_REFUND => 'refunds_or_payment',
            self::BLOCK_PAYMENT_REVIEW => 'payment_review',
            self::BLOCK_CASH_WALLET_BALANCE => 'cash_balance',
            self::BLOCK_DELIVERY_APPEAL => 'delivery_appeal',
            self::BLOCK_TRANSACTION_SUPPORT_TICKET => 'transaction_support',
            self::BLOCK_LEGAL_HOLD => 'legal_hold',
        ];
        $labels = [];
        foreach ($map as $bit => $label) {
            if (($mask & $bit) !== 0) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function claimExecution(string $requestId, string $expectedStatus): ?string
    {
        $owner = Str::uuid()->toString();

        return DB::transaction(function () use ($requestId, $expectedStatus, $owner) {
            $state = CustomerAccountDeletionState::query()->where('request_id', $requestId)->lockForUpdate()->first();
            if (! $state || $state->status !== $expectedStatus || ! $state->sessions_revoked_at
                || ($expectedStatus === 'countdown' && $state->scheduled_for?->isFuture())
                || ($expectedStatus === 'failed_retryable' && $state->next_retry_at?->isFuture())
                || $this->calculateBlockerMask((int) $state->user_id, $state) !== 0) {
                return null;
            }

            $state->forceFill([
                'status' => $state->account_closed_at ? 'purging' : 'executing',
                'execution_started_at' => $state->execution_started_at ?: now(),
                'execution_owner_token' => $owner,
                'obligation_epoch_at_claim' => $state->obligation_epoch,
                'next_retry_at' => null,
            ])->save();
            $this->event($state, 'execution_claimed', 'execution-claim:'.$state->request_id.':'.$state->obligation_epoch.':'.$state->attempt_count);

            return $owner;
        }, 5);
    }

    private function runOwnedExecution(string $requestId, string $owner): void
    {
        $this->ownedStep($requestId, $owner, 'close-account', function (int $userId, CustomerAccountDeletionState $state) {
            $this->revokeTokensForUser($userId);
            DB::table('users')->where('id', $userId)->update([
                'status' => 0,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'cm_firebase_token' => null,
                'notification_preferences' => null,
                'updated_at' => now(),
            ]);
            if (Schema::hasTable('user_external_identities')) {
                DB::table('user_external_identities')->where('user_id', $userId)->delete();
            }
            $state->account_closed_at = now();
            $state->status = 'purging';
        });

        $this->ownedStep($requestId, $owner, 'clear-order-pii', fn (int $userId) => $this->clearOrderPii($userId));
        $this->ownedStep($requestId, $owner, 'delete-conversations', fn (int $userId) => $this->deleteConversations($userId));
        $this->ownedStep($requestId, $owner, 'delete-customer-data', fn (int $userId) => $this->deleteCustomerData($userId));
        $this->ownedStep($requestId, $owner, 'anonymize-user', fn (int $userId) => $this->anonymizeUser($userId));
        $this->ownedStep($requestId, $owner, 'complete', function (int $userId, CustomerAccountDeletionState $state) {
            $completedAt = now();
            $state->status = 'completed';
            $state->purge_completed_at = $completedAt;
            $state->execution_owner_token = null;
            $state->blocker_mask = 0;
            $state->state_version++;
            CustomerAccountDeletionNotice::query()
                ->where('request_id', $state->request_id)
                ->whereNotIn('status', ['sent', 'cancelled'])
                ->update([
                    'status' => 'pending_send',
                    'purge_completed_at' => $completedAt,
                    'send_due_at' => $completedAt,
                    'legal_due_at' => $completedAt->copy()->addWeekdays(3),
                    'next_retry_at' => null,
                    'last_error_code' => null,
                    'updated_at' => $completedAt,
                ]);
        });
    }

    private function runExecutionSafely(string $requestId, string $owner): void
    {
        try {
            $this->runOwnedExecution($requestId, $owner);
        } catch (\Throwable $e) {
            DB::transaction(function () use ($requestId, $owner, $e) {
                $state = CustomerAccountDeletionState::query()->where('request_id', $requestId)->lockForUpdate()->first();
                if (! $state || $state->execution_owner_token !== $owner
                    || ! in_array($state->status, ['executing', 'purging'], true)) {
                    return;
                }
                $attempt = (int) $state->attempt_count + 1;
                $state->forceFill([
                    'status' => 'failed_retryable',
                    'attempt_count' => $attempt,
                    'next_retry_at' => now()->addMinutes(min(60, 2 ** min($attempt, 5))),
                    'failure_code' => class_basename($e),
                    'execution_owner_token' => null,
                ])->save();
                $this->event($state, 'execution_failed', 'execution-failed:'.$state->request_id.':'.$attempt, [
                    'failure_code' => class_basename($e),
                ]);
            }, 5);
            Log::error('customer account deletion execution failed', [
                'request_id' => $requestId,
                'exception' => $e::class,
            ]);
        }
    }

    private function ownedStep(string $requestId, string $owner, string $step, callable $callback): void
    {
        DB::transaction(function () use ($requestId, $owner, $step, $callback) {
            $state = CustomerAccountDeletionState::query()->where('request_id', $requestId)->lockForUpdate()->first();
            if (! $state || ! in_array($state->status, ['executing', 'purging'], true)
                || $state->execution_owner_token !== $owner
                || (int) $state->obligation_epoch_at_claim !== (int) $state->obligation_epoch
                || ! $this->directFlag('nezha_account_deletion_execution_enabled')
                || ! $this->directFlag('nezha_account_deletion_purge_enabled')) {
                return;
            }
            if ($this->calculateBlockerMask((int) $state->user_id, $state) !== 0) {
                $state->forceFill([
                    'status' => 'waiting_obligations',
                    'execution_owner_token' => null,
                    'scheduled_for' => null,
                    'countdown_started_at' => null,
                    'obligation_epoch' => $state->obligation_epoch + 1,
                    'state_version' => $state->state_version + 1,
                ])->save();

                return;
            }
            $dedupe = 'purge-step-completed:'.$requestId.':'.$step;
            if (CustomerAccountDeletionEvent::query()->where('dedupe_key', $dedupe)->exists()) {
                return;
            }
            $this->event($state, 'purge_step_started', 'purge-step-started:'.$requestId.':'.$step, ['step' => $step]);
            $callback((int) $state->user_id, $state);
            $state->save();
            $this->event($state, 'purge_step_completed', $dedupe, ['step' => $step]);
        }, 5);
    }

    private function lockOrCreateState(int $userId): CustomerAccountDeletionState
    {
        $state = CustomerAccountDeletionState::query()->where('user_id', $userId)->lockForUpdate()->first();
        if ($state) {
            return $state;
        }
        try {
            DB::table('customer_account_deletion_states')->insertOrIgnore([
                'user_id' => $userId,
                'request_id' => null,
                'source_order_id' => null,
                'source' => null,
                'status' => 'open',
                'blocker_mask' => 0,
                'obligation_epoch' => 0,
                'state_version' => 0,
                'purge_matrix_version' => self::PURGE_MATRIX,
                'copy_version' => null,
                'copy_locale' => 'zh-CN',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }

        return CustomerAccountDeletionState::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    private function ensureNotice(CustomerAccountDeletionState $state, User $user): void
    {
        $email = trim((string) $user->getRawOriginal('email'));
        if ((int) $user->is_email_verified !== 1 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AccountDeletionException(
                'ACCOUNT_DELETION_NO_NOTICE_CHANNEL',
                '请先在“我的资料”中补充并验证邮箱。注销完成通知必须送达已验证邮箱。',
                422
            );
        }

        CustomerAccountDeletionNotice::query()->updateOrCreate(
            ['request_id' => $state->request_id],
            [
                'state_id' => $state->id,
                'channel' => 'email',
                'recipient_ciphertext' => Crypt::encryptString($email),
                'locale' => $state->copy_locale ?: 'zh-CN',
                'status' => 'waiting_execution',
                'purge_completed_at' => null,
                'send_due_at' => null,
                'legal_due_at' => null,
                'claimed_at' => null,
                'owner_token' => null,
                'attempt_count' => 0,
                'next_retry_at' => null,
                'sent_at' => null,
                'recipient_cleared_at' => null,
                'last_error_code' => null,
            ]
        );
    }

    private function cancelNotice(string $requestId): void
    {
        CustomerAccountDeletionNotice::query()
            ->where('request_id', $requestId)
            ->whereNotIn('status', ['sent', 'cancelled'])
            ->update([
                'status' => 'cancelled',
                'recipient_ciphertext' => null,
                'recipient_cleared_at' => now(),
                'claimed_at' => null,
                'owner_token' => null,
                'next_retry_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function assertStateAllowsOrder(CustomerAccountDeletionState $state): void
    {
        if ($state->account_closed_at || in_array($state->status, ['executing', 'purging', 'completed'], true)) {
            throw new AccountDeletionException(
                'ACCOUNT_DELETION_EXECUTING',
                '该账号正在注销或已注销，不能继续下单。',
                409,
                $this->projection($state)
            );
        }
    }

    private function revokeTokensForUser(int $userId): void
    {
        if (! Schema::hasTable('oauth_access_tokens')) {
            return;
        }
        $tokenIds = DB::table('oauth_access_tokens')->where('user_id', $userId)->pluck('id');
        if ($tokenIds->isNotEmpty() && Schema::hasTable('oauth_refresh_tokens')) {
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => 1]);
        }
        DB::table('oauth_access_tokens')->where('user_id', $userId)->update(['revoked' => 1]);
    }

    private function clearOrderPii(int $userId): void
    {
        $orderIds = DB::table('orders')->where('user_id', $userId)->where('is_guest', 0)->pluck('id');
        DB::table('orders')->whereIn('id', $orderIds)->update([
            'delivery_address_id' => null,
            'delivery_address' => null,
            'order_note' => null,
            'unavailable_item_note' => null,
            'delivery_instruction' => null,
            'updated_at' => now(),
        ]);

        if ($orderIds->isEmpty()) {
            return;
        }
        if (Schema::hasTable('offline_payments')) {
            DB::table('offline_payments')->whereIn('order_id', $orderIds)->update([
                'payment_info' => null,
                'note' => null,
                'customer_note' => null,
                'method_fields' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('refunds')) {
            $refundImages = DB::table('refunds')->whereIn('order_id', $orderIds)->pluck('image');
            $this->deleteUploadedFiles($refundImages, ['refund/', 'refund-request/']);
            DB::table('refunds')->whereIn('order_id', $orderIds)->update([
                'image' => null,
                'customer_note' => null,
                'admin_note' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('nezha_refund_records')) {
            $proofs = DB::table('nezha_refund_records')->whereIn('order_id', $orderIds)->pluck('refund_proof_image');
            $this->deleteUploadedFiles($proofs, ['refund/', 'refund-proof/']);
            DB::table('nezha_refund_records')->whereIn('order_id', $orderIds)->update([
                'user_id' => null,
                'guest_id' => null,
                'reason_note' => null,
                'route_locked_note' => null,
                'original_tx_hash' => null,
                'locked_to_address' => null,
                'refund_tx_hash' => null,
                'chain_verify_detail' => null,
                'refund_proof_image' => null,
                'risk_hit' => null,
                'review_note' => null,
                'merchant_refund_note' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('nezha_delivery_appeals')) {
            DB::table('nezha_delivery_appeals')->whereIn('order_id', $orderIds)->update([
                'user_id' => null,
                'detail' => null,
                'evidence' => null,
                'admin_note' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('nezha_cs_tickets')) {
            DB::table('nezha_cs_tickets')->whereIn('order_id', $orderIds)->update([
                'user_id' => null,
                'conversation_id' => null,
                'note' => null,
                'updated_at' => now(),
            ]);
        }
    }

    private function deleteCustomerData(int $userId): void
    {
        foreach (['customer_addresses', 'carts', 'wishlists', 'user_notifications', 'visitor_logs', 'recent_searches', 'nezha_cart_events', 'coupon_claims'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                DB::table($table)->where('user_id', $userId)->delete();
            }
        }
        if (Schema::hasTable('reviews')) {
            $reviews = DB::table('reviews')->where('user_id', $userId)->get(['id', 'attachment']);
            $reviewIds = $reviews->pluck('id');
            $this->deleteUploadedFiles($reviews->pluck('attachment'), ['review/']);
            if ($reviewIds->isNotEmpty() && Schema::hasTable('nezha_review_reports')) {
                DB::table('nezha_review_reports')->whereIn('review_id', $reviewIds)->delete();
            }
            DB::table('reviews')->whereIn('id', $reviewIds)->delete();
        }

        if (Schema::hasTable('local_life_posts')) {
            $posts = DB::table('local_life_posts')->where('user_id', $userId)->get(['id', 'images']);
            $postIds = $posts->pluck('id');
            $this->deleteUploadedFiles($posts->pluck('images'), ['local-life/']);
            if ($postIds->isNotEmpty() && Schema::hasTable('local_life_reports')) {
                DB::table('local_life_reports')->whereIn('post_id', $postIds)->delete();
            }
            DB::table('local_life_posts')->whereIn('id', $postIds)->delete();
        }

        if (Schema::hasTable('local_life_merchant_notes')) {
            $noteIds = DB::table('local_life_merchant_notes')->where('user_id', $userId)->pluck('id');
            if ($noteIds->isNotEmpty() && Schema::hasTable('local_life_reports') && Schema::hasColumn('local_life_reports', 'note_id')) {
                DB::table('local_life_reports')->whereIn('note_id', $noteIds)->delete();
            }
            DB::table('local_life_merchant_notes')->whereIn('id', $noteIds)->delete();
        }

        foreach (['user_infos'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                DB::table($table)->where('user_id', $userId)->delete();
            }
        }
        if (Schema::hasTable('restaurant_reports')) {
            DB::table('restaurant_reports')->where('user_id', $userId)->update([
                'user_id' => null,
                'guest_id' => null,
                'description' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('nezha_review_reports')) {
            DB::table('nezha_review_reports')->where('user_id', $userId)->update([
                'user_id' => null,
                'detail' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('local_life_reports')) {
            DB::table('local_life_reports')->where('user_id', $userId)->update([
                'user_id' => null,
                'detail' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('d_m_reviews')) {
            $deliveryReviews = DB::table('d_m_reviews')->where('user_id', $userId)->get(['id', 'attachment']);
            $this->deleteUploadedFiles($deliveryReviews->pluck('attachment'), ['review/']);
            DB::table('d_m_reviews')->whereIn('id', $deliveryReviews->pluck('id'))->delete();
        }
        if (Schema::hasTable('nezha_cs_feedback')) {
            DB::table('nezha_cs_feedback')->where('user_id', $userId)->update([
                'user_id' => null,
                'conversation_id' => null,
                'comment' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('nezha_cs_tickets')) {
            DB::table('nezha_cs_tickets')->where('user_id', $userId)->update([
                'user_id' => null,
                'conversation_id' => null,
                'note' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('nezha_delivery_appeals')) {
            DB::table('nezha_delivery_appeals')->where('user_id', $userId)->update([
                'user_id' => null,
                'detail' => null,
                'evidence' => null,
                'admin_note' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('nezha_risk_records')) {
            DB::table('nezha_risk_records')->where('user_id', $userId)->update([
                'user_id' => null,
                'guest_id' => null,
                'snapshot' => null,
                'ip_address' => null,
                'review_note' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('nezha_payment_address_credentials')) {
            $credentials = NezhaPaymentAddressCredential::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->get();
            foreach ($credentials as $credential) {
                $credential->secret_hash = str_repeat('0', 64);
                $credential->address_snapshot = '';
                $credential->submitted_tx_hash = null;
                $credential->revoked_reason = null;
                if (Schema::hasColumn('nezha_payment_address_credentials', 'redacted_at')) {
                    $credential->redacted_at = now();
                }
                $credential->save();
            }
        }
        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'user_id')) {
            DB::table('expenses')->where('user_id', $userId)->update(['user_id' => null]);
        }
        if (Schema::hasTable('nezha_refund_records')) {
            $this->deleteUploadedFiles(
                DB::table('nezha_refund_records')->where('user_id', $userId)->pluck('refund_proof_image'),
                ['refund/', 'refund-proof/']
            );
            DB::table('nezha_refund_records')->where('user_id', $userId)->update([
                'user_id' => null,
                'guest_id' => null,
                'reason_note' => null,
                'route_locked_note' => null,
                'original_tx_hash' => null,
                'locked_to_address' => null,
                'refund_tx_hash' => null,
                'chain_verify_detail' => null,
                'refund_proof_image' => null,
                'risk_hit' => null,
                'review_note' => null,
                'merchant_refund_note' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('user_external_identities')) {
            DB::table('user_external_identities')->where('user_id', $userId)->delete();
        }
    }

    private function deleteConversations(int $userId): void
    {
        if (! Schema::hasTable('conversations') || ! Schema::hasTable('messages')
            || ! Schema::hasTable('user_infos')) {
            return;
        }
        $participantIds = DB::table('user_infos')->where('user_id', $userId)->pluck('id');
        if ($participantIds->isEmpty()) {
            return;
        }
        $ids = DB::table('conversations')
            ->whereIn('sender_id', $participantIds)
            ->orWhereIn('receiver_id', $participantIds)
            ->pluck('id');
        if ($ids->isNotEmpty()) {
            if (Schema::hasColumn('messages', 'file')) {
                $this->deleteUploadedFiles(
                    DB::table('messages')->whereIn('conversation_id', $ids)->pluck('file'),
                    ['conversation/']
                );
            }
            if (Schema::hasTable('nezha_cs_logs')) {
                DB::table('nezha_cs_logs')->whereIn('conversation_id', $ids)->update([
                    'conversation_id' => null,
                    'message_id' => null,
                    'updated_at' => now(),
                ]);
            }
            if (Schema::hasTable('nezha_cs_tg_map')) {
                DB::table('nezha_cs_tg_map')->whereIn('conversation_id', $ids)->delete();
            }
            if (Schema::hasTable('nezha_cs_feedback')) {
                DB::table('nezha_cs_feedback')->whereIn('conversation_id', $ids)->update([
                    'user_id' => null,
                    'conversation_id' => null,
                    'comment' => null,
                    'updated_at' => now(),
                ]);
            }
            DB::table('messages')->whereIn('conversation_id', $ids)->delete();
            DB::table('conversations')->whereIn('id', $ids)->delete();
        }
    }

    private function anonymizeUser(int $userId): void
    {
        $image = DB::table('users')->where('id', $userId)->value('image');
        $this->deleteUploadedFiles(collect([$image]), ['profile/']);
        DB::table('users')->where('id', $userId)->update([
            'status' => 0,
            'f_name' => null,
            'l_name' => null,
            'phone' => null,
            'email' => null,
            'image' => null,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'cm_firebase_token' => null,
            'notification_preferences' => null,
            'email_verification_token' => null,
            'is_phone_verified' => 0,
            'is_email_verified' => 0,
            'email_verified_at' => null,
            'social_id' => null,
            'login_medium' => null,
            'loyalty_point' => 0,
            'updated_at' => now(),
        ]);
    }

    private function deleteUploadedFiles(iterable $values, array $prefixes): void
    {
        $paths = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $decoded = json_decode($value, true);
            $items = is_array($decoded)
                ? (array_key_exists('img', $decoded) ? [$decoded] : $decoded)
                : [$value];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $item = $item['img'] ?? null;
                }
                if (! is_string($item) || trim($item) === '') {
                    continue;
                }
                $normalized = ltrim(str_replace('\\', '/', trim($item)), '/');
                $paths[] = $normalized;
                foreach ($prefixes as $prefix) {
                    $paths[] = rtrim($prefix, '/').'/'.basename($normalized);
                }
            }
        }
        $paths = array_values(array_unique($paths));
        $diskNames = ['public'];
        if (Helpers::getDisk() === 's3'
            || (config('filesystems.disks.s3.key') && config('filesystems.disks.s3.bucket'))) {
            $diskNames[] = 's3';
        }
        foreach (array_unique($diskNames) as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                foreach ($paths as $path) {
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('customer account deletion attachment cleanup failed', [
                    'disk' => $diskName,
                    'exception' => $e::class,
                ]);
                throw $e;
            }
        }
    }

    private function event(CustomerAccountDeletionState $state, string $type, string $dedupeKey, array $metadata = []): void
    {
        if (! $state->request_id) {
            return;
        }
        CustomerAccountDeletionEvent::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'state_id' => $state->id,
                'request_id' => $state->request_id,
                'event_type' => $type,
                'state_version' => $state->state_version,
                'metadata' => $metadata ?: null,
            ]
        );
    }
}
