<?php

namespace App\Livewire\Transactions;

use App\Services\ReplayService;
use App\Traits\SendsNotifications;
use Livewire\Component;
use Livewire\WithFileUploads;

class Replay extends Component
{
    use SendsNotifications;
    use WithFileUploads;

    public $logFile;

    public $results = [];

    public function render()
    {
        return view('livewire.transactions.replay');
    }

    public function runReplay(ReplayService $replay)
    {
        $this->validate([
            'logFile' => 'required|file|mimes:json,txt',
        ]);

        $json = json_decode($this->logFile->get(), true);

        if (! $json) {
            $this->addError('logFile', 'Invalid JSON file.');

            return;
        }

        $this->results = $replay->replay($json);

        $this->success('Replay complete.');
    }
}
