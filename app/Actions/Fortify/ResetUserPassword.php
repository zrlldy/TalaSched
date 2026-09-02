<?php

namespace App\Actions\Fortify;

use App\Audit\AuditLogger;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => $input['password'],
            ])->save();

            $this->auditLogger->record(
                action: 'account.password_reset',
                subject: $user,
            );
        });
    }
}
