<?php

namespace App\Mail;

use App\Services\OutboundEmailRecorder;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Mail\Mailer;

class LoggingMailer extends Mailer
{
    public function send($view, array $data = [], $callback = null)
    {
        if ($view instanceof MailableContract) {
            OutboundEmailRecorder::rememberMailable($view::class);
        }

        try {
            return parent::send($view, $data, $callback);
        } catch (\Throwable $e) {
            app(OutboundEmailRecorder::class)->markLastFailed($e);

            throw $e;
        } finally {
            if ($view instanceof MailableContract) {
                OutboundEmailRecorder::forgetMailable();
            }
        }
    }
}
