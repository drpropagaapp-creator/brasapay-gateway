@extends('emails.layouts.branded')

@section('content')
    @if (! empty($autoAccepted))
        <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Você agora é co-produtor</p>
        <p style="margin:0 0 16px 0;">Olá,</p>
        <p style="margin:0 0 16px 0;"><strong>{{ $inviterName }}</strong> adicionou você como co-produtor do produto <strong>{{ $productName }}</strong> na plataforma {{ $branding['app_name'] }}. A co-produção já está <strong>ativa</strong> na sua conta ({{ $recipientEmail }}).</p>
        <p style="margin:0 0 24px 0;">Comissão: <strong>{{ number_format($commissionPercent, 2, ',', '.') }}%</strong> sobre as vendas elegíveis, a partir de agora.</p>
        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 12px auto;">
            <tr>
                <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                    <a href="{{ $acceptUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Ver minhas co-produções</a>
                </td>
            </tr>
        </table>
        <p style="margin:16px 0 0 0;font-size:14px;color:#64748b;">Não reconhece esta parceria? Fale com quem adicionou você; a co-produção pode ser encerrada no painel do produto.</p>
    @else
        <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Convite de co-produção</p>
        <p style="margin:0 0 16px 0;">Olá,</p>
        <p style="margin:0 0 16px 0;"><strong>{{ $inviterName }}</strong> convidou você para co-produzir o produto <strong>{{ $productName }}</strong> na plataforma {{ $branding['app_name'] }}.</p>
        <p style="margin:0 0 16px 0;">Comissão acordada: <strong>{{ number_format($commissionPercent, 2, ',', '.') }}%</strong> sobre as vendas elegíveis (conforme definido no convite).</p>
        <p style="margin:0 0 24px 0;">Crie sua conta de infoprodutor com o e-mail <strong>{{ $recipientEmail }}</strong>: a co-produção é ativada automaticamente no cadastro.</p>
        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 12px auto;">
            <tr>
                <td style="border-radius:8px;background-color:{{ $branding['theme_primary'] }};">
                    <a href="{{ $registerUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Criar conta e ativar</a>
                </td>
            </tr>
        </table>
        <p style="margin:16px 0 0 0;font-size:14px;color:#64748b;">Já tem conta com este e-mail? <a href="{{ $acceptUrl }}" style="color:{{ $branding['theme_primary'] }};">Ver o convite</a></p>
    @endif
@endsection
