<?php

namespace App\Services\Auth;

use App\Exceptions\CustomerLoginException;
use App\Models\User;

class CustomerLoginFinalizer
{
    public function __construct(private readonly CustomerAccessTokenIssuer $tokenIssuer) {}

    public function issue(User $user, string $loginType): string
    {
        if (! (bool) $user->status) {
            throw new CustomerLoginException(
                'account_blocked',
                'This account is unavailable. Please contact support.',
            );
        }

        // Account-deletion V5 will plug its pending-deletion challenge into
        // this method before its API candidate is allowed to issue sessions.
        // Until then every current customer provider still shares this single
        // status-before-token boundary.
        $user->login_medium = $loginType;
        $user->save();

        return $this->tokenIssuer->issue($user);
    }
}
