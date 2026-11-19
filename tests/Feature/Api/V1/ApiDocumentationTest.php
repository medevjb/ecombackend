<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

it('keeps documented endpoints and Postman requests aligned with Laravel routes', function () {
    $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    $collection = json_decode(file_get_contents(base_path('docs/api/ecommerce.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);

    $normalize = fn (string $path): string => preg_replace('/\{\{[^}]+\}\}|\{[^}]+\}/', '{parameter}', $path);

    $documented = collect($spec['paths'])->flatMap(fn (array $methods, string $path) => collect($methods)
        ->keys()->map(fn (string $method): string => strtoupper($method).' '.$normalize($path)))->sort()->values()->all();

    $registered = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' '.$normalize('/'.$route->uri())))->sort()->values()->all();

    $postman = collect($collection['item'])->flatMap(fn (array $folder) => $folder['item'])
        ->map(fn (array $item): string => $item['request']['method'].' '.$normalize('/'.implode('/', $item['request']['url']['path'])))
        ->sort()->values()->all();

    expect($documented)->toBe($registered)
        ->and($postman)->toBe($registered);
});
