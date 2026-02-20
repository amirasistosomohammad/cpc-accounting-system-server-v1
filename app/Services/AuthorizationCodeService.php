<?php

namespace App\Services;

use App\Models\AuthorizationCode;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthorizationCodeService
{
    /**
     * Validate code and mark as used. Returns the code model.
     * Codes are now UNIVERSAL: they are not restricted to a specific action.
     * @throws ValidationException
     */
    public static function validateAndUse(string $code, string $forAction, $user, ?string $subjectType = null, ?int $subjectId = null): AuthorizationCode
    {
        $codeModel = AuthorizationCode::where('code', strtoupper($code))->first();
        if (!$codeModel) {
            throw ValidationException::withMessages(['authorization_code' => ['The authorization code is invalid.']]);
        }
        // We intentionally ignore $forAction here now – codes are universal.
        if (!$codeModel->isValid()) {
            throw ValidationException::withMessages(['authorization_code' => ['The authorization code has expired or has already been used.']]);
        }

        // Manual codes (for_action = 'manual') remain reusable; don't mark them used.
        if ($codeModel->for_action !== 'manual') {
            $userType = $user instanceof \App\Models\Admin ? 'admin' : 'personnel';
            $codeModel->markUsed($userType, $user->id);
        }
        return $codeModel;
    }
}
