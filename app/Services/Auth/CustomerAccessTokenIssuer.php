<?php

namespace App\Services\Auth;

use App\Models\User;

class CustomerAccessTokenIssuer
{
    public const REQUEST_TOKEN_HASH = 'nezha.customer_issued_token_hash';

    public const REQUEST_USER_ID = 'nezha.customer_issued_user_id';

    public const REQUEST_ACCESS_TOKEN_ID = 'nezha.customer_issued_access_token_id';

    public function issue(User $user): string
    {
        $issued = $user->createToken('RestaurantCustomerAuth');
        $token = $issued->accessToken;

        // The response middleware only creates a browser session if this exact
        // token is actually returned by a successful outward login response.
        // This avoids setting a Cookie for intermediate Google redirect codes
        // or for flows that later fail verification.
        if (app()->bound('request')) {
            request()->attributes->set(
                self::REQUEST_TOKEN_HASH,
                hash('sha256', $token)
            );
            request()->attributes->set(
                self::REQUEST_USER_ID,
                $user->getKey()
            );
            request()->attributes->set(
                self::REQUEST_ACCESS_TOKEN_ID,
                (string) $issued->token->id
            );
        }

        return $token;
    }
}
