<?php

namespace App\Http\Requests;

use App\Exceptions\InvalidIdempotencyKey;
use App\Exceptions\MissingIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El header se valida antes de las reglas: un request sin clave debe
     * devolver 400, no 422 por un body inválido.
     */
    protected function prepareForValidation(): void
    {
        $key = (string) $this->header('Idempotency-Key', '');

        if ($key === '') {
            throw new MissingIdempotencyKey;
        }

        if (! Str::isUuid($key)) {
            throw new InvalidIdempotencyKey;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resource_id' => ['required', 'uuid', 'exists:resources,id'],
            // Acepta ISO 8601 con offset. Sin offset se interpreta en la
            // zona horaria del recurso (lo resuelve el controlador).
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ];
    }
}
