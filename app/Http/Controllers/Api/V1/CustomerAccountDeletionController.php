<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AccountDeletionException;
use App\Http\Controllers\Controller;
use App\Services\Auth\CustomerAccessTokenIssuer;
use App\Services\CustomerAccountDeletion\CustomerAccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerAccountDeletionController extends Controller
{
    public function __construct(
        private readonly CustomerAccountDeletionService $deletions,
    ) {}

    public function show(Request $request)
    {
        return response()->json([
            'account_deletion' => $this->deletions->projection(
                $this->deletions->currentForUser((int) $request->user()->id)
            ),
        ]);
    }

    public function cancel(Request $request)
    {
        $deletion = $this->deletions->cancelForUser($request->user());

        return response()->json([
            'message' => '账号注销预约已取消。',
            'account_deletion' => $this->deletions->projection($deletion),
        ]);
    }

    public function cancelFromChallenge(Request $request, CustomerAccessTokenIssuer $tokens)
    {
        $this->validateChallenge($request);
        $user = $this->deletions->resolveLoginChallenge(
            (string) $request->request_id,
            (string) $request->challenge,
            CustomerAccessTokenIssuer::authContext(),
            true
        );

        return response()->json([
            'message' => '注销预约已取消，正在登录。',
            'token' => $tokens->issue($user, true),
        ]);
    }

    public function keepFromChallenge(Request $request)
    {
        $this->validateChallenge($request);
        $this->deletions->resolveLoginChallenge(
            (string) $request->request_id,
            (string) $request->challenge,
            CustomerAccessTokenIssuer::authContext(),
            false
        );

        return response()->json(['message' => '注销预约保持不变，未登录账号。']);
    }

    private function validateChallenge(Request $request): void
    {
        $validator = Validator::make($request->all(), [
            'request_id' => 'required|uuid',
            'challenge' => 'required|string|size:64',
        ]);
        if ($validator->fails()) {
            throw new AccountDeletionException(
                'ACCOUNT_DELETION_CHALLENGE_REPLAYED',
                '登录确认无效，请重新登录。',
                422
            );
        }
    }
}
