<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\DepositAddress;
use App\Services\Keys\KeyVault;
use App\Support\Evm\Crypto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AddressController extends Controller
{
    private function client(Request $r): ApiClient
    {
        return $r->attributes->get('api_client');
    }

    /** Create a fresh deposit address; the private key is generated and sealed server-side. */
    public function store(Request $request, KeyVault $vault): JsonResponse
    {
        $data = $request->validate([
            'chain' => ['required', Rule::in(array_keys(config('sweeper.chains')))],
            'external_ref' => ['nullable', 'string', 'max:120'],
        ]);

        $kp = Crypto::generateKeypair();
        $row = new DepositAddress([
            'api_client_id' => $this->client($request)->id,
            'chain' => $data['chain'],
            'address' => $kp['address'],
            'external_ref' => $data['external_ref'] ?? null,
        ]);
        $row->key_envelope = $vault->seal($kp['private'], $row->vaultContext());
        $row->save();
        unset($kp);

        return response()->json(['id' => $row->id, 'chain' => $row->chain, 'address' => $row->address, 'external_ref' => $row->external_ref], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->client($request)->addresses()->latest('id');
        if ($ref = $request->query('external_ref')) {
            $q->where('external_ref', $ref);
        }

        return response()->json($q->paginate(50)->through(fn ($a) => $a->only(['id', 'chain', 'address', 'external_ref', 'last_scanned_at'])));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $a = $this->client($request)->addresses()->findOrFail($id);

        return response()->json($a->only(['id', 'chain', 'address', 'external_ref', 'last_scanned_at'])
            + ['sweeps' => $a->sweeps()->latest('id')->limit(50)->get(['id', 'asset', 'amount', 'status', 'tx_hash', 'confirmed_at'])]);
    }
}
