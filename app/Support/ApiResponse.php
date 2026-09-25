<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Consistent API envelope: { success, message, data } / { success, message, errors }.
 */
trait ApiResponse
{
    protected function ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function fail(string $message, int $status = 422, array $errors = [], ?string $code = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ];
        if ($code) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status);
    }
}
