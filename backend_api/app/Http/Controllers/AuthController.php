<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Mail\OtpMail;
use Exception;
use App\Services\EmailService;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        Log::info('=== REGISTER PROCESS STARTED ===', ['request_data' => $request->all()]);
        
        try {
            Log::info('Step 1: Starting validation');
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'company' => 'nullable|string|max:255',
                'email' => 'required|email|max:255',
                'password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                Log::warning('Step 1: Validation failed', ['errors' => $validator->errors()]);
                return response()->json(['errors' => $validator->errors()], 422);
            }
            Log::info('Step 1: Validation passed');

            Log::info('Step 2: Checking if user exists');
            $user = User::where('email', $request->email)->first();
            if ($user && $user->is_activated) {
                Log::info('Step 2: User already exists and is activated', ['user_id' => $user->id]);
                return response()->json(['message' => 'Account already exists and is activated.'], 409);
            }
            Log::info('Step 2: User check passed - proceeding with registration');

            Log::info('Step 3: Starting database transaction');
            DB::beginTransaction();

            Log::info('Step 4: Cleaning up expired OTPs');
            $this->cleanupExpiredOtps();

            Log::info('Step 5: Creating/updating user record');
            $user = User::updateOrCreate(
                ['email' => $request->email],
                [
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'company' => $request->company,
                    'password' => Hash::make($request->password),
                    'is_activated' => false,
                ]
            );
            Log::info('Step 5: User record created/updated', ['user_id' => $user->id]);

            Log::info('Step 6: Deleting existing unverified OTPs for user');
            $deletedOtps = UserOtp::where('user_id', $user->id)
                ->where('is_verified', false)
                ->delete();
            Log::info('Step 6: Deleted unverified OTPs', ['deleted_count' => $deletedOtps]);

            Log::info('Step 7: Generating new OTP');
            $otp = $this->generateOtp();
            $expiresAt = Carbon::now()->addMinutes(config('auth.otp.expiry', 10));
            Log::info('Step 7: OTP generated', ['otp' => $otp, 'expires_at' => $expiresAt]);

            Log::info('Step 8: Creating OTP record in database');
            UserOtp::create([
                'user_id' => $user->id,
                'otp_code' => $otp,
                'expires_at' => $expiresAt,
                'is_verified' => false,
            ]);
            Log::info('Step 8: OTP record created successfully');

            Log::info('Step 9: Attempting to send OTP email');
            try {
                $emailService = new EmailService();
                $emailService->sendOtpEmail($user->email, $otp, $user->first_name . ' ' . $user->last_name);
                Log::info('Step 9: OTP email sent successfully', ['email' => $user->email, 'user_id' => $user->id]);
            } catch (Exception $e) {
                Log::error('Step 9: FAILED to send OTP email', [
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                Log::info('Step 9: Rolling back transaction due to email failure');
                DB::rollBack();
                return response()->json([
                    'message' => 'Registration failed. Unable to send verification email. Please try again.'
                ], 500);
            }

            Log::info('Step 10: Committing database transaction');
            DB::commit();
            Log::info('Step 10: Transaction committed successfully');

            Log::info('=== REGISTER PROCESS COMPLETED SUCCESSFULLY ===');
            return response()->json([
                'message' => 'Registration successful. Please check your email for the OTP.'
            ], 201);

        } catch (Exception $e) {
            Log::error('=== REGISTER PROCESS FAILED ===', [
                'email' => $request->email ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            Log::info('Rolling back transaction due to unexpected error');
            DB::rollBack();

            return response()->json([
                'message' => 'Registration failed. Please try again later.'
            ], 500);
        }
    }

    public function verifyOtp(Request $request)
    {
        Log::info('=== OTP VERIFICATION PROCESS STARTED ===', ['request_data' => $request->all()]);
        
        try {
            Log::info('Step 1: Starting validation');
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'otp_code' => 'required|string|size:6',
            ]);

            if ($validator->fails()) {
                Log::warning('Step 1: Validation failed', ['errors' => $validator->errors()]);
                return response()->json(['errors' => $validator->errors()], 422);
            }
            Log::info('Step 1: Validation passed');

            Log::info('Step 2: Cleaning up expired OTPs');
            $this->cleanupExpiredOtps();

            Log::info('Step 3: Finding user by email');
            $user = User::where('email', $request->email)->first();
            if (!$user) {
                Log::warning('Step 3: User not found', ['email' => $request->email]);
                return response()->json(['message' => 'User not found.'], 404);
            }
            Log::info('Step 3: User found', ['user_id' => $user->id]);

            Log::info('Step 4: Looking for valid OTP');
            $userOtp = UserOtp::where('user_id', $user->id)
                ->where('otp_code', $request->otp_code)
                ->where('is_verified', false)
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if (!$userOtp) {
                Log::warning('Step 4: Invalid or expired OTP', [
                    'email' => $request->email,
                    'user_id' => $user->id,
                    'attempted_otp' => $request->otp_code,
                    'current_time' => Carbon::now()
                ]);
                
                // Let's also check what OTPs exist for this user
                $existingOtps = UserOtp::where('user_id', $user->id)->get();
                Log::info('Step 4: Existing OTPs for user', ['otps' => $existingOtps->toArray()]);
                
                return response()->json(['message' => 'Invalid or expired OTP.'], 400);
            }
            Log::info('Step 4: Valid OTP found', ['otp_id' => $userOtp->id]);

            Log::info('Step 5: Starting database transaction');
            DB::beginTransaction();

            Log::info('Step 6: Marking OTP as verified');
            $userOtp->update(['is_verified' => true]);
            Log::info('Step 6: OTP marked as verified');
            
            Log::info('Step 7: Activating user account');
            $user->update(['is_activated' => true]);
            Log::info('Step 7: User account activated');

            Log::info('Step 8: Deleting all OTPs for user');
            $deletedOtps = UserOtp::where('user_id', $user->id)->delete();
            Log::info('Step 8: Deleted OTPs', ['deleted_count' => $deletedOtps]);

            Log::info('Step 9: Committing transaction');
            DB::commit();
            Log::info('Step 9: Transaction committed successfully');

            Log::info('=== OTP VERIFICATION COMPLETED SUCCESSFULLY ===', [
                'email' => $user->email,
                'user_id' => $user->id
            ]);

            return response()->json(['message' => 'Account activated successfully.'], 200);

        } catch (Exception $e) {
            Log::error('=== OTP VERIFICATION FAILED ===', [
                'email' => $request->email ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            Log::info('Rolling back transaction due to error');
            DB::rollBack();

            return response()->json([
                'message' => 'Verification failed. Please try again later.'
            ], 500);
        }
    }

    public function login(Request $request)
    {
        Log::info('=== LOGIN PROCESS STARTED ===', ['email' => $request->email]);
        
        try {
            Log::info('Step 1: Starting validation');
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                Log::warning('Step 1: Validation failed', ['errors' => $validator->errors()]);
                return response()->json(['errors' => $validator->errors()], 422);
            }
            Log::info('Step 1: Validation passed');

            Log::info('Step 2: Finding user by email');
            $user = User::where('email', $request->email)->first();
            
            if (!$user) {
                Log::warning('Step 2: User not found', ['email' => $request->email]);
                return response()->json(['message' => 'Invalid credentials.'], 401);
            }
            Log::info('Step 2: User found', ['user_id' => $user->id]);

            Log::info('Step 3: Checking if account is activated');
            if (!$user->is_activated) {
                Log::warning('Step 3: Account not activated', [
                    'email' => $request->email,
                    'user_id' => $user->id
                ]);
                return response()->json(['message' => 'Account not activated. Please verify your email first.'], 401);
            }
            Log::info('Step 3: Account is activated');

            Log::info('Step 4: Verifying password');
            if (!Hash::check($request->password, $user->password)) {
                Log::warning('Step 4: Password verification failed', [
                    'email' => $request->email,
                    'user_id' => $user->id
                ]);
                return response()->json(['message' => 'Invalid credentials.'], 401);
            }
            Log::info('Step 4: Password verified successfully');

            Log::info('Step 5: Deleting existing tokens');
            $deletedTokens = $user->tokens()->delete();
            Log::info('Step 5: Deleted existing tokens', ['deleted_count' => $deletedTokens]);

            Log::info('Step 6: Creating new API token');
            $token = $user->createToken('api_token')->plainTextToken;
            Log::info('Step 6: API token created successfully');

            Log::info('=== LOGIN COMPLETED SUCCESSFULLY ===', [
                'email' => $user->email,
                'user_id' => $user->id
            ]);

            return response()->json([
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'company' => $user->company,
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('=== LOGIN FAILED ===', [
                'email' => $request->email ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Login failed. Please try again later.'
            ], 500);
        }
    }

    public function resendOtp(Request $request)
    {
        Log::info('=== RESEND OTP PROCESS STARTED ===', ['email' => $request->email]);
        
        try {
            Log::info('Step 1: Starting validation');
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ]);

            if ($validator->fails()) {
                Log::warning('Step 1: Validation failed', ['errors' => $validator->errors()]);
                return response()->json(['errors' => $validator->errors()], 422);
            }
            Log::info('Step 1: Validation passed');

            Log::info('Step 2: Finding user by email');
            $user = User::where('email', $request->email)->first();
            
            if (!$user) {
                Log::warning('Step 2: User not found', ['email' => $request->email]);
                return response()->json(['message' => 'User not found.'], 404);
            }
            Log::info('Step 2: User found', ['user_id' => $user->id]);

            Log::info('Step 3: Checking if account is already activated');
            if ($user->is_activated) {
                Log::info('Step 3: Account already activated', ['user_id' => $user->id]);
                return response()->json(['message' => 'Account is already activated.'], 400);
            }
            Log::info('Step 3: Account not activated - proceeding with OTP resend');

            Log::info('Step 4: Starting database transaction');
            DB::beginTransaction();

            Log::info('Step 5: Cleaning up expired OTPs');
            $this->cleanupExpiredOtps();

            Log::info('Step 6: Deleting existing unverified OTPs');
            $deletedOtps = UserOtp::where('user_id', $user->id)
                ->where('is_verified', false)
                ->delete();
            Log::info('Step 6: Deleted unverified OTPs', ['deleted_count' => $deletedOtps]);

            Log::info('Step 7: Generating new OTP');
            $otp = $this->generateOtp();
            $expiresAt = Carbon::now()->addMinutes(config('auth.otp.expiry', 10));
            Log::info('Step 7: OTP generated', ['otp' => $otp, 'expires_at' => $expiresAt]);

            Log::info('Step 8: Creating new OTP record');
            UserOtp::create([
                'user_id' => $user->id,
                'otp_code' => $otp,
                'expires_at' => $expiresAt,
                'is_verified' => false,
            ]);
            Log::info('Step 8: OTP record created');

            Log::info('Step 9: Attempting to send OTP email');
            try {
                $emailService = new EmailService();
                $emailService->sendOtpEmail($user->email, $otp, $user->first_name . ' ' . $user->last_name);
                Log::info('Step 9: OTP email sent successfully', ['email' => $user->email, 'user_id' => $user->id]);
            } catch (Exception $e) {
                Log::error('Step 9: FAILED to send OTP email', [
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                Log::info('Step 9: Rolling back transaction due to email failure');
                DB::rollBack();
                return response()->json([
                    'message' => 'Failed to resend OTP. Please try again.'
                ], 500);
            }

            Log::info('Step 10: Committing transaction');
            DB::commit();
            Log::info('Step 10: Transaction committed successfully');

            Log::info('=== RESEND OTP COMPLETED SUCCESSFULLY ===');
            return response()->json([
                'message' => 'OTP resent successfully. Please check your email.'
            ], 200);

        } catch (Exception $e) {
            Log::error('=== RESEND OTP FAILED ===', [
                'email' => $request->email ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            Log::info('Rolling back transaction due to error');
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to resend OTP. Please try again later.'
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        Log::info('=== LOGOUT PROCESS STARTED ===');
        
        try {
            $user = $request->user();
            
            if ($user) {
                Log::info('Step 1: User found, deleting current token', ['user_id' => $user->id]);
                $user->currentAccessToken()->delete();
                Log::info('Step 1: Current token deleted successfully', [
                    'email' => $user->email,
                    'user_id' => $user->id
                ]);
            } else {
                Log::info('Step 1: No authenticated user found');
            }

            Log::info('=== LOGOUT COMPLETED SUCCESSFULLY ===');
            return response()->json(['message' => 'Logged out successfully.'], 200);

        } catch (Exception $e) {
            Log::error('=== LOGOUT FAILED ===', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Logout failed. Please try again.'
            ], 500);
        }
    }

    private function cleanupExpiredOtps()
    {
        Log::info('Starting cleanup of expired OTPs');
        try {
            $expiredOtps = UserOtp::where('expires_at', '<', Carbon::now())->get();
            Log::info('Found expired OTPs', ['count' => $expiredOtps->count(), 'otps' => $expiredOtps->toArray()]);
            
            $deletedCount = UserOtp::where('expires_at', '<', Carbon::now())->delete();
            
            if ($deletedCount > 0) {
                Log::info("Cleaned up expired OTPs successfully", ['deleted_count' => $deletedCount]);
            } else {
                Log::info("No expired OTPs to clean up");
            }
        } catch (Exception $e) {
            Log::error('Failed to cleanup expired OTPs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function generateOtp()
    {
        Log::info('Generating OTP');
        $min = config('auth.otp.min', 100000);
        $max = config('auth.otp.max', 999999);
        $otp = rand($min, $max);
        Log::info('OTP generated', ['otp' => $otp, 'min' => $min, 'max' => $max]);
        return $otp;
    }
}