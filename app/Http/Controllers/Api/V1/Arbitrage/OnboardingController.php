<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Arbitrage\Onboarding\DemoProvisioner;
use App\Arbitrage\Onboarding\DemoResetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provisión demo de un clic para usuarios nuevos, y reinicio total del proceso.
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly DemoProvisioner $provisioner)
    {
    }

    public function demo(Request $request): JsonResponse
    {
        $result = $this->provisioner->provision((int) $request->user()->id);

        return response()->json([
            'settings' => $result['setting']->fresh(),
            'simulation' => ['active' => true, 'run' => $result['run']],
        ], 201);
    }

    /**
     * Reinicia el proceso del usuario: borra toda su data de transacciones y
     * challengers/champion y restaura wallets. Acción destructiva e irreversible.
     */
    public function reset(Request $request, DemoResetService $service): JsonResponse
    {
        $deleted = $service->reset((int) $request->user()->id);

        return response()->json([
            'reset' => true,
            'deleted' => $deleted,
        ]);
    }
}
