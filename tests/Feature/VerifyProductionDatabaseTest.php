<?php

test('production database verification fails outside postgresql', function () {
    config(['database.default' => 'sqlite']);

    $this->artisan('database:verify-production')
        ->expectsTable(['Check', 'Status', 'Detail'], [
            ['PostgreSQL connection', 'FAIL', 'connection=sqlite driver=sqlite'],
        ])
        ->assertFailed();
});
