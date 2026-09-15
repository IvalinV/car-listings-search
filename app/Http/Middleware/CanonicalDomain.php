<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.env') !== 'production') {
            return $next($request);
        }

        $forwardedProto = strtolower(trim(explode(',', (string) $request->header('X-Forwarded-Proto', ''), 2)[0]));
        $isHttps = $request->isSecure() || $forwardedProto === 'https';

        if (strtolower($request->getHost()) === 'autosearch.bg' && $isHttps) {
            return $next($request);
        }

        return redirect()->to(
            'https://autosearch.bg'.$request->getRequestUri(),
            Response::HTTP_MOVED_PERMANENTLY,
        );
    }
}
