<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Arbitrage\Onboarding\DemoProvisioner;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provisión demo de un clic para usuarios nuevos.
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
}
