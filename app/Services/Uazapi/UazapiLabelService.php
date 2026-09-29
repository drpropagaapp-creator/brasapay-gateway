<?php

namespace App\Services\Uazapi;

use App\Exceptions\UazapiRequestException;
use App\Models\UazapiInstance;
use Illuminate\Support\Facades\Log;

class UazapiLabelService
{
    public const ABANDONED = 'abandoned';

    public const HOT = 'hot';

    public const PAID = 'paid';

    public function __construct(private UazapiClient $client) {}

    public function ensureDefaults(UazapiInstance $instance): void
    {
        $token = (string) ($instance->instance_token ?? '');
        if ($token === '') {
            return;
        }

        $map = is_array($instance->label_map) ? $instance->label_map : [];
        $defined = config('uazapi.labels', []);
        if (! is_array($defined) || $defined === []) {
            return;
        }

        $missing = [];
        foreach (array_keys($defined) as $key) {
            if (! is_string($key)) {
                continue;
            }
            $id = isset($map[$key]) ? trim((string) $map[$key]) : '';
            if ($id === '') {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return;
        }

        try {
            $api = $this->client->using($instance);
            $existing = $this->indexByName($api->listLabels($token));
            $dirty = false;

            foreach ($missing as $key) {
                $spec = is_array($defined[$key] ?? null) ? $defined[$key] : [];
                $name = trim((string) ($spec['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $labelId = $this->extractLabelId($existing[$name] ?? null);
                if ($labelId === null) {
                    $api->editLabel($token, [
                        'labelid' => 'new',
                        'name' => $name,
                        'color' => (int) ($spec['color'] ?? 0),
                        'delete' => false,
                    ]);
                    $existing = $this->indexByName($api->listLabels($token));
                    $labelId = $this->extractLabelId($existing[$name] ?? null);
                }

                if ($labelId !== null) {
                    $map[$key] = $labelId;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $instance->label_map = $map;
                $instance->save();
            }
        } catch (UazapiRequestException $e) {
            Log::warning('UazapiLabelService: falha ao sincronizar etiquetas', [
                'instance_id' => $instance->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function apply(UazapiInstance $instance, string $phone, string $key): void
    {
        $token = (string) ($instance->instance_token ?? '');
        if ($token === '' || $phone === '') {
            return;
        }

        $this->ensureDefaults($instance);
        $instance->refresh();

        $map = is_array($instance->label_map) ? $instance->label_map : [];
        $labelId = isset($map[$key]) ? trim((string) $map[$key]) : '';
        if ($labelId === '') {
            return;
        }

        try {
            $api = $this->client->using($instance);
            $api->setChatLabels($token, [
                'number' => $phone,
                'add_labelid' => $labelId,
            ]);

            if ($key === self::PAID) {
                foreach ([self::ABANDONED, self::HOT] as $removeKey) {
                    $removeId = isset($map[$removeKey]) ? trim((string) $map[$removeKey]) : '';
                    if ($removeId === '') {
                        continue;
                    }
                    $api->setChatLabels($token, [
                        'number' => $phone,
                        'remove_labelid' => $removeId,
                    ]);
                }
            }

            if ($key === self::HOT) {
                $abandonedId = isset($map[self::ABANDONED]) ? trim((string) $map[self::ABANDONED]) : '';
                if ($abandonedId !== '') {
                    $api->setChatLabels($token, [
                        'number' => $phone,
                        'remove_labelid' => $abandonedId,
                    ]);
                }
            }
        } catch (UazapiRequestException $e) {
            Log::debug('UazapiLabelService: falha ao aplicar etiqueta', [
                'instance_id' => $instance->id,
                'key' => $key,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $labels
     * @return array<string, array<string, mixed>>
     */
    private function indexByName(array $labels): array
    {
        $indexed = [];
        foreach ($labels as $label) {
            if (! is_array($label)) {
                continue;
            }
            $name = trim((string) ($label['name'] ?? ''));
            if ($name !== '') {
                $indexed[$name] = $label;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>|null  $label
     */
    private function extractLabelId(?array $label): ?string
    {
        if ($label === null) {
            return null;
        }

        foreach (['labelid', 'id'] as $key) {
            $value = $label[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
