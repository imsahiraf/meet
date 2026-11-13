<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authentication\Http\Requests\ChangePasswordRequest;
use Modules\Authentication\Http\Requests\ForgotPasswordRequest;
use Modules\Authentication\Http\Requests\LoginRequest;
use Modules\Authentication\Http\Requests\RegisterRequest;
use Modules\Authentication\Http\Requests\ResendOtpRequest;
use Modules\Authentication\Http\Requests\ResetPasswordRequest;
use Modules\Authentication\Http\Requests\VerifyEmailRequest;
use Modules\Authentication\Http\Requests\VerifyTwoFactorRequest;
use Modules\Authentication\Http\Resources\UserResource;
use Modules\Authentication\Services\AuthenticationService;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
    ) {
    }

    /**
     * RAS-1 API1 - POST /auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $this->auth->register($request->toDto());

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. Please verify your email.',
            'data' => null,
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-1 API2 - POST /auth/verify-email
     */
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $this->auth->verifyEmail($request->string('email'), $request->string('otp'));

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-1 API3 - POST /auth/resend-otp
     */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $this->auth->resendOtp($request->string('email'));

        return response()->json([
            'success' => true,
            'message' => 'A new OTP has been sent.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-1 API4 - POST /auth/login. If the account has 2FA enabled,
     * no token is issued yet - the response instead carries a
     * challenge_token the client must POST alongside a code to
     * /auth/two-factor/verify to actually receive a token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request, $request->string('login'), $request->string('password'));

        if ($result['requires_2fa']) {
            return response()->json([
                'success' => true,
                'message' => 'Two-factor authentication code required.',
                'data' => ['requires_2fa' => true, 'challenge_token' => $result['challenge_token']],
                'errors' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'requires_2fa' => false,
                'token' => $result['token'],
                'user' => new UserResource($result['user']),
            ],
            'errors' => null,
        ]);
    }

    /**
     * POST /auth/two-factor/verify - completes a 2FA-pending login by
     * verifying the code (or a one-time recovery code) and issuing the
     * Sanctum token, exactly as login() would have if 2FA weren't involved.
     */
    public function verifyTwoFactor(VerifyTwoFactorRequest $request): JsonResponse
    {
        $result = $this->auth->completeTwoFactorLoginForApi($request, $request->string('challenge_token'), $request->string('code'));

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'requires_2fa' => false,
                'token' => $result['token'],
                'user' => new UserResource($result['user']),
            ],
            'errors' => null,
        ]);
    }

    /**
     * RAS-1 API5 - POST /auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user(), (string) $request->user()->currentAccessToken()?->id);

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-1 API6 - POST /auth/forgot-password
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->forgotPassword($request->string('email'));

        return response()->json([
            'success' => true,
            'message' => 'If that email exists, a password reset link has been sent.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-1 API7 - POST /auth/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->string('token'), $request->string('password'));

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-1 API8 - GET /auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['profile', 'preferences', 'privacy', 'roles']);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => new UserResource($user),
            'errors' => null,
        ]);
    }

    /**
     * RAS-1 API9 - POST /auth/refresh
     */
    public function refresh(Request $request): JsonResponse
    {
        $session = $request->user()->sessions()
            ->where('session_id', (string) $request->user()->currentAccessToken()?->id)
            ->where('status', 'active')
            ->first();

        $session?->forceFill(['last_activity_at' => now()])->save();

        return response()->json([
            'success' => true,
            'message' => 'Session refreshed.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-1 API10 - POST /auth/change-password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword(
            $request->user(),
            $request->string('current_password'),
            $request->string('new_password'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
