<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\FrontendMessageSent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'message' => ['required', 'string', 'max:250'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        $message = [
            'id' => (string) Str::uuid(),
            'message' => $payload['message'],
            'source' => $payload['source'] ?? 'react-frontend',
            'sent_at' => now()->toIso8601String(),
        ];

        FrontendMessageSent::dispatch($message);

        return response()->json([
            'status' => 'accepted',
            'data' => $message,
        ], 202);
    }
}
