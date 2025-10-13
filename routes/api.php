<?php

use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Products
Route::get('/products/{id}', [ProductController::class, 'show']);
