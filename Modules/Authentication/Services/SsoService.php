<?php

namespace Modules\Authentication\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Modules\Authentication\Events\UserRegistered;
use Modules\Authentication\Exceptions\CannotUnlinkSoleLoginMethodException;
use Modules\Authentication\Listeners\CreateDefaultUserRecords;
use Modules\Authentication\Models\User;
use Modules\Authentication\Models\UserOauthIdentity;
use Modules\Authentication\Repositories\UserRepository;

class SsoService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthenticationService $auth,
    ) {
    }

    /**
     * Resolves (or creates) the local User for a provider identity,
     * running the exact same suspended/unverified gate + 2FA-or-session
     * branch every other login path uses. Never bypasses 2FA - a
     * linked provider account is a separate identity from this app's
     * own account, so 2FA still protects it regardless of how the
     * sign-in got here (unlike a passkey, which is itself possession +
     * biometric verification already).
     */
    public function loginOrRegister(Request $request, string $provider, SocialiteUser $socialiteUser): array
    {
        $user = $this->resolveUser($provider, $socialiteUser);

        $verifiedUser = $this->auth->authenticateViaSso($request, $user);

        return $this->auth->loginForWebViaSso($request, $verifiedUser);
    }

    private function resolveUser(string $provider, SocialiteUser $socialiteUser): User
    {
        $identity = UserOauthIdentity::where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if ($identity) {
            return $identity->user;
        }

        $email = $socialiteUser->getEmail();
        $existingUser = $email ? $this->users->findByEmail($email) : null;

        if ($existingUser) {
            $this->linkIdentity($existingUser, $provider, $socialiteUser);

            return $existingUser;
        }

        return DB::transaction(function () use ($provider, $socialiteUser) {
            $user = $this->users->createFromSso($socialiteUser->getEmail(), $socialiteUser->getName() ?: $socialiteUser->getNickname() ?: $socialiteUser->getEmail());

            // Reuses the exact same default-records setup every other
            // registration gets (profile/preferences/privacy/presence,
            // default role) - a direct call, not UserRegistered::dispatch(),
            // since that event's OTHER listener (SendRegistrationOtpMail)
            // doesn't apply here: this account is already verified.
            app(CreateDefaultUserRecords::class)->handle(new UserRegistered($user, ''));

            $this->linkIdentity($user, $provider, $socialiteUser);

            return $user;
        });
    }

    private function linkIdentity(User $user, string $provider, SocialiteUser $socialiteUser): void
    {
        UserOauthIdentity::firstOrCreate([
            'user_id' => $user->id,
            'provider' => $provider,
        ], [
            'provider_user_id' => $socialiteUser->getId(),
        ]);
    }

    /**
     * Refuses to remove a user's only login method - a password-less
     * SSO-only account with just one linked provider would otherwise
     * be permanently locked out the moment that link is removed.
     */
    public function unlink(User $user, UserOauthIdentity $identity): void
    {
        $remainingIdentities = $user->oauthIdentities()->where('id', '!=', $identity->id)->exists();

        if (! $user->hasUsablePassword() && ! $remainingIdentities) {
            throw new CannotUnlinkSoleLoginMethodException;
        }

        $identity->delete();
    }
}
