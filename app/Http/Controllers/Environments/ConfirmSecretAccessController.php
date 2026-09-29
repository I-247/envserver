<?php

namespace App\Http\Controllers\Environments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Variables\ConfirmSecretAccessRequest;
use App\Support\SecretAccessWindow;
use Illuminate\Http\JsonResponse;

class ConfirmSecretAccessController extends Controller
{
    /**
     * Confirm the password so secrets reveal for the next few minutes.
     */
    public function __invoke(ConfirmSecretAccessRequest $request): JsonResponse
    {
        SecretAccessWindow::open($request);

        return response()->json(['minutes' => SecretAccessWindow::minutes()]);
    }
}
