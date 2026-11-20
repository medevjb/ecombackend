<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Yaml\Yaml;

class ApiReferenceController extends Controller
{
    public function index(): View
    {
        $specification = Yaml::parseFile(base_path('docs/api/openapi.yaml'));

        return view('api-reference', ['specification' => $specification]);
    }

    public function specification(): BinaryFileResponse
    {
        return response()->file(base_path('docs/api/openapi.yaml'), ['Content-Type' => 'application/yaml; charset=UTF-8']);
    }
}
