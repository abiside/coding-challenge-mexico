<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Http\Controllers\Controller;
use App\Models\WalletBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wallets simuladas por usuario: listar y fondear/editar saldos.
 */
class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $balances = WalletBalance::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('exchange')
            ->orderBy('asset')
            ->get(['id', 'exchange', 'asset', 'available', 'locked', 'version', 'updated_at']);

        return response()->json(['data' => $balances]);
    }

    /**
     * Crea o actualiza el saldo de un (exchange, asset). Útil para el
     * onboarding (depósito inicial) y ajustes manuales.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exchange' => ['required', 'string', 'max:50'],
            'asset' => ['required', 'string', 'max:20'],
            'available' => ['required', 'numeric', 'min:0'],
        ]);

        $balance = WalletBalance::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'exchange' => strtolower($data['exchange']),
                'asset' => strtoupper($data['asset']),
            ],
            ['available' => $data['available']],
        );

        return response()->json(['data' => $balance], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        WalletBalance::where('user_id', $request->user()->id)->where('id', $id)->delete();

        return response()->json(['status' => 'ok']);
    }
}
