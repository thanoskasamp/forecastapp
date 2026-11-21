<?php

use App\Http\Controllers\ForecastController;
use Illuminate\Support\Facades\Route;

Route::get('/forecasts', [ForecastController::class, 'index']);
