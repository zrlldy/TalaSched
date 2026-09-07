<?php

namespace App\Http\Controllers;

use App\Actions\GetWorkspaceOverview;
use App\Actions\Organizations\GetPendingOrganizationInvitations;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Organization $currentOrganization, GetPendingOrganizationInvitations $getPendingInvitations, GetWorkspaceOverview $overview): Response
    {
        return Inertia::render('Dashboard', [
            'pendingInvitations' => $getPendingInvitations->handle($request->user()),
            'overview' => $overview->handle($currentOrganization),
        ]);
    }
}
