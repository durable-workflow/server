<?php

use App\Http\Controllers\ServerDiscoveryController;
use Illuminate\Support\Facades\Route;

Route::get('/', ServerDiscoveryController::class);
