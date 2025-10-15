<?php

use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Products
Route::get('/products/{id}', [ProductController::class, 'show']);

// Holds
Route::post('/holds', [HoldController::class, 'store']);

// Orders
Route::post('/orders', [OrderController::class, 'store']);
