<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/proxy', [\App\Http\Controllers\ProxyController::class, 'handle']);
