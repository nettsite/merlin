<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }

    /**
     * 'unsafe-inline' and 'unsafe-eval' are required by Livewire/Alpine;
     * fonts.googleapis.com / fonts.gstatic.com by the app layouts. In the
     * local environment the Vite dev server (and its HMR websocket) must
     * also be allowed or `composer run dev` serves blocked assets.
     */
    private function contentSecurityPolicy(): string
    {
        $vite = app()->isLocal()
            ? ' http://localhost:5173 http://127.0.0.1:5173 ws://localhost:5173 ws://127.0.0.1:5173'
            : '';

        return "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval'{$vite}; "
            ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com{$vite}; "
            ."font-src 'self' https://fonts.gstatic.com{$vite}; "
            ."img-src 'self' data:; "
            ."connect-src 'self'{$vite}; "
            ."object-src 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self'; "
            ."frame-ancestors 'self'";
    }
}
