<?php

namespace App\Services;

use App\Models\MemberStudentActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderManualRefund;
use App\Support\PlatformCompanySettings;
use App\Support\PlatformCustomerDirectory;
use Carbon\Carbon;
use FPDF;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carta de defesa administrativa para contestação / chargeback, a partir dos registros do pedido.
 * Só afirma fatos presentes no banco: não inventa WhatsApp, bandeira/final de cartão ou estorno.
 */
class ChargebackDefensePdfService
{
    public function __construct(
        protected MemberProgressService $progressService,
    ) {}

    public function filename(Order $order): string
    {
        return sprintf('contestacao-pedido-%s-%s.pdf', $order->id, now()->format('Y-m-d'));
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDocument(Order $order): array
    {
        $order->loadMissing(['product', 'user', 'tenantOwner', 'orderItems.product']);

        $legalConfigured = PlatformCompanySettings::legalName();
        $legalName = PlatformCompanySettings::documentCompanyName();
        $cnpj = PlatformCompanySettings::cnpjFormatted();

        $customer = $order->user;
        $product = $order->product;
        $enrolledAt = $this->enrolledAt($customer, $product);
        $paidAt = $this->paidAt($order);
        $accessEvents = $this->accessEvents($customer, $product);
        $usageEvents = array_values(array_filter(
            $accessEvents,
            fn (array $event) => ($event['event'] ?? '') !== MemberStudentActivityLog::EVENT_ENROLLED
        ));
        $refund = $this->refundBlock($order);
        $card = $this->cardDetails($order);
        $progress = $this->progressBlock($customer, $product);

        $delivered = $enrolledAt !== null;
        $hasUsage = $usageEvents !== [];
        $approved = in_array($order->status, ['completed', 'refunded', 'disputed', 'refund_pending'], true);

        return [
            'company' => [
                'legal_name' => $legalName,
                'legal_name_configured' => $legalConfigured !== '',
                'cnpj' => $cnpj !== '' ? $cnpj : null,
            ],
            'subject' => $this->subjectLine($delivered, $refund !== null),
            'intro' => $this->introParagraph($legalName, $delivered, $refund !== null),
            'identification' => $this->identificationLines($order, $product, $card, $paidAt),
            'delivery' => [
                'granted' => $delivered,
                'student_name' => $this->studentName($order, $customer),
                'student_email' => $this->studentEmail($order, $customer),
                'student_cpf' => $this->studentCpf($order, $customer),
                'product_name' => $this->productName($order, $product),
                'granted_at' => $enrolledAt,
                'modality' => $this->deliveryModality($product),
                'situation' => $delivered
                    ? 'Produto entregue, com acesso concedido'
                    : 'Não há registro de concessão de acesso a este produto',
                'progress' => $progress,
            ],
            'timeline' => $this->compactTimeline($order, $paidAt, $enrolledAt, $usageEvents),
            'access_events' => $accessEvents,
            'usage_events' => $usageEvents,
            'has_usage' => $hasUsage,
            'refund' => $refund,
            'facts' => $this->facts($order, $delivered, $enrolledAt, $hasUsage, $refund !== null, $approved),
            'closing' => $this->closingParagraphs($legalName, $delivered, $refund !== null),
            'generated_at' => now()->timezone(config('app.timezone')),
        ];
    }

    public function renderPdf(Order $order): string
    {
        $doc = $this->buildDocument($order);
        $company = $doc['company']['legal_name'];

        $pdf = new FPDF;
        $pdf->SetCompression(false);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $pdf->SetTitle($this->latin1('DEFESA ADMINISTRATIVA – CONTESTAÇÃO DE TRANSAÇÃO'));

        $pdf->SetFont('Arial', 'B', 14);
        $pdf->MultiCell(0, 7, $this->latin1('DEFESA ADMINISTRATIVA – CONTESTAÇÃO DE TRANSAÇÃO'));
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->MultiCell(0, 6, $this->latin1($company));
        if (! empty($doc['company']['cnpj'])) {
            $pdf->SetFont('Arial', '', 10);
            $this->line($pdf, 'CNPJ: '.$doc['company']['cnpj']);
        }
        if (empty($doc['company']['legal_name_configured'])) {
            $pdf->SetFont('Arial', 'I', 8);
            $this->line($pdf, 'Razão social não cadastrada em Configurações → Dados da plataforma.');
        }

        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 10);
        $this->line($pdf, 'Assunto: '.$doc['subject']);
        $pdf->Ln(2);
        $pdf->SetFont('Arial', '', 10);
        $this->line($pdf, 'Prezados,');
        $pdf->Ln(1);
        $this->line($pdf, $doc['intro']);

        $this->sectionTitle($pdf, '1. IDENTIFICAÇÃO DA TRANSAÇÃO');
        foreach ($doc['identification'] as $row) {
            $this->bullet($pdf, $row['label'].': '.$row['value']);
        }

        $this->sectionTitle($pdf, '2. COMPROVAÇÃO DE ENTREGA DO PRODUTO DIGITAL');
        $delivery = $doc['delivery'];
        if ($delivery['granted']) {
            $this->line($pdf, 'Conforme os registros internos de gerenciamento da área de membros, o produto digital foi disponibilizado ao comprador após a confirmação do pagamento.');
        } else {
            $this->line($pdf, 'Até a emissão deste documento, não há registro interno de concessão de acesso ao produto vinculado a este pedido.');
        }
        $pdf->Ln(1);
        $pdf->SetFont('Arial', 'B', 10);
        $this->line($pdf, 'Dados de identificação do aluno:');
        $pdf->SetFont('Arial', '', 10);
        $this->bullet($pdf, 'Nome: '.$delivery['student_name']);
        $this->bullet($pdf, 'E-mail cadastrado: '.$delivery['student_email']);
        if ($delivery['student_cpf']) {
            $this->bullet($pdf, 'CPF: '.$delivery['student_cpf']);
        }
        $this->bullet($pdf, 'Produto disponibilizado: '.$delivery['product_name']);
        $this->bullet($pdf, 'Data e horário da concessão do acesso: '.$this->formatDateTime($delivery['granted_at'], true));
        $this->bullet($pdf, 'Modalidade de entrega: '.$delivery['modality']);
        $this->bullet($pdf, 'Situação da entrega: '.$delivery['situation']);
        if (is_array($delivery['progress']) && (int) ($delivery['progress']['total'] ?? 0) > 0) {
            $this->bullet($pdf, sprintf(
                'Progresso na área de membros: %s / %s aulas (%s%%)',
                $delivery['progress']['completed'] ?? 0,
                $delivery['progress']['total'] ?? 0,
                $delivery['progress']['percent'] ?? 0
            ));
        }

        $pdf->Ln(1);
        $pdf->SetFont('Arial', 'B', 10);
        $this->line($pdf, 'Linha do tempo registrada no sistema:');
        $pdf->SetFont('Arial', '', 10);
        foreach ($doc['timeline'] as $row) {
            $this->line($pdf, $this->formatDateTime($row['at'], $row['with_seconds'] ?? false).' — '.$row['event']);
        }

        if ($delivery['granted']) {
            $pdf->Ln(1);
            $this->line($pdf, 'Os registros demonstram a disponibilização do produto digital ao e-mail cadastrado, evidenciando o cumprimento da obrigação de entrega digital.');
            $this->line($pdf, 'Ressaltamos que o registro de concessão de acesso comprova a disponibilização do conteúdo ao aluno, não sendo apresentado como comprovação de login ou consumo efetivo do material.');
        }

        if ($doc['has_usage']) {
            $pdf->Ln(1);
            $pdf->SetFont('Arial', 'B', 10);
            $this->line($pdf, 'Histórico de acessos (data, evento e IP):');
            $pdf->SetFont('Arial', '', 9);
            $events = array_slice($doc['usage_events'], 0, 100);
            foreach ($events as $event) {
                $when = $this->formatDateTime($event['at'] ?? null, true);
                $ip = trim((string) ($event['ip'] ?? '')) !== '' ? (string) $event['ip'] : '—';
                $this->line($pdf, $when.'  |  '.($event['label'] ?? '').'  |  IP '.$ip);
            }
            if (count($doc['usage_events']) > 100) {
                $this->line($pdf, '… demais '. (count($doc['usage_events']) - 100).' evento(s) omitidos neste documento.');
            }
        }

        if (is_array($doc['refund'])) {
            $this->sectionTitle($pdf, '3. CANCELAMENTO E ESTORNO ADMINISTRATIVO');
            $refund = $doc['refund'];
            $this->line($pdf, 'Há registro de estorno administrativo desta transação no painel de gerenciamento.');
            $this->bullet($pdf, 'Data do estorno: '.$this->formatDateTime($refund['refunded_at'] ?? null));
            $this->bullet($pdf, 'Iniciado por: '.$refund['initiated_by']);
            if (! empty($refund['reason'])) {
                $this->bullet($pdf, 'Motivo registrado: '.$refund['reason']);
            }
            if (! empty($refund['gateway_note'])) {
                $this->bullet($pdf, 'Retorno da processadora: '.$refund['gateway_note']);
            }
            if (! empty($refund['offline'])) {
                $this->line($pdf, 'O estorno foi lançado de forma manual (offline) no painel.');
            }
            $this->line($pdf, 'Dessa forma, não houve recusa de atendimento à solicitação de cancelamento, conforme os registros internos disponíveis.');
            $this->line($pdf, 'Não há, neste sistema, registro do canal de atendimento pelo qual o cliente teria solicitado o cancelamento. Esse dado não é afirmado neste documento.');
        }

        $section = is_array($doc['refund']) ? '4' : '3';
        $this->sectionTitle($pdf, $section.'. BOA-FÉ E CUMPRIMENTO DAS OBRIGAÇÕES');
        $this->line($pdf, 'A documentação apresentada demonstra que:');
        $n = 1;
        foreach ($doc['facts'] as $fact) {
            $this->line($pdf, $n.'. '.$fact);
            $n++;
        }

        $section = is_array($doc['refund']) ? '5' : '4';
        $this->sectionTitle($pdf, $section.'. SOLICITAÇÃO DE ENCERRAMENTO DA CONTESTAÇÃO');
        foreach ($doc['closing'] as $paragraph) {
            $this->line($pdf, $paragraph);
            $pdf->Ln(1);
        }

        $pdf->Ln(2);
        $this->line($pdf, 'Atenciosamente,');
        $pdf->SetFont('Arial', 'B', 10);
        $this->line($pdf, $company);
        $pdf->SetFont('Arial', '', 10);
        $this->line($pdf, 'Departamento Financeiro e de Contestações');
        $this->line($pdf, 'Data: '.$this->formatLongDate($doc['generated_at']));

        $pdf->Ln(6);
        $pdf->SetFont('Arial', 'I', 8);
        $this->line(
            $pdf,
            'Documento gerado em '.$this->formatDateTime($doc['generated_at'], true)
            .' a partir dos registros internos da plataforma. Pedido #'.$order->id
            .'. Não constitui parecer jurídico e não inclui fatos que o sistema não registrou.'
        );

        return $pdf->Output('S');
    }

