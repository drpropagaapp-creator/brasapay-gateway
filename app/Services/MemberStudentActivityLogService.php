<?php

namespace App\Services;

use App\Models\MemberLesson;
use App\Models\MemberStudentActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Csv;
use App\Support\MemberAreaAdminPreview;
use FPDF;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MemberStudentActivityLogService
{
    public const VISIT_SESSION_PREFIX = 'member_student_activity.visit.';

    public const LESSON_VIEW_SESSION_PREFIX = 'member_student_activity.lesson.';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        User $user,
        Product $product,
        string $event,
        ?Request $request = null,
        ?MemberLesson $lesson = null,
        ?string $subject = null,
        ?int $fileIndex = null,
        array $metadata = [],
    ): ?MemberStudentActivityLog {
        if (! Schema::hasTable('member_student_activity_logs')) {
            return null;
        }

        if (! $user->isCliente()) {
            return null;
        }

        $request = $request ?? request();
        if ($request instanceof Request && MemberAreaAdminPreview::isActive($request, $product)) {
            return null;
        }

        if (MemberAreaAdminPreview::isPlatformAuditor($user)) {
            return null;
        }

        $agent = $request instanceof Request ? (string) $request->userAgent() : '';
        if (mb_strlen($agent) > 512) {
            $agent = mb_substr($agent, 0, 512);
        }

        $subject = $subject !== null ? mb_substr($subject, 0, 255) : null;

        try {
            return MemberStudentActivityLog::query()->create([
                'user_id' => $user->id,
                'product_id' => (string) $product->id,
                'member_lesson_id' => $lesson?->id,
                'event' => $event,
                'subject' => $subject,
                'file_index' => $fileIndex,
                'ip' => $request instanceof Request ? $request->ip() : null,
                'user_agent' => $agent !== '' ? $agent : null,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function recordLogin(User $user, Product $product, Request $request): void
    {
        $this->record($user, $product, MemberStudentActivityLog::EVENT_LOGIN, $request);
    }

    public function recordVisitOncePerSession(User $user, Product $product, Request $request): void
    {
        $key = self::VISIT_SESSION_PREFIX.(string) $product->id;
        if ($request->session()->get($key)) {
            return;
        }
        $this->record($user, $product, MemberStudentActivityLog::EVENT_VISIT, $request);
        $request->session()->put($key, true);
    }

    public function recordLessonViewedOncePerSession(User $user, Product $product, MemberLesson $lesson, Request $request): void
    {
        $key = self::LESSON_VIEW_SESSION_PREFIX.(string) $lesson->id;
        if ($request->session()->get($key)) {
            return;
        }
        $this->record(
            $user,
            $product,
            MemberStudentActivityLog::EVENT_LESSON_VIEWED,
            $request,
            $lesson,
            $lesson->title,
        );
        $request->session()->put($key, true);
    }

    public function recordLessonCompleted(User $user, Product $product, MemberLesson $lesson, Request $request): void
    {
        $this->record(
            $user,
            $product,
            MemberStudentActivityLog::EVENT_LESSON_COMPLETED,
            $request,
            $lesson,
            $lesson->title,
        );
    }

    public function recordMaterialDownload(User $user, Product $product, MemberLesson $lesson, Request $request, int $fileIndex, string $fileName): void
    {
        $recent = MemberStudentActivityLog::query()
            ->where('user_id', $user->id)
            ->where('product_id', (string) $product->id)
            ->where('member_lesson_id', $lesson->id)
            ->where('event', MemberStudentActivityLog::EVENT_MATERIAL_DOWNLOADED)
            ->where('file_index', $fileIndex)
            ->where('created_at', '>=', now()->subSeconds(30))
            ->exists();
        if ($recent) {
            return;
        }

        $this->record(
            $user,
            $product,
            MemberStudentActivityLog::EVENT_MATERIAL_DOWNLOADED,
            $request,
            $lesson,
            $fileName !== '' ? $fileName : 'Material',
            $fileIndex,
            ['lesson_title' => $lesson->title],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function dossierFor(User $student, Product $product): array
    {
        $enrolledAt = DB::table('product_user')
            ->where('product_id', $product->id)
            ->where('user_id', $student->id)
            ->value('created_at');

        $orderColumns = ['id', 'amount', 'created_at', 'updated_at'];
        if (Schema::hasColumn('orders', 'paid_at')) {
            $orderColumns[] = 'paid_at';
        }

        $order = Order::query()
            ->where('user_id', $student->id)
            ->where('product_id', $product->id)
            ->where('status', 'completed')
            ->latest()
            ->first($orderColumns);

        $progress = app(MemberProgressService::class);
        $total = $product->type === Product::TYPE_AREA_MEMBROS ? $progress->totalLessonsCount($product) : 0;
        $completed = $product->type === Product::TYPE_AREA_MEMBROS
            ? $progress->completedLessonsCount($product, $student)
            : 0;

        $logs = MemberStudentActivityLog::query()
            ->where('user_id', $student->id)
            ->where('product_id', (string) $product->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        $events = $logs->map(fn (MemberStudentActivityLog $log) => $this->presentEvent(
            $log->event,
            $log->created_at?->toIso8601String(),
            $log->ip,
            $log->subject,
            $log->member_lesson_id,
        ))->values()->all();

        if ($enrolledAt) {
            $events[] = $this->presentEvent(
                MemberStudentActivityLog::EVENT_ENROLLED,
                \Carbon\Carbon::parse($enrolledAt)->toIso8601String(),
                null,
                null,
                null,
            );
        }

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'type' => $product->type,
            ],
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'enrolled_at' => $enrolledAt ? \Carbon\Carbon::parse($enrolledAt)->toIso8601String() : null,
            'order' => $order ? [
                'id' => $order->id,
                'amount' => $order->amount !== null ? (float) $order->amount : null,
                'paid_at' => ($order->getAttribute('paid_at')
                    ? \Carbon\Carbon::parse($order->getAttribute('paid_at'))
                    : ($order->updated_at ?? $order->created_at)
                )?->toIso8601String(),
            ] : null,
            'progress' => [
                'completed' => $completed,
                'total' => $total,
                'percent' => $product->type === Product::TYPE_AREA_MEMBROS
                    ? $progress->completionPercent($product, $student)
                    : null,
            ],
            'events' => $events,
        ];
    }

    /**
     * @param  resource  $handle
     */
    public function writeDossierCsv($handle, User $student, Product $product): void
    {
        $dossier = $this->dossierFor($student, $product);

        Csv::writeBom($handle);
        Csv::writeRow($handle, ['Campo', 'Valor']);
        Csv::writeRow($handle, ['Aluno', $dossier['student']['name'] ?? '']);
        Csv::writeRow($handle, ['E-mail', $dossier['student']['email'] ?? '']);
        Csv::writeRow($handle, ['Produto', $dossier['product']['name'] ?? '']);
        Csv::writeRow($handle, ['Acesso concedido', $this->csvDate($dossier['enrolled_at'] ?? null)]);

        $order = $dossier['order'] ?? null;
        if (is_array($order)) {
            $amount = isset($order['amount']) ? (float) $order['amount'] : null;
            $orderLine = $amount !== null
                ? 'R$ '.number_format($amount, 2, ',', '.')
                : '';
            $paidAt = $this->csvDate($order['paid_at'] ?? null);
            if ($paidAt !== '') {
                $orderLine = trim($orderLine.' · '.$paidAt);
            }
            Csv::writeRow($handle, ['Compra', $orderLine]);
        }

        $progress = $dossier['progress'] ?? null;
        if (is_array($progress) && ($progress['percent'] ?? null) !== null) {
            Csv::writeRow($handle, [
                'Progresso',
                ($progress['completed'] ?? 0).' / '.($progress['total'] ?? 0).' aulas ('.($progress['percent'] ?? 0).'%)',
            ]);
        }

        fwrite($handle, "\n");
        Csv::writeRow($handle, ['Data', 'Evento', 'IP']);

        $events = collect($dossier['events'] ?? [])
            ->sortBy(fn (array $event) => $event['occurred_at'] ?? '')
            ->values();

        foreach ($events as $event) {
            Csv::writeRow($handle, [
                $this->csvDate($event['occurred_at'] ?? null),
                $event['label'] ?? '',
                $event['ip'] ?? '',
            ]);
        }
    }

    public function renderDossierPdf(User $student, Product $product): string
    {
        $dossier = $this->dossierFor($student, $product);
        $appName = (string) config('app.name', 'Stacker');

        $pdf = new FPDF;
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $pdf->SetTitle($this->pdfLatin1('Dossiê de acesso do aluno'));

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, $this->pdfLatin1('Dossiê de acesso do aluno'), 0, 1);
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->MultiCell(0, 5, $this->pdfLatin1(
            'Prova de acesso à área de membros. Documento para anexar em contestação MED.'
        ));
        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $this->pdfLatin1('Identificação'), 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $this->pdfLine($pdf, 'Aluno: '.($dossier['student']['name'] ?? '—'));
        $this->pdfLine($pdf, 'E-mail: '.($dossier['student']['email'] ?? '—'));
        $this->pdfLine($pdf, 'Produto: '.($dossier['product']['name'] ?? '—'));
        $this->pdfLine($pdf, 'Acesso concedido: '.($this->csvDate($dossier['enrolled_at'] ?? null) ?: '—'));

        $order = $dossier['order'] ?? null;
        if (is_array($order)) {
            $amount = isset($order['amount']) ? (float) $order['amount'] : null;
            $orderLine = $amount !== null
                ? 'R$ '.number_format($amount, 2, ',', '.')
                : '—';
            $paidAt = $this->csvDate($order['paid_at'] ?? null);
            if ($paidAt !== '') {
                $orderLine .= ' em '.$paidAt;
            }
            $this->pdfLine($pdf, 'Compra: '.$orderLine);
        }

        $progress = $dossier['progress'] ?? null;
        if (is_array($progress) && ($progress['percent'] ?? null) !== null) {
            $this->pdfLine(
                $pdf,
                'Progresso: '.($progress['completed'] ?? 0).' / '.($progress['total'] ?? 0)
                .' aulas ('.($progress['percent'] ?? 0).'%)'
            );
        }

        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $this->pdfLatin1('Linha do tempo (data, evento e IP)'), 0, 1);
        $pdf->SetFont('Arial', '', 10);

        $events = collect($dossier['events'] ?? [])
            ->sortBy(fn (array $event) => $event['occurred_at'] ?? '')
            ->values();

        if ($events->isEmpty()) {
            $this->pdfLine($pdf, 'Nenhum acesso, aula ou download registrado.');
        } else {
            foreach ($events as $event) {
                $when = $this->csvDate($event['occurred_at'] ?? null) ?: '—';
                $ip = trim((string) ($event['ip'] ?? '')) !== '' ? (string) $event['ip'] : '—';
                $this->pdfLine($pdf, $when.'  |  '.($event['label'] ?? '').'  |  IP '.$ip);
            }
        }

        $pdf->Ln(8);
        $pdf->SetFont('Arial', 'I', 8);
        $this->pdfLine(
            $pdf,
            'Gerado em '.$this->csvDate(now()->toIso8601String()).' — '.$appName
            .'. Os IPs e horários refletem os registros da área de membros.'
        );

        return $pdf->Output('S');
    }

    public static function eventLabel(string $event, ?string $subject = null): string
    {
        $subject = $subject !== null && trim($subject) !== '' ? trim($subject) : null;

        return match ($event) {
            MemberStudentActivityLog::EVENT_ENROLLED => 'Acesso concedido',
            MemberStudentActivityLog::EVENT_LOGIN => 'Entrou na área de membros',
            MemberStudentActivityLog::EVENT_VISIT => 'Acessou o produto',
            MemberStudentActivityLog::EVENT_LESSON_VIEWED => $subject
                ? 'Abriu a aula "'.$subject.'"'
                : 'Abriu uma aula',
            MemberStudentActivityLog::EVENT_LESSON_COMPLETED => $subject
                ? 'Concluiu a aula "'.$subject.'"'
                : 'Concluiu uma aula',
            MemberStudentActivityLog::EVENT_MATERIAL_DOWNLOADED => $subject
                ? 'Baixou "'.$subject.'"'
                : 'Baixou um material',
            MemberStudentActivityLog::EVENT_EXTERNAL_LINK_CLICKED => $subject
                ? 'Clicou em Acessar conteúdo ('.$subject.')'
                : 'Clicou em Acessar conteúdo',
            default => $event,
        };
    }

    /**
     * @return array{event: string, label: string, occurred_at: string|null, ip: string|null, subject: string|null, member_lesson_id: int|null}
     */
    private function presentEvent(
        string $event,
        ?string $occurredAt,
        ?string $ip,
        ?string $subject,
        mixed $lessonId,
    ): array {
        return [
            'event' => $event,
            'label' => self::eventLabel($event, $subject),
            'occurred_at' => $occurredAt,
            'ip' => $ip,
            'subject' => $subject,
            'member_lesson_id' => $lessonId !== null ? (int) $lessonId : null,
        ];
    }

    private function csvDate(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        return \Carbon\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y H:i:s');
    }

    private function pdfLine(FPDF $pdf, string $text): void
    {
        $pdf->MultiCell(0, 5, $this->pdfLatin1($text));
    }

    private function pdfLatin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }
}
