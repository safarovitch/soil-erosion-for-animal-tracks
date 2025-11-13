<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RusleConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RusleConfigController extends Controller
{
    public function __construct(private readonly RusleConfigService $configService)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $defaults = $this->configService->getDefaults();
        $config = $this->configService->getUserConfig($user);

        return response()->json([
            'defaults_version' => $this->configService->getDefaultsVersion(),
            'defaults' => $defaults,
            'overrides' => $config->overrides ?? [],
            'effective' => $config->effective($defaults),
            'last_saved_at' => optional($config->updated_at)?->toIso8601String(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'overrides' => 'array',
            'reset' => 'sometimes|boolean',
        ]);

        $defaults = $this->configService->getDefaults();

        if ($request->boolean('reset')) {
            $overrides = [];
        } else {
            $overrides = $data['overrides'] ?? [];
            $this->assertValidOverrides($overrides, $defaults);
        }

        $config = $this->configService->persistOverrides($request->user(), $overrides);

        return response()->json([
            'message' => 'RUSLE configuration updated',
            'defaults_version' => $this->configService->getDefaultsVersion(),
            'defaults' => $defaults,
            'overrides' => $config->overrides ?? [],
            'effective' => $config->effective($defaults),
            'last_saved_at' => optional($config->updated_at)?->toIso8601String(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertValidOverrides(array $overrides, array $defaults, string $path = 'overrides'): void
    {
        foreach ($overrides as $key => $value) {
            $currentPath = $path . '.' . $key;

            if (!array_key_exists($key, $defaults)) {
                throw ValidationException::withMessages([
                    $currentPath => 'Unknown configuration key.',
                ]);
            }

            $sample = $defaults[$key];

            if (is_array($sample)) {
                if (!is_array($value)) {
                    throw ValidationException::withMessages([
                        $currentPath => 'Value must be an array.',
                    ]);
                }

                if ($this->isList($sample)) {
                    $template = $sample[0] ?? [];
                    foreach ($value as $index => $child) {
                        if (!is_array($child)) {
                            throw ValidationException::withMessages([
                                "{$currentPath}.{$index}" => 'Value must be an array.',
                            ]);
                        }
                        $this->assertValidOverrides($child, $template, "{$currentPath}.{$index}");
                    }
                } else {
                    $this->assertValidOverrides($value, $sample, $currentPath);
                }
            } else {
                if ($sample === null) {
                    continue;
                }

                if (is_bool($sample)) {
                    if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                        throw ValidationException::withMessages([
                            $currentPath => 'Value must be boolean.',
                        ]);
                    }
                } elseif (is_numeric($sample)) {
                    if (!is_numeric($value)) {
                        throw ValidationException::withMessages([
                            $currentPath => 'Value must be numeric.',
                        ]);
                    }
                }
            }
        }
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
