<?php

namespace App\Services\Meta;

class MetaUserDataHasher
{
    /** @var array<string, string> */
    private const BR_STATE_NAMES = [
        'acre' => 'ac',
        'alagoas' => 'al',
        'amapa' => 'ap',
        'amazonas' => 'am',
        'bahia' => 'ba',
        'ceara' => 'ce',
        'distritofederal' => 'df',
        'espiritosanto' => 'es',
        'goias' => 'go',
        'maranhao' => 'ma',
        'matogrosso' => 'mt',
        'matogrossodosul' => 'ms',
        'minasgerais' => 'mg',
        'para' => 'pa',
        'paraiba' => 'pb',
        'parana' => 'pr',
        'pernambuco' => 'pe',
        'piaui' => 'pi',
        'riodejaneiro' => 'rj',
        'riograndedonorte' => 'rn',
        'riograndedosul' => 'rs',
        'rondonia' => 'ro',
        'roraima' => 'rr',
        'santacatarina' => 'sc',
        'saopaulo' => 'sp',
        'sergipe' => 'se',
        'tocantins' => 'to',
    ];

    public function hashEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * E.164 aceito pela Meta. Incompleto ou tamanho inválido → null (omite ph).
     */
    public function normalizePhone(?string $phone): ?string
    {
        return \App\Support\MetaPhoneNormalizer::normalize($phone);
    }

    public function hashPhone(?string $phone): ?string
    {
        $digits = $this->normalizePhone($phone);

        return $digits !== null ? hash('sha256', $digits) : null;
    }

    public function hashName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/[^\p{L}\s]/u', '', $normalized) ?? '';
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        return $normalized !== '' ? hash('sha256', $normalized) : null;
    }

    public function hashCity(?string $city): ?string
    {
        $normalized = $this->lettersOnly($city);

        return $normalized !== null ? hash('sha256', $normalized) : null;
    }

    public function hashState(?string $state): ?string
    {
        $code = $this->normalizeState($state);

        return $code !== null ? hash('sha256', $code) : null;
    }

    public function normalizeState(?string $state): ?string
    {
        $letters = $this->lettersOnly($state);
        if ($letters === null) {
            return null;
        }

        if (isset(self::BR_STATE_NAMES[$letters])) {
            return self::BR_STATE_NAMES[$letters];
        }

        if (strlen($letters) === 2) {
            return $letters;
        }

        return null;
    }

    public function hashCountry(?string $country): ?string
    {
        if ($country === null || trim($country) === '') {
            return null;
        }

        $letters = $this->lettersOnly($country);
        if ($letters === null) {
            return null;
        }

        $code = match ($letters) {
            'br', 'brasil', 'brazil' => 'br',
            default => strlen($letters) === 2 ? $letters : null,
        };

        return $code !== null ? hash('sha256', $code) : null;
    }

    public function hashCpf(?string $cpf): ?string
    {
        if ($cpf === null || trim($cpf) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $cpf) ?? '';
        if (strlen($digits) !== 11) {
            return null;
        }

        return hash('sha256', $digits);
    }

    public function hashZip(?string $zip): ?string
    {
        if ($zip === null || trim($zip) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $zip) ?? '';

        return $digits !== '' ? hash('sha256', $digits) : null;
    }

    public function hashExternalId(?string $externalId): ?string
    {
        if ($externalId === null || trim($externalId) === '') {
            return null;
        }

        return hash('sha256', strtolower(trim($externalId)));
    }

    /**
     * @param  array<int, string|null>  $externalIds
     * @return array<int, string>
     */
    public function hashExternalIds(array $externalIds): array
    {
        $hashes = [];
        foreach ($externalIds as $externalId) {
            if (! is_string($externalId)) {
                continue;
            }
            $hash = $this->hashExternalId($externalId);
            if ($hash !== null && ! in_array($hash, $hashes, true)) {
                $hashes[] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUserData(MetaEventContext $context): array
    {
        $externalIds = $context->externalIds !== []
            ? $context->externalIds
            : [$context->externalId];
        $externalHashes = $this->hashExternalIds($externalIds);

        $data = array_filter([
            'em' => ($h = $this->hashEmail($context->email)) ? [$h] : null,
            'ph' => ($h = $this->hashPhone($context->phone)) ? [$h] : null,
            'fn' => ($h = $this->hashName($context->firstName)) ? [$h] : null,
            'ln' => ($h = $this->hashName($context->lastName)) ? [$h] : null,
            'ct' => ($h = $this->hashCity($context->city)) ? [$h] : null,
            'st' => ($h = $this->hashState($context->state)) ? [$h] : null,
            'country' => ($h = $this->hashCountry($context->country)) ? [$h] : null,
            'zp' => ($h = $this->hashZip($context->zip)) ? [$h] : null,
            'external_id' => $externalHashes !== [] ? $externalHashes : null,
            'client_ip_address' => $context->clientIp ?: null,
            'client_user_agent' => $context->clientUserAgent ?: null,
            'fbp' => $context->fbp ?: null,
            'fbc' => $context->fbc ?: null,
            'subscription_id' => $context->subscriptionId ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        return $data;
    }

    private function lettersOnly(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        $normalized = strtr($normalized, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        $normalized = preg_replace('/[^a-z]/', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : null;
    }
}
