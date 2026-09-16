<?php

namespace App\Http\Controllers;

use App\Lighting\LightingControl;
use App\Lighting\LightingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LightingController extends Controller
{
    public function status(LightingStatus $status): JsonResponse
    {
        return $this->respond($status->get());
    }

    public function control(Request $request, LightingControl $control, LightingStatus $status): JsonResponse
    {
        $this->allowed($request, ['enabled', 'controlEpoch']);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'controlEpoch' => ['nullable', 'string', 'max:128', 'required_if:enabled,false'],
        ]);
        $result = $control->control($request->user()->id, $request->session()->getId(), (bool) $data['enabled'], $data['controlEpoch'] ?? null);

        return $this->result($result, $status);
    }

    public function intent(Request $request, LightingControl $control, LightingStatus $status): JsonResponse
    {
        $this->allowed($request, ['intentId', 'controlEpoch', 'clientSeq', 'target']);
        $kind = $request->input('target.kind');
        $data = $request->validate([
            'intentId' => ['required', 'uuid'],
            'controlEpoch' => ['required', 'string', 'max:128'],
            'clientSeq' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'target' => ['required', $kind === 'white' ? 'array:kind,profile' : 'array:kind,mythosSessionId,color'],
            'target.kind' => ['required', 'in:white,mythos'],
            'target.profile' => ['required_if:target.kind,white', 'prohibited_unless:target.kind,white', 'in:action,encounters'],
            'target.mythosSessionId' => ['required_if:target.kind,mythos', 'prohibited_unless:target.kind,mythos', 'uuid'],
            'target.color' => ['present_if:target.kind,mythos', 'prohibited_unless:target.kind,mythos', 'nullable', 'in:green,yellow,blue'],
        ]);
        // Normalize key order and numbers before hashing idempotent payloads.
        $data['clientSeq'] = (int) $data['clientSeq'];
        $data['target'] = $kind === 'white'
            ? ['kind' => 'white', 'profile' => $data['target']['profile']]
            : ['kind' => 'mythos', 'mythosSessionId' => $data['target']['mythosSessionId'], 'color' => $data['target']['color']];

        return $this->result($control->intent($request->user()->id, $request->session()->getId(), $data), $status);
    }

    private function result(array $result, LightingStatus $status): JsonResponse
    {
        $httpStatus = $result['httpStatus'];
        unset($result['httpStatus']);

        return $this->respond(array_merge($status->get(), $result), $httpStatus);
    }

    private function respond(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }

    private function allowed(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), [...$keys, '_token']) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unexpected lighting fields.']);
        }
    }
}
