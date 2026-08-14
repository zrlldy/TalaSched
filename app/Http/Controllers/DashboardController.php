<?php

namespace App\Http\Controllers;

use App\Actions\Organizations\GetPendingOrganizationInvitations;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, GetPendingOrganizationInvitations $getPendingInvitations): Response
    {
        return Inertia::render('Dashboard', [
            'pendingInvitations' => $getPendingInvitations->handle($request->user()),
        ]);
    }
}
