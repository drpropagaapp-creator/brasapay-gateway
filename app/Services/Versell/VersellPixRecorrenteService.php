<?php

namespace App\Services\Versell;

use App\Gateways\Versell\VersellCredentials;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Log;

/**
 * Pix Automático Versell (padrão Bacen) — mesma API/token/certs do Cash In.
 * Fluxo Jornada 3: locrec → cob → rec → GET rec?txid (QR composto).
 */
class VersellPixRecorrenteService
{
    public function __construct(
        private readonly array $credentials,
        private readonly VersellHttpClient $client = new VersellHttpClient()
    ) {}

    /**
     * POST /locrec
     *
     * @return array{id: int, location?: string, criacao?: string}
     */
    public function createLocRec(): array
    {
        $response = $this->request('POST', '/locrec', null);
        $data = $this->jsonOrFail($response, 'criar location de recorrência');
        if (empty($data['id'])) {
            Log::warning('VersellPixRecorrenteService createLocRec invalid', ['keys' => array_keys($data)]);
            throw new \RuntimeException('Versell: não foi possível criar o location de recorrência.');
        }

        return $data;
    }

    /**
     * PUT /cob/{txid} — cobrança imediata da Jornada 3.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{txid: string, copy_paste?: string|null, qrcode?: string|null, loc?: array}
     */
    public function createCobWithTxid(
        string $txid,
        float $amount,
        array $consumer,
        string $pixKey,
        string $solicitacaoPagador = ''
    ): array {
        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?: '';
        if (strlen($document) < 11) {
            $document = '00000000000';
        }

        $devedor = ['nome' => mb_substr((string) ($consumer['name'] ?? ''), 0, 200)];
        if (strlen($document) === 14) {
            $devedor['cnpj'] = $document;
        } else {
            $devedor['cpf'] = substr($document, 0, 11);
        }

        $body = [
            'calendario' => ['expiracao' => 3600],
            'devedor' => $devedor,
            'valor' => [
                'original' => number_format(round($amount, 2), 2, '.', ''),
            ],
            'chave' => $pixKey,
            'solicitacaoPagador' => $solicitacaoPagador !== '' ? $solicitacaoPagador : 'Pedido PIX automático',
            'infoAdicionais' => [
                ['nome' => 'order_ref', 'valor' => mb_substr($txid, 0, 50)],
            ],
        ];

        $response = $this->request('PUT', '/cob/'.$txid, $body);
        $data = $this->jsonOrFail($response, 'criar cobrança imediata');
        $returnedTxid = trim((string) ($data['txid'] ?? $txid));
        if ($returnedTxid === '') {
            throw new \RuntimeException('Versell: cobrança imediata sem txid.');
        }

        $copyPaste = $data['pixCopiaECola'] ?? $data['pix_copia_e_cola'] ?? null;
        $copyPaste = is_string($copyPaste) && $copyPaste !== '' ? $copyPaste : null;

        return [
            'txid' => $returnedTxid,
            'copy_paste' => $copyPaste,
            'qrcode' => null,
            'loc' => is_array($data['loc'] ?? null) ? $data['loc'] : [],
        ];
    }

