<?php

namespace App\Traits;

trait SendsNotifications
{
    public function notify(string $type, string $message): void
    {
        $this->dispatch('notify', type: $type, message: $message);
    }

    public function success(string $message): void
    {
        $this->notify('success', $message);
    }

    public function error(string $message): void
    {
        $this->notify('error', $message);
    }

    public function warning(string $message): void
    {
        $this->notify('warning', $message);
    }

    public function info(string $message): void
    {
        $this->notify('info', $message);
    }
}
