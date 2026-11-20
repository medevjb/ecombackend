<?php

use Database\Seeders\StaffAccessSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

it('uploads validated staff images to the configured disk and checks the media scope', function () {
    $this->seed(StaffAccessSeeder::class);
    Storage::fake('public');
    $route = '/api/v1/admin/media/';

    $this->withToken(catalogStaffToken('product-manager'))->post($route.'content', [
        'image' => UploadedFile::fake()->image('banner.png'),
    ], ['Accept' => 'application/json'])->assertForbidden();

    Auth::forgetGuards();
    $this->post($route.'category-create', [
        'image' => UploadedFile::fake()->create('unsafe.svg', 1, 'image/svg+xml'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['image'], 'errors.fields');

    Auth::forgetGuards();
    $response = $this->post($route.'category-create', [
        'image' => UploadedFile::fake()->image('category.png'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $path = Storage::disk('public')->allFiles('storefront/category-create');
    expect($path)->toHaveCount(1);
    $response->assertJsonPath('data.url', Storage::disk('public')->url($path[0]));
});