    /**
     * @param  array{brand: ?string, last4: ?string}  $card
     * @return list<array{label: string, value: string}>
     */
    private function identificationLines(Order $order, ?Product $product, array $card, ?Carbon $paidAt): array
    {
        $lines = [];
        $gatewayId = trim((string) ($order->gateway_id ?? ''));
        $lines[] = ['label' => 'ID da transação', 'value' => $gatewayId !== '' ? $gatewayId : 'Não informado'];
        $ref = trim((string) ($order->public_reference ?? ''));
        if ($ref !== '') {
            $lines[] = ['label' => 'Referência pública', 'value' => $ref];
        }
        $lines[] = ['label' => 'Pedido interno', 'value' => '#'.$order->id];
        $lines[] = ['label' => 'Produto', 'value' => $this->productName($order, $product)];
        if ($order->tenantOwner?->name) {
            $lines[] = ['label' => 'Infoprodutor', 'value' => (string) $order->tenantOwner->name];
        }
        $lines[] = ['label' => 'Data da compra', 'value' => $this->formatDateTime($order->created_at)];
        if ($paidAt && (! $order->created_at || ! $paidAt->equalTo($order->created_at))) {
            $lines[] = ['label' => 'Pagamento aprovado em', 'value' => $this->formatDateTime($paidAt)];
        }
        $amount = $order->amount !== null ? (float) $order->amount : null;
        $lines[] = ['label' => 'Valor bruto', 'value' => $amount !== null ? $this->money($amount) : 'Não informado'];
        $lines[] = ['label' => 'Forma de pagamento', 'value' => $this->paymentMethodLine($order, $card)];
        $lines[] = ['label' => 'Status atual', 'value' => PlatformCustomerDirectory::orderStatusLabel($order->status)];
        if (in_array($order->status, ['refunded', 'disputed', 'refund_pending', 'cancelled'], true)) {
            $lines[] = ['label' => 'Status original', 'value' => 'Aprovada'];
        } elseif ($order->status === 'completed') {
            $lines[] = ['label' => 'Status original', 'value' => 'Aprovada'];
        }
        $acquirer = $order->acquirerDisplayName();
        if ($acquirer !== '' && $acquirer !== '—') {
            $lines[] = ['label' => 'Adquirente/processadora', 'value' => $acquirer];
        }
        $ip = trim((string) ($order->customer_ip ?? ''));
        if ($ip !== '') {
            $lines[] = ['label' => 'IP do checkout', 'value' => $ip];
        }

        return $lines;
    }

