<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class ExportReady extends Notification
{
    use Queueable;

    public $fileName;

    public function __construct($fileName)
    {
        $this->fileName = $fileName;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = Storage::disk('public')->url($this->fileName);

        return (new MailMessage)
            ->subject('Your customers export is ready')
            ->line('Your customer export is ready for download.')
            ->action('Download Export', $url)
            ->line('Thank you for using our application!');
    }
}