    /**
     * POST /rec — Jornada 3 (vincula loc + txid da cob).
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{idRec: string, status?: string}
     */
    public function createRecurrence(
        int $locId,
        string $txidCob,
        array $consumer,
        float $valorRec,
        string $dataInicial,
        string $dataFinal,
        string $contrato = '',
        string $objeto = 'Assinatura',
        string $periodicidade = 'MENSAL'
    ): array {
        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?: '';
        if (strlen($document) < 11) {
            $document = '00000000000';
        }

        $devedor = ['nome' => mb_substr((string) ($consumer['name'] ?? ''), 0, 200)];
        if (strlen($document) === 14) {
            $devedor['cnpj'] = $document;
        } else {
            $devedor['cpf'] = substr($document, 0, 11);
        }

        $contratoVal = $contrato !== '' ? $contrato : str_pad((string) time(), 8, '0', STR_PAD_LEFT);
        $body = [
            'loc' => $locId,
            'vinculo' => [
                'contrato' => preg_replace('/\D/', '', $contratoVal) ?: $contratoVal,
                'devedor' => $devedor,
                'objeto' => mb_substr($objeto, 0, 140),
            ],
            'calendario' => [
                'dataInicial' => $dataInicial,
                'dataFinal' => $dataFinal,
                'periodicidade' => $this->normalizePeriodicidade($periodicidade),
            ],
            'valor' => [
                'valorRec' => number_format(round($valorRec, 2), 2, '.', ''),
            ],
            'politicaRetentativa' => 'PERMITE_3R_7D',
            'ativacao' => [
                'dadosJornada' => [
                    'txid' => $txidCob,
                ],
            ],
        ];

        $response = $this->request('POST', '/rec', $body);
        $data = $this->jsonOrFail($response, 'criar recorrência');
        if (empty($data['idRec'])) {
            Log::warning('VersellPixRecorrenteService createRecurrence invalid', ['keys' => array_keys($data)]);
            throw new \RuntimeException('Versell: não foi possível criar a recorrência PIX automático.');
        }

        return $data;
    }

    /**
     * GET /rec/{idRec} (?txid= para QR composto).
     *
     * @return array<string, mixed>
     */
    public function getRecurrence(string $idRec, ?string $txid = null): array
    {
        $path = '/rec/'.rawurlencode($idRec);
        if ($txid !== null && $txid !== '') {
            $path .= '?txid='.rawurlencode($txid);
        }

        $response = $this->request('GET', $path, null);

        return $this->jsonOrFail($response, 'consultar recorrência');
    }

    public function isRecorrenciaAprovada(string $idRec): bool
    {
        $data = $this->getRecurrence($idRec);

        return strtoupper(trim((string) ($data['status'] ?? ''))) === 'APROVADA';
    }

    /**
     * GET /cobr/{txid}
     *
     * @return array<string, mixed>
     */
    public function getCobranca(string $txid): array
    {
        $response = $this->request('GET', '/cobr/'.rawurlencode($txid), null);

        return $this->jsonOrFail($response, 'consultar cobrança recorrente');
    }

    public static function periodicidadeFromInterval(?string $interval): string
    {
        return match ($interval) {
            SubscriptionPlan::INTERVAL_WEEKLY => 'SEMANAL',
            SubscriptionPlan::INTERVAL_QUARTERLY => 'TRIMESTRAL',
            SubscriptionPlan::INTERVAL_SEMI_ANNUAL => 'SEMESTRAL',
            SubscriptionPlan::INTERVAL_ANNUAL => 'ANUAL',
            default => 'MENSAL',
        };
    }

    /**
     * PUT /cobr/{txid} ou POST /cobr.
     *
     * @param  array{name?: string, document?: string, email?: string}  $devedor
     * @return array<string, mixed>
     */
    public function createCobrancaRecorrente(
        string $idRec,
        float $valor,
        string $dataDeVencimento,
        ?string $txid = null,
        array $devedor = [],
        string $infoAdicional = ''
    ): array {
        $body = [
            'idRec' => $idRec,
            'valor' => ['original' => number_format(round($valor, 2), 2, '.', '')],
            'calendario' => ['dataDeVencimento' => $dataDeVencimento],
            'ajusteDiaUtil' => true,
            'devedor' => $this->buildCobrDevedor($devedor),
        ];
        if ($infoAdicional !== '') {
            $body['infoAdicional'] = $infoAdicional;
        }

        if ($txid !== null && $txid !== '') {
            $response = $this->request('PUT', '/cobr/'.$txid, $body);
        } else {
            $response = $this->request('POST', '/cobr', $body);
        }

        $data = $this->jsonOrFail($response, 'criar cobrança recorrente');
        if (empty($data['idRec']) && empty($data['txid'])) {
            Log::warning('VersellPixRecorrenteService createCobrancaRecorrente invalid', ['keys' => array_keys($data)]);
            throw new \RuntimeException('Versell: não foi possível criar a cobrança recorrente.');
        }

        return $data;
    }

