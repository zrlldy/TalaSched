<?php

namespace App\Http\Controllers\Settings;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill($request->validated());

        $emailWasVerified = $user->email_verified_at !== null;
        $nameChanged = $user->isDirty('name');
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        DB::transaction(function () use ($user, $emailWasVerified, $nameChanged, $emailChanged): void {
            $user->save();

            $this->auditLogger->record(
                action: 'account.profile_updated',
                actor: $user,
                subject: $user,
                before: [
                    'email_verified' => $emailWasVerified,
                ],
                after: [
                    'name_changed' => $nameChanged,
                    'email_changed' => $emailChanged,
                    'email_verified' => $user->email_verified_at !== null,
                ],
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->ownedOrganizations()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'account' => __('Transfer ownership of your organizations before deleting your account.'),
            ]);
        }

        Auth::logout();

        DB::transaction(function () use ($user): void {
            $this->auditLogger->record(
                action: 'account.deleted',
                actor: $user,
                subject: $user,
                after: [
                    'status' => 'deleted',
                ],
            );

            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
