<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Exceptions\ChainListenerRecoveryException;
use App\Common\Models\ChainListenerRun;
use App\Common\Services\ChainListenerRecoveryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChainListenerRecoveryController
{
    public function __construct(private readonly ChainListenerRecoveryService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->list($request)]);
    }

    public function replay(Request $request, ChainListenerRun $run): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->service->replay($run, $request->user()),
            ], 202);
        } catch (ChainListenerRecoveryException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->status);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Listener run not found.'], 404);
        }
    }

    public function resolve(Request $request, ChainListenerRun $run): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            return response()->json([
                'data' => $this->service->resolve($run, $request->user(), (string) $validated['note']),
            ]);
        } catch (ChainListenerRecoveryException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->status);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Listener run not found.'], 404);
        }
    }
}
