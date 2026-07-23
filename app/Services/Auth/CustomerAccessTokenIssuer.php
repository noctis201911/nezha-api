<?php

namespace App\Services\Auth;

use App\Exceptions\AccountDeletionException;
use App\Models\User;
use App\Services\CustomerAccountDeletion\CustomerAccountDeletionService;

class CustomerAccessTokenIssuer
{
    public function __construct(private readonly CustomerAccountDeletionService $deletions)
    {
    }

    public function issue(User $user, bool $afterChallengeCancellation = false): string
    {
        if (! $afterChallengeCancellation) {
            $challenge = $this->deletions->issueLoginChallenge($user, self::authContext());
            if ($challenge) {
                throw new AccountDeletionException(
                    'ACCOUNT_DELETION_ACTIVE',
                    '该账号已预约注销。请选择取消注销并登录，或保持注销并退出。',
                    409,
                    $challenge
                );
            }
        }

        return $user->createToken('RestaurantCustomerAuth')->accessToken;
    }

    public static function authContext(): string
    {
        $request = request();

        return substr(hash('sha256', (string) $request->userAgent().'|'.(string) $request->ip()), 0, 40);
    }
}
