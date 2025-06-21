<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\OAuth;
use League\OAuth2\Client\Provider\Google;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Exception;

class EmailService
{
    private $transporter;
    private $clientId;
    private $clientSecret;
    private $refreshToken;
    private $emailUser;
    private $initialized = false;

    public function __construct()
    {
        $this->clientId = config('services.gmail.client_id');
        $this->clientSecret = config('services.gmail.client_secret');
        $this->refreshToken = config('services.gmail.refresh_token');
        $this->emailUser = config('services.gmail.email_user');
        
        Log::info('EmailService initialized with config', [
            'client_id_set' => !empty($this->clientId),
            'client_secret_set' => !empty($this->clientSecret),
            'refresh_token_set' => !empty($this->refreshToken),
            'email_user' => $this->emailUser
        ]);
        
        $this->initializeTransporter();
    }

    private function initializeTransporter(): void
    {
        try {
            $this->createTransporter();
            Log::info('Email transporter successfully initialized');
            $this->initialized = true;
        } catch (Exception $error) {
            Log::error('Failed to initialize email transporter', [
                'error' => $error->getMessage(),
                'trace' => $error->getTraceAsString()
            ]);
            $this->initialized = false;
        }
    }

    private function createTransporter(): void
    {
        try {
            // Validate configuration
            if (!$this->clientId || !$this->clientSecret || !$this->refreshToken || !$this->emailUser) {
                $missing = [];
                if (!$this->clientId) $missing[] = 'client_id';
                if (!$this->clientSecret) $missing[] = 'client_secret';
                if (!$this->refreshToken) $missing[] = 'refresh_token';
                if (!$this->emailUser) $missing[] = 'email_user';
                
                throw new Exception('Missing required email configuration: ' . implode(', ', $missing));
            }

            Log::info('Creating Google OAuth2 provider');
            $provider = new Google([
                'clientId' => $this->clientId,
                'clientSecret' => $this->clientSecret,
                'redirectUri' => 'https://developers.google.com/oauthplayground',
            ]);

            Log::info('Getting access token');
            $accessToken = $this->getAccessToken($provider);

            if (!$accessToken) {
                throw new Exception('Failed to obtain access token');
            }

            Log::info('Access token obtained successfully');

            // Create PHPMailer instance
            $this->transporter = new PHPMailer(true);
            
            // Enable SMTP debugging for troubleshooting
            $this->transporter->SMTPDebug = SMTP::DEBUG_SERVER;
            $this->transporter->Debugoutput = function($str, $level) {
                Log::info("SMTP Debug: $str");
            };
            
            $this->transporter->isSMTP();
            $this->transporter->Host = 'smtp.gmail.com';
            $this->transporter->Port = 587;
            $this->transporter->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $this->transporter->SMTPAuth = true;
            $this->transporter->AuthType = 'XOAUTH2';
            
            // Set OAuth
            $this->transporter->setOAuth(
                new OAuth([
                    'provider' => $provider,
                    'clientId' => $this->clientId,
                    'clientSecret' => $this->clientSecret,
                    'refreshToken' => $this->refreshToken,
                    'userName' => $this->emailUser,
                ])
            );
            
            $this->transporter->setFrom($this->emailUser, config('app.name', 'Your App'));
            Log::info('PHPMailer transporter created successfully');
            
        } catch (Exception $error) {
            Log::error('Transporter creation failed', [
                'error' => $error->getMessage(),
                'trace' => $error->getTraceAsString()
            ]);
            throw $error;
        }
    }

    private function getAccessToken($provider): ?string
    {
        try {
            Log::info('Attempting to get access token with refresh token');
            
            $accessToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $this->refreshToken
            ]);
            
            $token = $accessToken->getToken();
            Log::info('Access token retrieved successfully', [
                'token_length' => strlen($token),
                'expires_in' => $accessToken->getExpires()
            ]);
            
