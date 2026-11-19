<?php

use Database\Seeders\StaffAccessSeeder;

it('provisions a staff identity and refuses to bootstrap a second super admin', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->artisan('staff:bootstrap-super-admin', ['email' => 'owner@example.test'])
        ->expectsQuestion('Password', 'demo-strong-password')
        ->assertSuccessful();

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'demo-strong-password'])
        ->assertOk()->assertJsonPath('data.dashboard', 'staff')->assertJsonPath('data.user.roles', ['super-admin']);

    $this->artisan('staff:bootstrap-super-admin', ['email' => 'second@example.test'])
        ->expectsQuestion('Password', 'demo-strong-password')
        ->assertFailed();
    $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
});
