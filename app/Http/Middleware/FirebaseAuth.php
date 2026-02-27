<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Kreait\Firebase\Auth as FirebaseAuthClient;

class FirebaseAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = (string) $request->header('Authorization', '');

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
            return response()->json(['error' => 'Missing Authorization Bearer token'], 401);
        }

        $idToken = trim((string) ($m[1] ?? ''));
        if ($idToken === '') {
            return response()->json(['error' => 'Empty token'], 401);
        }

        try {
            /** @var FirebaseAuthClient $auth */
            $auth = app('firebase.auth'); // AppServiceProvider singleton

            $verifiedToken = $auth->verifyIdToken($idToken);

            $uid = $verifiedToken->claims()->get('sub');
            if (!is_string($uid) || $uid === '') {
                return response()->json(['error' => 'Invalid token'], 401);
            }

            // Controllers: $request->attributes->get('firebase_uid')
            $request->attributes->set('firebase_uid', $uid);
        } catch (\Throwable $e) {
            // İstersen aç:
            // \Log::warning('Firebase token verify failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid token'], 401);
        }

        return $next($request);
    }
}