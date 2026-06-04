<?php

namespace App\Livewire;

use App\Models\Transaction;
use Livewire\Component;

class ValuationProgress extends Component
{
    public int $done = 0;

    public int $total = 0;

    public int $progress = 0;

    protected $listeners = ['valuationUpdated' => '$refresh'];

    public function mount()
    {
        $this->updateProgress();
    }

    public function updateProgress()
    {
        $this->total = Transaction::count();
        $this->done = Transaction::whereIn('valuation_status', ['done', 'failed'])->count();
        $this->progress = $this->total > 0 ? (int) (($this->done / $this->total) * 100) : 0;
    }

    public function getProgressProperty(): int
    {
        return $this->total > 0 ? (int) (($this->done / $this->total) * 100) : 0;
    }

    public function render()
    {
        $this->updateProgress();

        return view('livewire.valuation-progress-circular');
    }
}
