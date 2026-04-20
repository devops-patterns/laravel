<?php

use Illuminate\Support\Facades\Route;

Route::get('/version', function () {
    return response()->json([
        'version' => str($version = config('app.version'))->startsWith('v') ? $version : str($version)->limit(7, ''),
        'hostname' => gethostname(),
        'timestamp' => now()->toISOString(),
    ]);
});
