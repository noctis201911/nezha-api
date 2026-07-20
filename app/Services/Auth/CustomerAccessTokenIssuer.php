<?php

namespace App\Services\Auth;

use App\Models\User;

class CustomerAccessTokenIssuer
{
    public function issue(User $user): string
    {
        return $user->createToken('RestaurantCustomerAuth')->accessToken;
    }
}
