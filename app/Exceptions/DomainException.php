<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Errores de dominio con traducción HTTP explícita.
 *
 * Laravel invoca `render()` cuando la excepción llega a la capa HTTP, así que
 * un 409/410/422 no depende de que cada controlador repita el mapeo.
 */
abstract class DomainException extends RuntimeException
{
    abstract public function status(): int;

    abstract public function errorCode(): string;

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode(),
            'message' => $this->getMessage(),
        ], $this->status());
    }
}
