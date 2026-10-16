<?php

namespace App\Services\Olt;

use RuntimeException;

class VsolModelDetector
{
    public function detect(string $output): array
    {
        $normalized = strtoupper(preg_replace('/\s+/', ' ', trim($output)));
        $matches = [];

        foreach (config('olt_vsol.profiles', []) as $key => $profile) {
            foreach (($profile['detect'] ?? []) as $pattern) {
                if (@preg_match($pattern, $normalized) === 1) {
                    $matches[] = ['key' => $key, 'models' => $profile['models'] ?? [], 'label' => $profile['label'] ?? $key];
                    break;
                }
            }
        }

        $matches = collect($matches)->unique('key')->values()->all();

        if (count($matches) === 1 && count($matches[0]['models']) === 1) {
            return [
                'status' => 'detected',
                'profile' => $matches[0]['key'],
                'model' => $matches[0]['models'][0],
                'label' => $matches[0]['label'],
            ];
        }

        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'matches' => $matches];
        }

        return ['status' => 'unknown'];
    }
}
