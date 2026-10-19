<?php

namespace App\Http\Controllers;

use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\OutboundEmail;
use App\Models\Product;
use App\Models\User;
use App\Services\BrandingEmailData;
use App\Services\EmailCampaignRecipientsService;
use App\Services\PlatformAuditService;
use App\Services\TenantMailConfigService;
use App\Support\EmailCampaignTemplate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailMarketingController extends Controller
{
    public function __construct(
        protected TenantMailConfigService $mailConfig,
        protected EmailCampaignRecipientsService $recipientsService
    ) {}

    public function index(Request $request): Response
    {
        $tenantId = null;
        $emailConfigured = $this->mailConfig->isEmailConfigured($tenantId);
        $cloudMode = config('getfy.cloud_mode', false);

        $campaigns = EmailCampaign::forTenant(null)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EmailCampaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subject' => $c->subject,
                'status' => $c->status,
                'total_recipients' => $c->total_recipients,
                'sent_count' => $c->sent_count,
                'sent_at' => $c->sent_at?->toIso8601String(),
                'created_at' => $c->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        $cronInstructions = 'Adicione ao crontab do servidor (uma vez por minuto):' . "\n"
            . '* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1' . "\n\n"
            . 'Para o envio em massa funcionar, o worker de fila deve estar rodando:' . "\n"
            . 'php artisan queue:work';

        $scheduleHeartbeat = Cache::get('schedule_heartbeat');
        $scheduleOk = self::isHeartbeatRecent($scheduleHeartbeat, 5);
        if (! $scheduleOk) {
            self::runScheduleFallbackIfDue();
            $scheduleOk = self::isHeartbeatRecent(Cache::get('schedule_heartbeat'), 5);
        }
        $queueOk = self::isHeartbeatRecent(Cache::get('queue_heartbeat'), 5);

        $cronSecret = config('getfy.cron_secret');
        $appUrl = rtrim(config('app.url'), '/');
        $cronUrl = $cronSecret
            ? $appUrl . '/cron?token=' . urlencode($cronSecret)
            : null;

        $section = $request->query('aba') === 'enviados' ? 'enviados' : 'marketing';
        $status = (string) $request->query('status', '');
        if (! in_array($status, ['sent', 'failed', 'opened', 'sending'], true)) {
            $status = '';
        }
        $search = trim((string) $request->query('q', ''));
        if (mb_strlen($search) > 120) {
            $search = mb_substr($search, 0, 120);
        }

        return Inertia::render('EmailMarketing/Index', [
            'campaigns' => $campaigns,
            'email_configured' => $emailConfigured,
            'cloud_mode' => $cloudMode,
            'cron_instructions' => $cronInstructions,
            'app_url' => $appUrl,
            'base_path' => base_path(),
            'cron_url' => $cronUrl,
            'schedule_ok' => $scheduleOk,
            'queue_ok' => $queueOk,
            'section' => $section,
            'email_filters' => [
                'status' => $status !== '' ? $status : null,
                'q' => $search !== '' ? $search : null,
            ],
            'sent_emails' => $this->sentEmails($status, $search),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentEmails(string $status, string $search): array
    {
        if (! Schema::hasTable('outbound_emails')) {
            return ['data' => [], 'links' => [], 'total' => 0];
        }

        $like = '%'.addcslashes($search, '%_\\').'%';

        $paginator = OutboundEmail::query()
            ->when($status === 'opened', fn ($q) => $q->whereNotNull('opened_at'))
            ->when($status === 'sent', fn ($q) => $q->where('status', OutboundEmail::STATUS_SENT)->whereNull('opened_at'))
            ->when($status === 'failed', fn ($q) => $q->where('status', OutboundEmail::STATUS_FAILED))
            ->when($status === 'sending', fn ($q) => $q->where('status', OutboundEmail::STATUS_SENDING))
            ->when($search !== '', function ($q) use ($like) {
                $q->where(function ($inner) use ($like) {
                    $inner->where('recipient_email', 'like', $like)
                        ->orWhere('recipient_name', 'like', $like)
                        ->orWhere('subject', 'like', $like)
                        ->orWhere('mail_label', 'like', $like);
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (OutboundEmail $row) => [
                'id' => $row->id,
                'recipient_email' => $row->recipient_email,
                'recipient_name' => $row->recipient_name,
                'recipient_role' => $this->recipientRoleLabel($row->recipient_role),
                'subject' => $row->subject,
                'mail_label' => $row->mail_label,
                'status' => $row->displayStatus(),
                'error_message' => $row->error_message,
                'sent_at' => $row->sent_at?->toIso8601String(),
                'opened_at' => $row->opened_at?->toIso8601String(),
                'open_count' => (int) $row->open_count,
                'created_at' => $row->created_at?->toIso8601String(),
            ]);

        return $paginator->toArray();
    }

    private function recipientRoleLabel(?string $role): ?string
    {
        return match ($role) {
            User::ROLE_INFOPRODUTOR => 'Infoprodutor',
            User::ROLE_CLIENTE, User::ROLE_ALUNO => 'Cliente',
            User::ROLE_PLATFORM_ADMIN, User::ROLE_ADMIN => 'Admin',
            User::ROLE_TEAM => 'Equipe',
            default => null,
        };
    }

    public function create(): Response
    {
        $tenantId = null;
        $emailConfigured = $this->mailConfig->isEmailConfigured($tenantId);
        $products = Product::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])->values()->all();

        return Inertia::render('EmailMarketing/Create', [
            'email_configured' => $emailConfigured,
            'products' => $products,
            'default_message_text' => EmailCampaignTemplate::defaultMessageText(),
            'campaign_theme_primary' => BrandingEmailData::forTenant(null)['theme_primary'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body_message' => ['required', 'string', 'max:50000'],
            'filter_config' => ['nullable', 'array'],
            'filter_config.include_customers' => ['nullable', 'boolean'],
            'filter_config.include_infoprodutors' => ['nullable', 'boolean'],
            'filter_config.all_customers' => ['nullable', 'boolean'],
            'filter_config.product_ids' => ['nullable', 'array'],
            'filter_config.product_ids.*' => ['nullable'],
        ]);

        $filterConfig = self::normalizeCampaignFilterConfig($validated['filter_config'] ?? []);

        EmailCampaign::create([
            'tenant_id' => null,
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'body_html' => EmailCampaignTemplate::wrapContent(
                $validated['body_message'],
                BrandingEmailData::forTenant(null)['theme_primary']
            ),
            'filter_config' => $filterConfig,
            'status' => EmailCampaign::STATUS_DRAFT,
        ]);

        return redirect()->route('plataforma.email-marketing.index')->with('success', 'Campanha criada. Você pode disparar quando quiser.');
    }

    public function edit(EmailCampaign $campaign): Response|RedirectResponse
    {
        $tenantId = null;
        if ($campaign->tenant_id !== null) {
            abort(404);
        }
        if (! $campaign->isDraft()) {
            return redirect()->route('plataforma.email-marketing.index')->with('info', 'Apenas campanhas em rascunho podem ser editadas.');
        }

        $emailConfigured = $this->mailConfig->isEmailConfigured($tenantId);
        $products = Product::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])->values()->all();

        $filterConfig = EmailCampaignRecipientsService::normalizeFilterConfig($campaign->filter_config ?? []);

        return Inertia::render('EmailMarketing/Edit', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'subject' => $campaign->subject,
                'body_message' => EmailCampaignTemplate::extractPlainText($campaign->body_html ?? ''),
                'filter_config' => $filterConfig,
            ],
            'email_configured' => $emailConfigured,
            'products' => $products,
            'default_message_text' => EmailCampaignTemplate::defaultMessageText(),
            'campaign_theme_primary' => BrandingEmailData::forTenant(null)['theme_primary'],
        ]);
    }

    public function update(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        if ($campaign->tenant_id !== null || ! $campaign->isDraft()) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body_message' => ['required', 'string', 'max:50000'],
            'filter_config' => ['nullable', 'array'],
            'filter_config.include_customers' => ['nullable', 'boolean'],
            'filter_config.include_infoprodutors' => ['nullable', 'boolean'],
            'filter_config.all_customers' => ['nullable', 'boolean'],
            'filter_config.product_ids' => ['nullable', 'array'],
            'filter_config.product_ids.*' => ['nullable'],
        ]);

        $filterConfig = self::normalizeCampaignFilterConfig($validated['filter_config'] ?? []);

        $campaign->update([
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'body_html' => EmailCampaignTemplate::wrapContent(
                $validated['body_message'],
                BrandingEmailData::forTenant(null)['theme_primary']
            ),
            'filter_config' => $filterConfig,
        ]);

        return redirect()->route('plataforma.email-marketing.index')->with('success', 'Campanha atualizada.');
    }

    public function previewRecipients(Request $request, EmailCampaign $campaign): JsonResponse
    {
        if ($campaign->tenant_id !== null) {
            abort(404);
        }

        $validated = $request->validate([
            'filter_config' => ['nullable', 'array'],
            'filter_config.include_customers' => ['nullable', 'boolean'],
            'filter_config.include_infoprodutors' => ['nullable', 'boolean'],
            'filter_config.all_customers' => ['nullable', 'boolean'],
            'filter_config.product_ids' => ['nullable', 'array'],
            'filter_config.product_ids.*' => ['nullable'],
        ]);

        $filterConfig = $validated['filter_config'] ?? $campaign->filter_config ?? [];

        return $this->previewRecipientsResponse(null, $filterConfig);
    }

    /**
     * Preview recipients by filter config (no campaign required). Used on create form.
     */
    public function previewRecipientsByFilter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter_config' => ['nullable', 'array'],
            'filter_config.include_customers' => ['nullable', 'boolean'],
            'filter_config.include_infoprodutors' => ['nullable', 'boolean'],
            'filter_config.all_customers' => ['nullable', 'boolean'],
            'filter_config.product_ids' => ['nullable', 'array'],
            'filter_config.product_ids.*' => ['nullable'],
        ]);

        return $this->previewRecipientsResponse(null, $validated['filter_config'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $filterConfig
     */
    private function previewRecipientsResponse(?int $tenantId, array $filterConfig): JsonResponse
    {
        $normalized = self::normalizeCampaignFilterConfig($filterConfig);
        $recipients = $this->recipientsService->getRecipients($tenantId, $normalized);
        $count = $recipients->count();
        $sample = $recipients->take(10)->map(fn ($r) => [
            'email' => $r['email'],
            'name' => $r['name'],
            'type' => $r['type'] ?? 'customer',
        ])->values()->all();

        return response()->json([
            'count' => $count,
            'sample' => $sample,
            'breakdown' => [
                'customers' => $recipients->where('type', 'customer')->count(),
                'infoprodutors' => $recipients->where('type', 'infoprodutor')->count(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filterConfig
     * @return array{include_customers: bool, include_infoprodutors: bool, all_customers: bool, product_ids: list<int>}
     */
    private static function normalizeCampaignFilterConfig(array $filterConfig): array
    {
        $normalized = EmailCampaignRecipientsService::normalizeFilterConfig($filterConfig);
        if (! $normalized['include_customers'] && ! $normalized['include_infoprodutors']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'filter_config' => ['Selecione pelo menos um público: compradores e/ou infoprodutores.'],
            ]);
        }

        return $normalized;
    }

    public function send(EmailCampaign $campaign): RedirectResponse
    {
        if ($campaign->tenant_id !== null) {
            abort(404);
        }
        if (! $campaign->isDraft()) {
            return redirect()->route('plataforma.email-marketing.index')->with('error', 'Apenas campanhas em rascunho podem ser disparadas.');
        }
        if (! $this->mailConfig->isEmailConfigured(null)) {
            return redirect()->route('plataforma.email-marketing.index')->with('error', 'Configure o e-mail em Configurações > E-mail antes de disparar campanhas.');
        }

        $filterConfig = $campaign->filter_config ?? ['all_customers' => true];
        $total = $this->recipientsService->getRecipients(null, $filterConfig)->count();
        if ($total === 0) {
            return redirect()->route('plataforma.email-marketing.index')->with('error', 'Nenhum destinatário encontrado para o filtro desta campanha.');
        }

        $campaign->update([
            'status' => EmailCampaign::STATUS_SENDING,
            'total_recipients' => $total,
            'sent_count' => 0,
        ]);

        PlatformAuditService::log('platform.email_campaign.send', [
            'campaign_id' => $campaign->id,
            'total_recipients' => $total,
        ]);

        try {
            Artisan::call('email-campaign:process');
            $campaign->refresh();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('plataforma.email-marketing.index')
                ->with('error', 'Campanha iniciada, mas falhou ao processar o primeiro lote: '.$e->getMessage());
        }

        $sentNow = (int) $campaign->sent_count;
        $message = $sentNow > 0
            ? "Campanha iniciada. {$sentNow} e-mail(s) já enviado(s); o restante segue em lotes de 30 por minuto."
            : 'Campanha iniciada. Os e-mails serão enviados em lotes de 30 por minuto (confira cron/fila na aba Configuração).';

        return redirect()->route('plataforma.email-marketing.index')->with('success', $message);
    }

    /**
     * Fallback: quando o cron não está rodando, executa o schedule ao visitar esta página.
     * Usa throttle (55s) para não rodar em toda requisição.
     */
    private static function runScheduleFallbackIfDue(): void
    {
        $lastRun = Cache::get('schedule_fallback_last_run');
        if ($lastRun && Carbon::parse($lastRun)->gte(now()->subSeconds(55))) {
            return;
        }
        Cache::put('schedule_fallback_last_run', now()->toIso8601String(), now()->addMinutes(5));
        Artisan::call('schedule:run');
    }

    /**
     * Verifica se o valor do heartbeat (ISO8601) está dentro dos últimos N minutos.
     */
    private static function isHeartbeatRecent(?string $value, int $minutes = 5): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        try {
            $at = Carbon::parse($value);

            return $at->gte(now()->subMinutes($minutes));
        } catch (\Throwable) {
            return false;
        }
    }

}
