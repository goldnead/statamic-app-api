<?php

namespace Goldnead\AppApi\Http\Controllers;

use Goldnead\Accounts\Exceptions\AccountException;
use Goldnead\Accounts\Facades\Accounts;
use Goldnead\AppApi\Exceptions\ApiException;
use Goldnead\AppApi\Services\ExportDownloads;
use Goldnead\AppApi\Support\Users;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Statamic\Auth\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The account actions of statamic-accounts, as JSON.
 *
 * Every decision is the addon's: whether the address is taken, what blocks
 * a deletion, what goes into the export, that none of it happens while an
 * admin is signed in as the customer. Changing the address, deleting and
 * exporting sit behind Statamic's elevated session (423 without), exactly
 * as the addon's own forms do.
 */
class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(['account' => $this->state(Users::current($request))]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = Users::current($request);
        $verification = Accounts::verification();

        if (! $verification->enabled()) {
            throw ApiException::make('verification_disabled', 409);
        }

        if ($verification->isVerified($user)) {
            throw ApiException::make('already_verified', 409);
        }

        $verification->send($user);

        return new JsonResponse(['sent' => true], 202);
    }

    public function changeEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:191']]);
        $user = Users::current($request);

        Accounts::emailChange()->request($user, $data['email']);

        return new JsonResponse(['account' => $this->state($user)], 202);
    }

    public function cancelEmailChange(Request $request): JsonResponse
    {
        $user = Users::current($request);

        Accounts::impersonation()->refuseWhileActive();
        Accounts::emailChange()->cancel($user);

        return new JsonResponse(['account' => $this->state($user)]);
    }

    public function requestDeletion(Request $request): JsonResponse
    {
        $user = Users::current($request);

        try {
            Accounts::deletion()->request($user);
        } catch (AccountException $e) {
            if ($e->field !== 'account') {
                throw $e;
            }

            throw ApiException::make('deletion_blocked', 409, null, [
                'blockers' => Accounts::deletion()->blockers($user),
            ], $e->getMessage());
        }

        $state = $this->state($user);

        // The addon's own switch: sign out once the deletion is scheduled.
        if (config('accounts.deletion.logout', false)) {
            Auth::guard((string) config('statamic.users.guards.web', 'web'))->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return new JsonResponse(['account' => $state], 202);
    }

    public function withdrawDeletion(Request $request): JsonResponse
    {
        $user = Users::current($request);
        $deletion = Accounts::deletion();

        Accounts::impersonation()->refuseWhileActive();

        $open = $deletion->pending($user);
        $cancelled = $deletion->cancel($user);

        return new JsonResponse([
            'cancelled' => $cancelled,
            // A subscription cancelled under the `cancel` policy before a
            // failed run stays cancelled; the addon says how many.
            'subscriptions_stay_cancelled' => $open === null ? 0 : $deletion->subscriptionsCancelled($open),
            'account' => $this->state($user),
        ]);
    }

    public function export(Request $request, ExportDownloads $downloads): JsonResponse
    {
        if (! config('accounts.export.enabled', true)) {
            throw ApiException::make('export_disabled', 404);
        }

        $user = Users::current($request);
        $file = Accounts::export()->build($user);

        return new JsonResponse($downloads->store($user, $file), 201);
    }

    public function download(Request $request, string $export, ExportDownloads $downloads): BinaryFileResponse
    {
        if (! $request->hasValidSignature()) {
            throw ApiException::make('invalid_signature', 403);
        }

        return $downloads->send(Users::current($request), $export);
    }

    /**
     * @return array<string, mixed>
     */
    protected function state(User $user): array
    {
        $verification = Accounts::verification();
        $deletion = Accounts::deletion();
        $change = Accounts::emailChange()->pending($user);
        $scheduled = $deletion->pending($user);

        return [
            'email' => $user->email(),
            'verification' => [
                'enabled' => $verification->enabled(),
                'verified' => $verification->isVerified($user),
                'verified_at' => $verification->verifiedAt($user)?->toIso8601String(),
            ],
            'email_change' => $change === null ? null : [
                'email' => $change->email,
                'expires_at' => $change->due_at?->toIso8601String(),
            ],
            'deletion' => $scheduled === null ? null : [
                'status' => $scheduled->status,
                'due_at' => $scheduled->due_at?->toIso8601String(),
            ],
            'deletion_blockers' => $deletion->blockers($user),
            'grace_days' => $deletion->graceDays(),
            'export_enabled' => (bool) config('accounts.export.enabled', true),
            'impersonated' => Accounts::impersonation()->active(),
        ];
    }
}