            return $token;
        } catch (Exception $e) {
            Log::error('Failed to get access token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    public function sendEmail(array $options): bool
    {
        try {
            Log::info('Attempting to send email', [
                'to' => $options['to'] ?? 'not set',
                'subject' => $options['subject'] ?? 'not set'
            ]);

            if (!$this->initialized || !$this->transporter) {
                Log::info('Transporter not initialized, creating now...');
                $this->createTransporter();
                $this->initialized = true;
            }

            // Clear previous recipients and attachments
            $this->transporter->clearAddresses();
            $this->transporter->clearCCs();
            $this->transporter->clearBCCs();
            $this->transporter->clearAttachments();

            // Set recipients
            if (is_array($options['to'])) {
                foreach ($options['to'] as $email) {
                    $this->transporter->addAddress($email);
                }
            } else {
                $this->transporter->addAddress($options['to']);
            }

            // Set CC if provided
            if (isset($options['cc'])) {
                if (is_array($options['cc'])) {
                    foreach ($options['cc'] as $email) {
                        $this->transporter->addCC($email);
                    }
                } else {
                    $this->transporter->addCC($options['cc']);
                }
            }

            // Set BCC if provided
            if (isset($options['bcc'])) {
                if (is_array($options['bcc'])) {
                    foreach ($options['bcc'] as $email) {
                        $this->transporter->addBCC($email);
                    }
                } else {
                    $this->transporter->addBCC($options['bcc']);
                }
            }

            $this->transporter->Subject = $options['subject'];

            // Set email content
            if (isset($options['html'])) {
                $this->transporter->isHTML(true);
                $this->transporter->Body = $options['html'];
                if (isset($options['text'])) {
                    $this->transporter->AltBody = $options['text'];
                }
            } else if (isset($options['text'])) {
                $this->transporter->isHTML(false);
                $this->transporter->Body = $options['text'];
            } else {
                throw new Exception('Either html or text content must be provided');
            }

            // Add attachments if provided
            if (isset($options['attachments']) && is_array($options['attachments'])) {
                foreach ($options['attachments'] as $attachment) {
                    if (isset($attachment['path'])) {
                        $this->transporter->addAttachment(
                            $attachment['path'],
                            $attachment['name'] ?? '',
                            $attachment['encoding'] ?? 'base64',
                            $attachment['type'] ?? ''
                        );
                    } else if (isset($attachment['content'])) {
                        $this->transporter->addStringAttachment(
                            $attachment['content'],
                            $attachment['name'] ?? 'attachment',
                            $attachment['encoding'] ?? 'base64',
                            $attachment['type'] ?? 'application/octet-stream'
                        );
                    }
                }
            }

            Log::info('Sending email...');
            $result = $this->transporter->send();
            
            $toEmails = is_array($options['to']) ? implode(', ', $options['to']) : $options['to'];
            Log::info("Email sent successfully", [
                'to' => $toEmails,
                'subject' => $options['subject']
            ]);
            
            return $result;
            
        } catch (Exception $error) {
            Log::error('Failed to send email', [
                'error' => $error->getMessage(),
                'trace' => $error->getTraceAsString(),
                'to' => $options['to'] ?? 'not set'
            ]);
            throw $error;
        }
    }

    public function sendOtpEmail(string $to, string $otp, string $userName = ''): bool
    {
        $subject = 'Your OTP Verification Code';
        $html = $this->getOtpEmailTemplate($otp, $userName);
        $text = $this->getOtpEmailTextTemplate($otp, $userName);
        
        return $this->sendEmail([
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $text
        ]);
    }

    private function getOtpEmailTemplate(string $otp, string $userName): string
    {
        $greeting = $userName ? "Hi {$userName}," : "Hello,";
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>OTP Verification</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #f8f9fa; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { padding: 30px 20px; background-color: #ffffff; }
                .otp-code { 
                    background-color: #e7f3ff; 
                    padding: 20px; 
                    text-align: center; 
                    font-size: 32px; 
                    font-weight: bold; 
                    letter-spacing: 5px; 
                    border-radius: 8px; 
                    margin: 25px 0; 
                    color: #0066cc;
                    border: 2px dashed #0066cc;
                }
                .footer { 
                    font-size: 14px; 
                    color: #666; 
                    margin-top: 30px; 
                    padding-top: 20px; 
                    border-top: 1px solid #eee;
                    text-align: center;
                }
                .warning { color: #d9534f; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin: 0; color: #333;'>Email Verification</h2>
                </div>
                <div class='content'>
                    <p>{$greeting}</p>
                    <p>Please use the following verification code to complete your registration:</p>
                    <div class='otp-code'>{$otp}</div>
                    <p><strong>Important:</strong> This code will expire in <span class='warning'>10 minutes</span>.</p>
                    <p>If you didn't request this verification code, please ignore this email and your account will remain secure.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message, please do not reply to this email.</p>
                    <p>&copy; " . date('Y') . " " . config('app.name') . ". All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    private function getOtpEmailTextTemplate(string $otp, string $userName): string
    {
        $greeting = $userName ? "Hi {$userName}," : "Hello,";
        return "{$greeting}\n\nYour verification code is: {$otp}\n\nThis code will expire in 10 minutes.\n\nIf you didn't request this code, please ignore this email.\n\n---\nThis is an automated message, please do not reply.\n" . config('app.name');
    }

    public function testConnection(): bool
    {
        try {
            if (!$this->initialized) {
                $this->createTransporter();
            }
            
            Log::info('Testing SMTP connection...');
            $this->transporter->smtpConnect();
            $this->transporter->smtpClose();
            Log::info('Email service connection test successful');
            return true;
        } catch (Exception $e) {
            Log::error('Email service connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'configured' => !empty($this->clientId) && !empty($this->clientSecret) && !empty($this->refreshToken),
            'email_user' => $this->emailUser,
            'service' => 'gmail_oauth2'
        ];
    }
}