    /**
     * @param  list<array{event: string, label: string, at: ?Carbon, ip: ?string}>  $usageEvents
     * @return list<array{at: ?Carbon, event: string, with_seconds?: bool}>
     */
    private function compactTimeline(Order $order, ?Carbon $paidAt, ?Carbon $enrolledAt, array $usageEvents): array
    {
        $rows = [
            ['at' => $order->created_at, 'event' => 'Criação da transação'],
        ];
        if ($paidAt) {
            $rows[] = ['at' => $paidAt, 'event' => 'Pagamento aprovado'];
        }
        if ($enrolledAt) {
            $rows[] = ['at' => $enrolledAt, 'event' => 'Acesso ao produto concedido', 'with_seconds' => true];
        }
        $firstLogin = collect($usageEvents)->first(
            fn (array $event) => ($event['event'] ?? '') === MemberStudentActivityLog::EVENT_LOGIN
        );
        if (is_array($firstLogin)) {
            $rows[] = [
                'at' => $firstLogin['at'] ?? null,
                'event' => 'Primeiro acesso à área de membros',
                'with_seconds' => true,
            ];
        }

        return $rows;
    }

    /**
     * @return array{refunded_at: ?Carbon, initiated_by: string, reason: ?string, gateway_note: ?string, offline: bool}|null
     */
    private function refundBlock(Order $order): ?array
    {
        $snap = OrderManualRefund::snapshot($order);
        $isRefunded = in_array($order->status, ['refunded', 'refund_pending'], true) || $snap !== null;
        if (! $isRefunded) {
            return null;
        }

        $refundedAt = null;
        if (is_array($snap) && ! empty($snap['refunded_at'])) {
            try {
                $refundedAt = Carbon::parse($snap['refunded_at']);
            } catch (\Throwable) {
                $refundedAt = null;
            }
        }
        if ($refundedAt === null && $order->status === 'refunded' && $order->updated_at) {
            $refundedAt = $order->updated_at;
        }

        $initiated = 'Painel de gerenciamento da transação';
        if (is_array($snap) && ($snap['initiated_by_label'] ?? '') !== '' && ($snap['initiated_by_label'] ?? '') !== '—') {
            $initiated = (string) $snap['initiated_by_label'];
            if (! empty($snap['initiated_by_name'])) {
                $initiated .= ' ('.$snap['initiated_by_name'].')';
            }
        }

        return [
            'refunded_at' => $refundedAt,
            'initiated_by' => $initiated,
            'reason' => is_array($snap) ? ($snap['reason'] ?? null) : null,
            'gateway_note' => is_array($snap) ? ($snap['gateway_refund']['note'] ?? null) : null,
            'offline' => is_array($snap) ? (bool) ($snap['offline'] ?? false) : false,
        ];
    }

