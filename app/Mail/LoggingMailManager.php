<?php

namespace App\Mail;

use Illuminate\Mail\MailManager;

class LoggingMailManager extends MailManager
{
    public function build($config)
    {
        $mailer = new LoggingMailer(
            $config['name'] ?? 'ondemand',
            $this->app['view'],
            $this->createSymfonyTransport($config),
            $this->app['events']
        );

        if ($this->app->bound('queue')) {
            $mailer->setQueue($this->app['queue']);
        }

        return $mailer;
    }
}