    /**
     * PATCH /rec/{idRec} — cancela a recorrência no PSP.
     *
     * @return array<string, mixed>
     */
    public function cancelRecurrence(string $idRec): array
    {
        $response = $this->request('PATCH', '/rec/'.rawurlencode($idRec), [
            'status' => 'CANCELADA',
        ]);

        return $this->jsonOrFail($response, 'cancelar recorrência', [409]);
    }

    /**
     * PATCH /cobr/{txid} — cancela cobrança recorrente ainda não liquidada.
     *
     * @return array<string, mixed>
     */
    public function cancelCobranca(string $txid): array
    {
        $response = $this->request('PATCH', '/cobr/'.rawurlencode($txid), [
            'status' => 'CANCELADA',
        ]);

        return $this->jsonOrFail($response, 'cancelar cobrança recorrente', [409]);
    }

    /**
     * POST /cobr/{txid}/retentativa/{data}
     *
     * @return array<string, mixed>
     */
    public function requestRetentativa(string $txid, ?string $data = null): array
    {
        $day = $data !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) === 1
            ? $data
            : now()->toDateString();
        $response = $this->request(
            'POST',
            '/cobr/'.rawurlencode($txid).'/retentativa/'.$day,
            null
        );

        return $this->jsonOrFail($response, 'solicitar retentativa');
    }

    /**
     * @return \Illuminate\Http\Client\Response
     */
    private function request(string $method, string $path, ?array $json)
    {
        if (! VersellCredentials::isCashInReady($this->credentials)) {
            throw new \RuntimeException('Versell: credenciais Cash In incompletas para Pix Automático.');
        }

        try {
            return $this->client->request(
                VersellCredentials::API_CASH_IN,
                $this->credentials,
                $method,
                $path,
                $json
            );
        } catch (\Throwable $e) {
            Log::warning('VersellPixRecorrenteService request failed', [
                'gateway' => 'versell',
                'endpoint' => $path,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
            throw new \RuntimeException('Versell Pix Automático: falha de comunicação.', 0, $e);
        }
    }

    /**
     * @param  \Illuminate\Http\Client\Response  $response
     * @param  list<int>  $alsoOk
     * @return array<string, mixed>
     */
    private function jsonOrFail($response, string $action, array $alsoOk = []): array
    {
        if (! $response->successful() && ! in_array($response->status(), $alsoOk, true)) {
            $problem = VersellProblemDetails::fromResponse($response->json(), $response->status(), $response->body());
            Log::warning('VersellPixRecorrenteService rejected', [
                'gateway' => 'versell',
                'action' => $action,
                'status' => $problem['status'],
                'error' => $problem['message'],
            ]);
            throw new \RuntimeException('Versell: '.$problem['message']);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array{name?: string, nome?: string, document?: string, cpf?: string, cnpj?: string, email?: string, logradouro?: string, cidade?: string, uf?: string, cep?: string}  $devedor
     * @return array<string, string>
     */
    private function buildCobrDevedor(array $devedor): array
    {
        $document = preg_replace('/\D/', '', (string) ($devedor['document'] ?? $devedor['cpf'] ?? $devedor['cnpj'] ?? '')) ?: '';
        $body = array_filter([
            'nome' => $devedor['name'] ?? $devedor['nome'] ?? null,
            'email' => $devedor['email'] ?? null,
            'logradouro' => $devedor['logradouro'] ?? null,
            'cidade' => $devedor['cidade'] ?? null,
            'uf' => $devedor['uf'] ?? null,
            'cep' => $devedor['cep'] ?? null,
        ]);
        if (strlen($document) === 14) {
            $body['cnpj'] = $document;
        } elseif (strlen($document) >= 11) {
            $body['cpf'] = substr($document, 0, 11);
        }

        return $body;
    }

    private function normalizePeriodicidade(string $periodicidade): string
    {
        $value = strtoupper(trim($periodicidade));
        $allowed = ['SEMANAL', 'MENSAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL'];

        return in_array($value, $allowed, true) ? $value : 'MENSAL';
    }
}
