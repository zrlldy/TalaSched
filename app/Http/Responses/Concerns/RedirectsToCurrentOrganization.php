<?php

namespace App\Http\Responses\Concerns;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentOrganization
{
    protected function redirectPathForCurrentOrganization(Request $request, string $redirect): string
    {
        $organization = $this->currentOrganization($request);

        if (! $organization) {
            return route('organizations.index');
        }

        URL::defaults(['current_organization' => $organization->slug]);

        return "/{$organization->slug}{$redirect}";
    }

    protected function currentOrganization(Request $request): ?Organization
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $organization = $user->currentOrganization;

        if ($organization && $user->belongsToOrganization($organization)) {
            return $organization;
        }

        $request->session()->forget('url.intended');

        return $user->switchToFallbackOrganization();
    }
}
