<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gatekeeping para escrituras: no es autenticación real.
 * La clave es pública (se muestra en el README/demo) y solo filtra bots.
 */
class EnsureApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('slotlock.demo_api_key');
        $provided = (string) $request->header('X-Demo-Key', '');

        if ($provided === '') {
            return $this->reject('missing_demo_key');
        }

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return $this->reject('invalid_demo_key');
        }

        return $next($request);
    }

    private function reject(string $error): Response
    {
        return response()->json([
            'error' => $error,
            'message' => 'Write requests require the X-Demo-Key header. The demo key is public and shown in the README/demo.',
        ], 401);
    }
}