    /**
     * @return list<string>
     */
    private function facts(
        Order $order,
        bool $delivered,
        ?Carbon $enrolledAt,
        bool $hasUsage,
        bool $refunded,
        bool $approved,
    ): array {
        $facts = [];
        if ($approved) {
            $facts[] = 'A transação foi devidamente aprovada pela processadora de pagamentos.';
        } else {
            $facts[] = 'O pedido consta nos registros internos com status '.PlatformCustomerDirectory::orderStatusLabel($order->status).'.';
        }
        if ($delivered && $enrolledAt) {
            $facts[] = 'O produto digital adquirido foi disponibilizado ao comprador em '.$this->formatDateTime($enrolledAt, true).'.';
            $facts[] = 'O acesso à área de membros foi concedido ao e-mail cadastrado, conforme registro interno do sistema.';
        } else {
            $facts[] = 'Não há registro interno de concessão de acesso a este produto até a emissão deste documento.';
        }
        if ($hasUsage) {
            $facts[] = 'Há registros posteriores de uso da área de membros (login, aulas ou downloads), com data, horário e IP.';
        }
        if ($refunded) {
            $facts[] = 'A solicitação de cancelamento foi atendida, com realização do estorno administrativo registrado no painel.';
        }

        return $facts;
    }

    /**
     * @return list<string>
     */
    private function closingParagraphs(string $legalName, bool $delivered, bool $refunded): array
    {
        $paragraphs = [
            'Diante dos fatos apresentados, a '.$legalName.' solicita a análise dos registros de entrega da operação.',
        ];

        if ($delivered && $refunded) {
            $paragraphs[] = 'Considerando que o produto foi disponibilizado e que o pagamento já foi objeto de estorno administrativo, requeremos o reconhecimento das providências adotadas e o encerramento da contestação, evitando a ocorrência de reembolso em duplicidade ou débito adicional sobre a mesma transação.';
        } elseif ($delivered) {
            $paragraphs[] = 'Considerando que o produto digital foi disponibilizado ao comprador conforme os registros internos, requeremos o reconhecimento da entrega e o encerramento da contestação.';
        } else {
            $paragraphs[] = 'Requeremos a análise dos registros anexos e o encerramento da contestação na medida em que os fatos ora demonstrados o permitirem.';
        }

        $paragraphs[] = 'Solicitamos, ainda, que seja considerado o histórico cronológico da operação constante deste documento.';
        $paragraphs[] = 'A empresa permanece à disposição para apresentar os registros complementares necessários à análise.';

        return $paragraphs;
    }

