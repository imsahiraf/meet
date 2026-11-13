<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\AuthenticationService;

class PasskeyLoginController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
    ) {
    }

    /**
     * Discoverable-credential (resident key) options - no username
     * needed up front, the browser's own account picker shows whichever
     * passkeys it has stored for this site.
     */
    public function options(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();

        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /**
     * Verifies the credential then establishes a real tracked web
     * session via the same path every other login uses (user_sessions
     * row, UserLoggedIn event) - unlike the package's own login
     * controller, which only drives the guard directly. A passkey
     * ceremony is itself possession + biometric/PIN verification, so
     * this deliberately does not also stop at the separate TOTP
     * challenge, matching how major providers treat passkey login.
     */
    public function login(PasskeyVerificationRequest $request, VerifyPasskey $verify): JsonResponse
    {
        $passkey = $verify($request->credential(), $request->verificationOptions());

        /** @var User $user */
        $user = $passkey->user;

        if (in_array($user->status, ['inactive', 'suspended'], true) || ! $user->email_verified_at) {
            throw InvalidPasskeyException::make('Unable to sign in with this account.');
        }

        $this->auth->establishWebSession($user, $request, $request->boolean('remember', true));

        return response()->json(['redirect' => route('chat.index')]);
    }
}
