<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TodoController;

// Handle CORS preflight requests
Route::options('{any}', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Accept, Origin, X-XSRF-TOKEN')
        ->header('Access-Control-Allow-Credentials', 'true')
        ->header('Access-Control-Max-Age', '86400');
})->where('any', '.*');

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);

// Public debug route for PDF upload testing (no auth required)
Route::post('/debug-pdf-upload-public', [TodoController::class, 'debugPdfUpload']);

// Health check route for Railway
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Authentication routes
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Todo option routes (must come before resource routes to avoid conflicts)
    Route::get('/todos/status-options', [TodoController::class, 'getStatusOptions']);
    Route::get('/todos/priority-options', [TodoController::class, 'getPriorityOptions']);
    
    // Bulk operations (must come before parameterized routes)
    Route::delete('/todos/bulk-delete', [TodoController::class, 'bulkDelete']);
    
    // Debug route for PDF upload testing
    Route::post('/debug-pdf-upload', [TodoController::class, 'debugPdfUpload']);
    
    // Todo CRUD operations
    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
    Route::get('/todos/{id}', [TodoController::class, 'show']);
    Route::put('/todos/{id}', [TodoController::class, 'update']);
    Route::patch('/todos/{id}', [TodoController::class, 'update']);
    Route::delete('/todos/{id}', [TodoController::class, 'destroy']);
    
    // PDF-specific operations
    Route::get('/todos/{id}/download-pdf', [TodoController::class, 'downloadPdf']);
    Route::delete('/todos/{id}/delete-pdf', [TodoController::class, 'deletePdf']);
});