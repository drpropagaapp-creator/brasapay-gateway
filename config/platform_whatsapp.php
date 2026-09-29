<?php

return [

    'queue' => env('PLATFORM_WHATSAPP_QUEUE', env('UAZAPI_QUEUE', 'uazapi')),

    'max_message_length' => (int) env('PLATFORM_WHATSAPP_MAX_MESSAGE_LENGTH', 1000),

    'campaign' => [
        'delay_seconds' => (int) env('PLATFORM_WHATSAPP_CAMPAIGN_DELAY', 10),
        'max_recipients' => (int) env('PLATFORM_WHATSAPP_CAMPAIGN_MAX', 500),
    ],

    'retry' => [
        'tries' => 3,
        'backoff' => [30, 90],
        'timeout' => 60,
    ],

    'inbound' => [
        'opt_out_keywords' => ['parar', 'stop', 'nao quero', 'não quero', 'sair', 'cancelar'],
        'opt_out_reply' => 'Ok, não enviaremos mais mensagens neste número.',
    ],

    'defaults' => [
        'templates' => [
            'seller.registered' => 'Oi {primeiro_nome}! Recebemos seu cadastro na {plataforma}. Envie seus documentos de verificação para liberar o painel: {kyc_url}',
            'seller.rejected' => 'Oi {primeiro_nome}, seu cadastro não foi aprovado.{motivo_linha}',
            'kyc.approved' => 'Oi {primeiro_nome}, seu KYC foi aprovado. Acesse o painel: {painel}',
            'kyc.rejected' => 'Oi {primeiro_nome}, seu KYC não foi aprovado. Motivo: {motivo}\nAtualize os documentos: {kyc_url}',
        ],
    ],

];
