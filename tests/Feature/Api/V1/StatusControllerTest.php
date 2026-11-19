<?php

use Illuminate\Support\Facades\DB;

it('serves a public status page and reports database connectivity', function () {
    $this->get('/')->assertOk()
        ->assertSee('API status')
        ->assertSee('/api/v1/status')
        ->assertCookieMissing(config('session.cookie'));

    $this->getJson('/api/v1/status')->assertOk()
        ->assertJsonPath('data.version', 'v1')
        ->assertJsonPath('data.status', 'operational')
        ->assertJsonPath('data.checks.api', 'operational')
        ->assertJsonPath('data.checks.database', 'operational');
});

it('reports a database outage without exposing connection details', function () {
    config(['database.connections.unavailable' => [
        'driver' => 'sqlite',
        'database' => '/missing/database.sqlite',
    ]]);
    $originalConnection = DB::getDefaultConnection();
    DB::setDefaultConnection('unavailable');

    try {
        $this->get('/')->assertOk();
        $this->getJson('/api/v1/status')->assertServiceUnavailable()
            ->assertJsonPath('errors.code', 'DATABASE_UNAVAILABLE')
            ->assertJsonPath('data', null)
            ->assertDontSee('database.sqlite');
    } finally {
        DB::setDefaultConnection($originalConnection);
    }
});
