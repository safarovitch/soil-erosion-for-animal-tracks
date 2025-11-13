<?php

namespace App\Services;

use App\Models\RusleUserConfig;
use App\Models\User;
class RusleConfigService
{
    public function getDefaults(): array
    {
        return config('rusle.defaults', []);
    }

    public function getDefaultsVersion(): ?string
    {
        return config('rusle.version');
    }

    public function getUserConfig(User $user): RusleUserConfig
    {
        return RusleUserConfig::firstOrNew(
            ['user_id' => $user->id],
            [
                'overrides' => [],
                'defaults_version' => $this->getDefaultsVersion(),
            ]
        );
    }

    public function getEffectiveConfig(User $user): array
    {
        $defaults = $this->getDefaults();
        $model = $this->getUserConfig($user);

        return $model->effective($defaults);
    }

    public function persistOverrides(User $user, array $input): RusleUserConfig
    {
        $sanitised = $this->filterOverrides($input);

        $config = $this->getUserConfig($user);
        $config->defaults_version = $this->getDefaultsVersion();
        $config->overrides = $sanitised;
        $config->last_synced_at = now();
        $config->save();

        return $config;
    }

    public function filterOverrides(array $input): array
    {
        $allowedStructure = $this->getDefaults();
        return $this->filterRecursive($input, $allowedStructure);
    }

    private function filterRecursive(array $input, array $allowed): array
    {
        $result = [];

        foreach ($input as $key => $value) {
            if (!array_key_exists($key, $allowed)) {
                continue;
            }

            if (is_array($allowed[$key])) {
                if (!is_array($value)) {
                    continue;
                }

                if ($this->isList($allowed[$key])) {
                    $template = $allowed[$key][0] ?? [];
                    $filteredList = [];
                    foreach ($value as $entry) {
                        if (!is_array($entry)) {
                            continue;
                        }

                        $filtered = $this->filterRecursive($entry, $template);
                        if (!empty($filtered)) {
                            $filteredList[] = $filtered;
                        }
                    }
                    if (!empty($filteredList)) {
                        $result[$key] = $filteredList;
                    }
                } else {
                    $filteredChild = $this->filterRecursive($value, $allowed[$key]);
                    if (!empty($filteredChild)) {
                        $result[$key] = $filteredChild;
                    }
                }
            } else {
                if ($value === null || $value === '') {
                    continue;
                }

                $result[$key] = $this->castToType($value, $allowed[$key]);
            }
        }

        return $result;
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function castToType($value, $sample)
    {
        if (is_bool($sample)) {
            if (is_bool($value)) {
                return $value;
            }

            if (is_string($value)) {
                $lower = strtolower($value);
                if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                    return true;
                }
                if (in_array($lower, ['false', '0', 'no', 'off'], true)) {
                    return false;
                }
            }

            return (bool) $value;
        }

        if (is_int($sample)) {
            return (int) $value;
        }

        if (is_float($sample)) {
            return (float) $value;
        }

        return $value;
    }
}

