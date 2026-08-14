<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('invitations:prune')->daily()->description('Delete expired organization invitations');
Schedule::command('idempotency:prune')->daily()->description('Delete expired idempotency records');
