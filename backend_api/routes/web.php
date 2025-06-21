<?php

use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// })
Route::get('/test-email', function () {
    try {
        $emailService = new \App\Services\EmailService();
        
        // Test connection first
        $status = $emailService->getStatus();
        Log::info('Email service status', $status);
        
        if (!$status['configured']) {
            return response()->json(['error' => 'Email service not properly configured'], 500);
        }
        
        // Test connection
        $connectionTest = $emailService->testConnection();
        if (!$connectionTest) {
            return response()->json(['error' => 'Email service connection failed'], 500);
        }
        
        // Send test email
        $result = $emailService->sendOtpEmail('arslan@efaida.tech', '123456', 'Test User');
        
        return response()->json([
            'success' => $result,
            'status' => $status,
            'message' => $result ? 'Email sent successfully' : 'Email failed to send'
        ]);
        
    } catch (Exception $e) {
        Log::error('Test email failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'error' => $e->getMessage(),
            'message' => 'Email test failed'
        ], 500);
    }
});
;
