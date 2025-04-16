<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\UdtController;
use Illuminate\Support\Facades\Route;

Route::post("/udt", [UdtController::class, "store"]);

Route::apiResource("companies", CompanyController::class);