    private function subjectLine(bool $delivered, bool $refunded): string
    {
        if ($delivered && $refunded) {
            return 'Comprovação de entrega do produto digital, disponibilização de acesso e estorno administrativo.';
        }
        if ($delivered) {
            return 'Comprovação de entrega do produto digital e disponibilização de acesso.';
        }
        if ($refunded) {
            return 'Esclarecimentos sobre a transação contestada e estorno administrativo.';
        }

        return 'Esclarecimentos e registros relacionados à transação contestada.';
    }

    private function introParagraph(string $legalName, bool $delivered, bool $refunded): string
    {
        $text = 'A '.$legalName.' apresenta, por meio deste documento, os esclarecimentos e registros relacionados à transação contestada';
        if ($delivered && $refunded) {
            return $text.', demonstrando a aprovação do pagamento, a efetiva disponibilização do produto digital ao comprador e o posterior atendimento à solicitação de cancelamento, com estorno realizado administrativamente.';
        }
        if ($delivered) {
            return $text.', demonstrando a aprovação do pagamento e a efetiva disponibilização do produto digital ao comprador.';
        }

        return $text.', com base exclusivamente nos registros internos do sistema.';
    }

    private function paymentMethodLine(Order $order, array $card): string
    {
        $label = trim($order->paymentMethodPushLabel());
        if ($label === '') {
            $label = $order->paymentMethodDisplayLabel();
        }
        $brand = $card['brand'] ?? null;
        $last4 = $card['last4'] ?? null;
        if (is_string($brand) && $brand !== '' && is_string($last4) && $last4 !== '') {
            return $label.' '.$brand.', final '.$last4;
        }
        if (is_string($last4) && $last4 !== '') {
            return $label.', final '.$last4;
        }

        return $label !== '' ? $label : 'Não informado';
    }

