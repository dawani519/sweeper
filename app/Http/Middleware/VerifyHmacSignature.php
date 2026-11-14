<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Services\Keys\KeyVault;
use Closure;
use Illuminate\Http\Request;

/**
 * Headers: X-Key-Id, X-Timestamp (unix), X-Signature = hex HMAC-SHA256(secret,
 * "{timestamp}.{METHOD}.{path-with-query}.{raw body}"). Replay window is configurable.
 */
class VerifyHmacSignature
{
    public function __construct(private KeyVault $vault) {}

    public function handle(Request $request, Closure $next)
    {
        $keyId = (string) $request->header('X-Key-Id');
        $ts = (string) $request->header('X-Timestamp');
        $sig = (string) $request->header('X-Signature');

        if ($keyId === '' || ! ctype_digit($ts) || $sig === '') {
            return $this->deny();
        }
        if (abs(time() - (int) $ts) > config('sweeper.api.timestamp_tolerance')) {
            return $this->deny('stale timestamp');
        }

        $client = ApiClient::where('key_id', $keyId)->where('active', true)->first();
        if (! $client) {
            return $this->deny();
        }

        $secret = $this->vault->open($client->secret_envelope, "client:{$client->key_id}");
        $payload = $ts.'.'.$request->method().'.'.$request->getRequestUri().'.'.$request->getContent();

        if (! hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) {
            return $this->deny();
        }

        $request->attributes->set('api_client', $client);

        return $next($request);
    }

    private function deny(string $why = 'unauthorized')
    {
        return response()->json(['error' => $why], 401);
    }
}
