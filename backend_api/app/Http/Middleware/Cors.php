<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Allow all origins
        $response->headers->set('Access-Control-Allow-Origin', '*');
        
        // Allow all methods
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
        
        // Allow all headers
        $response->headers->set('Access-Control-Allow-Headers', '*');
        
        // Allow credentials
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        
        // Cache preflight requests for 24 hours
        $response->headers->set('Access-Control-Max-Age', '86400');
        
        // Expose headers if needed
        $response->headers->set('Access-Control-Expose-Headers', '*');

        return $response;
    }
} 