    /**
     * @return array{brand: ?string, last4: ?string}
     */
    private function cardDetails(Order $order): array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $last4 = $this->extractLast4($meta);
        $brand = $this->extractBrand($meta);

        return [
            'brand' => $brand,
            'last4' => $last4,
        ];
    }

    private function extractLast4(array $meta): ?string
    {
        foreach (['card_last4', 'last4', 'last_four', 'card_last_four'] as $key) {
            $digits = $this->fourDigits($meta[$key] ?? null);
            if ($digits !== null) {
                return $digits;
            }
        }
        $mask = $meta['card_mask'] ?? null;
        if (is_string($mask) && preg_match('/(\d{4})\s*$/', $mask, $m)) {
            return $m[1];
        }
        $card = $meta['card'] ?? null;
        if (is_array($card)) {
            foreach (['last4', 'last_four', 'last_four_digits'] as $key) {
                $digits = $this->fourDigits($card[$key] ?? null);
                if ($digits !== null) {
                    return $digits;
                }
            }
        }

        return null;
    }

    private function extractBrand(array $meta): ?string
    {
        foreach (['card_brand', 'brand', 'card_brand_name'] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        $card = $meta['card'] ?? null;
        if (is_array($card)) {
            $value = trim((string) ($card['brand'] ?? $card['brand_name'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function fourDigits(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        if (preg_match('/(\d{4})\s*$/', (string) $value, $m)) {
            return $m[1];
        }

        return null;
    }

    private function paidAt(Order $order): ?Carbon
    {
        if (Schema::hasColumn('orders', 'paid_at') && $order->getAttribute('paid_at')) {
            return Carbon::parse($order->getAttribute('paid_at'));
        }
        if ($order->status === 'completed' && $order->updated_at) {
            return $order->updated_at;
        }

        return null;
    }

    private function enrolledAt(?User $customer, ?Product $product): ?Carbon
    {
        if ($customer === null || $product === null || ! Schema::hasTable('product_user')) {
            return null;
        }

        $value = DB::table('product_user')
            ->where('user_id', $customer->id)
            ->where(function ($q) use ($product) {
                $q->where('product_id', $product->id)
                    ->orWhere('product_id', (string) $product->id);
            })
            ->value('created_at');

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * @return list<array{event: string, label: string, at: ?Carbon, ip: ?string}>
     */
    private function accessEvents(?User $customer, ?Product $product): array
    {
        if ($customer === null || $product === null || ! Schema::hasTable('member_student_activity_logs')) {
            return [];
        }

        $logs = MemberStudentActivityLog::query()
            ->where('user_id', $customer->id)
            ->where('product_id', (string) $product->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(300)
            ->get();

        $events = $logs->map(fn (MemberStudentActivityLog $log) => [
            'event' => $log->event,
            'label' => MemberStudentActivityLogService::eventLabel($log->event, $log->subject),
            'at' => $log->created_at,
            'ip' => $log->ip,
        ])->values()->all();

        $enrolledAt = DB::table('product_user')
            ->where('user_id', $customer->id)
            ->where(function ($q) use ($product) {
                $q->where('product_id', $product->id)
                    ->orWhere('product_id', (string) $product->id);
            })
            ->value('created_at');

        if ($enrolledAt) {
            array_unshift($events, [
                'event' => MemberStudentActivityLog::EVENT_ENROLLED,
                'label' => MemberStudentActivityLogService::eventLabel(MemberStudentActivityLog::EVENT_ENROLLED),
                'at' => Carbon::parse($enrolledAt),
                'ip' => null,
            ]);
        }

        return $events;
    }

    /**
     * @return array{completed: int, total: int, percent: int|null}|null
     */
    private function progressBlock(?User $customer, ?Product $product): ?array
    {
        if ($customer === null || $product === null || $product->type !== Product::TYPE_AREA_MEMBROS) {
            return null;
        }

        return [
            'completed' => $this->progressService->completedLessonsCount($product, $customer),
            'total' => $this->progressService->totalLessonsCount($product),
            'percent' => $this->progressService->completionPercent($product, $customer),
        ];
    }

    private function deliveryModality(?Product $product): string
    {
        return match ($product?->type) {
            Product::TYPE_AREA_MEMBROS => 'Digital, por meio de área de membros',
            Product::TYPE_AREA_MEMBROS_EXTERNA => 'Digital, por meio de área de membros externa',
            Product::TYPE_LINK => 'Digital, por meio de link de acesso',
            Product::TYPE_LINK_PAGAMENTO => 'Link de pagamento (sem entrega automática de conteúdo)',
            Product::TYPE_PRODUTO_FISICO => 'Produto físico',
            default => 'Digital',
        };
    }

    private function productName(Order $order, ?Product $product): string
    {
        if ($product?->name) {
            return (string) $product->name;
        }
        $item = $order->orderItems->first();
        if ($item?->product?->name) {
            return (string) $item->product->name;
        }

        return 'Não informado';
    }

    private function studentName(Order $order, ?User $customer): string
    {
        $name = trim((string) ($customer?->name ?? ''));

        return $name !== '' ? $name : 'Não informado';
    }

    private function studentEmail(Order $order, ?User $customer): string
    {
        $email = trim((string) ($customer?->email ?? $order->email ?? ''));

        return $email !== '' ? $email : 'Não informado';
    }

    private function studentCpf(Order $order, ?User $customer): ?string
    {
        $fromUser = PlatformCustomerDirectory::formatCpf($customer?->document ?? null);
        if ($fromUser) {
            return $fromUser;
        }

        return PlatformCustomerDirectory::formatCpf($order->cpf);
    }

    private function money(float $amount): string
    {
        return 'R$ '.number_format($amount, 2, ',', '.');
    }

    private function formatDateTime(mixed $dt, bool $withSeconds = false): string
    {
        $carbon = $this->asCarbon($dt);
        if ($carbon === null) {
            return 'Não informado';
        }
        $carbon = $carbon->timezone(config('app.timezone'));
        if ($withSeconds) {
            return $carbon->format('d/m/Y').', às '.$carbon->format('H').'h'.$carbon->format('i').'min'.$carbon->format('s').'s';
        }

        return $carbon->format('d/m/Y').', às '.$carbon->format('H').'h'.$carbon->format('i');
    }

    private function formatLongDate(mixed $dt): string
    {
        $carbon = $this->asCarbon($dt);
        if ($carbon === null) {
            return 'Não informado';
        }
        $carbon = $carbon->timezone(config('app.timezone'));
        $months = [
            1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
            5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
            9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
        ];
        $month = $months[(int) $carbon->format('n')] ?? $carbon->format('m');

        return $carbon->format('d').' de '.$month.' de '.$carbon->format('Y');
    }

    private function asCarbon(mixed $dt): ?Carbon
    {
        if ($dt instanceof Carbon) {
            return $dt;
        }
        if ($dt instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($dt));
        }
        if (is_string($dt) && trim($dt) !== '') {
            try {
                return Carbon::parse($dt);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function sectionTitle(FPDF $pdf, string $title): void
    {
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 6, $this->latin1($title));
        $pdf->SetFont('Arial', '', 10);
    }

    private function bullet(FPDF $pdf, string $text): void
    {
        $this->line($pdf, '• '.$text);
    }

    private function line(FPDF $pdf, string $text): void
    {
        $pdf->MultiCell(0, 5, $this->latin1($text));
    }

    private function latin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }
}
