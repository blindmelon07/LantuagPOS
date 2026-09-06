<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class TwoFactorAuthController extends AppBaseController
{
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))->map(function () {
            return [
                'used' => false,
                'code' => Str::random(10) . '-' . Str::random(10),
            ];
        })->toArray();
    }

    /**
     * Get the QR code for two-factor authentication.
     *
     * @return JsonResponse
     */
    public function getQrCode(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $google2fa = app('pragmarx.google2fa');

        if (empty($user->two_factor_secret)) {
            $secret = $google2fa->generateSecretKey(16);
            $user->forceFill([
                'two_factor_secret' => encrypt($secret),
                'two_factor_recovery_codes' => encrypt(json_encode($this->generateRecoveryCodes())),
            ])->save();
        }

        $secret = decrypt($user->two_factor_secret);

        $qrCode = $google2fa->getQRCodeInline(
            config('app.name'),
            $user->email,
            $secret,
            200
        );

        return $this->sendResponse([
            'secret' => $secret,
            'qrcode' => $qrCode,
        ], 'QR code retrieved successfully');
    }

    /**
     * Verify the two-factor authentication code.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function enableDisable2FA(Request $request): JsonResponse
    {
        $request->validate([
            'otp' => 'required|string',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $google2fa = app('pragmarx.google2fa');
        $allCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        $codes = collect($allCodes)->where('used', false)->pluck('code')->values()->toArray();
        $isValid = false;

        if ($user->two_factor_enabled && in_array($request->otp, $codes)) {
            $isValid = true;
            $user->forceFill([
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(function ($code) use ($request) {
                    if ($code['code'] == $request->otp) {
                        $code['used'] = true;
                    }
                    return $code;
                }, $allCodes))),
            ])->save();
        }
        if (!$isValid) {
            try {
                $secret = decrypt($user->two_factor_secret);
            } catch (\Exception $e) {
                return $this->sendError('2FA secret is corrupted. Please re-enable 2FA.');
            }

            $isValid = $google2fa->verifyKey($secret, $request->otp, 2);
            if (!$isValid) {
                return $this->sendError('Invalid two-factor authentication code');
            }
        }

        if ($user->two_factor_enabled) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_enabled' => null,
                'two_factor_recovery_codes' => null,
            ])->save();
            return $this->sendResponse(null, 'Two-factor authentication disabled successfully');
        } else {
            $user->forceFill([
                'two_factor_enabled' => true,
            ])->save();

            return $this->sendResponse([
                'recovery_codes' => $codes,
                'download_url' => route('two-factor.download-recovery-codes', ['user' => encrypt($user->id)])
            ], 'Two-factor authentication enabled successfully');
        }
    }

    public function downloadRecoveryCodes($encryptedUserId)
    {
        $user = User::findOrFail(decrypt($encryptedUserId));

        $allCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        $codes = collect($allCodes)->where('used', false)->pluck('code')->values()->toArray();

        $directory = public_path('uploads/2fa/' . $user->id);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filePath = $directory . '/recovery-codes.txt';

        $content  = "Two-Factor Authentication Recovery Codes\n";
        $content .= "=====================================\n\n";
        $content .= "Generated At: " . now()->toDateTimeString() . "\n\n";
        $content .= "IMPORTANT:\n";
        $content .= "- Each code can be used ONLY ONCE\n";
        $content .= "- Store these codes somewhere safe\n\n";

        foreach ($codes as $code) {
            $content .= $code . PHP_EOL;
        }

        File::put($filePath, $content);

        return response()
            ->download($filePath, 'recovery-codes.txt')
            ->deleteFileAfterSend(true);
    }

    /**
     * Verify the two-factor authentication code.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string',
        ]);

        $user = User::whereRaw('lower(email) = ?', [$request->email])->firstOrFail();
        $allCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        $codes = collect($allCodes)->where('used', false)->pluck('code')->values()->toArray();
        $isValid = false;

        if (in_array($request->otp, $codes)) {
            $isValid = true;
            $user->forceFill([
                'two_factor_recovery_codes' => encrypt(json_encode(array_map(function ($code) use ($request) {
                    if ($code['code'] == $request->otp) {
                        $code['used'] = true;
                    }
                    return $code;
                }, $allCodes))),
            ])->save();
        }

        if (!$isValid) {
            $google2fa = app('pragmarx.google2fa');

            try {
                $secret = decrypt($user->two_factor_secret);
            } catch (\Exception $e) {
                return $this->sendError('2FA secret is corrupted. Please re-enable 2FA.');
            }

            $isValid = $google2fa->verifyKey($secret, $request->otp, 2);
            if (!$isValid) {
                return $this->sendError('The one-time password you entered is invalid. Please try again.');
            }
        }

        $userPermissions = $user->getAllPermissions()->pluck('name')->toArray();
        unset($user->roles);
        unset($user->permissions);
        Session::put('auth', $user->id);
        $token = $user->createToken('token')->plainTextToken;
        $user->last_name = $user->last_name ?? '';

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $user,
                'roles' => $user->roles[0]->name,
                'expires_at' => config('sanctum.expiration'),
                'permissions' => $userPermissions,
            ],
            'message' => 'Logged in successfully.',
        ]);
    }
}
