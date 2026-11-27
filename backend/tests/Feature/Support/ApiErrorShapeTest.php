<?php

use App\Support\Http\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('returns the standard error shape for an unknown API route', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('renders an ApiException with its own code, status and details', function () {
    Route::get('/api/v1/_test/module-off', function () {
        throw new ApiException('module_disabled', 'Fees is not part of this licence.', 403, ['module' => 'fees']);
    });

    $this->getJson('/api/v1/_test/module-off')
        ->assertForbidden()
        ->assertExactJson([
            'error' => [
                'code' => 'module_disabled',
                'message' => 'Fees is not part of this licence.',
                'details' => ['module' => 'fees'],
            ],
        ]);
});

it('returns validation errors in the details object', function () {
    Route::post('/api/v1/_test/validate', function (Request $request) {
        $request->validate(['name' => 'required']);
    });

    $this->postJson('/api/v1/_test/validate', [])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['name']]]);
});
