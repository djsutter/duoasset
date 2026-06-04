<?php

namespace App\Livewire\Tax;

use App\Data\Tax\Schedule3\Schedule3Data;
use App\Enums\AcbEventType;
use App\Enums\Schedule3Method;
use App\Models\AcbEvent;
use App\Services\Tax\TaxService;
use App\Tax\Application\Schedule3MethodResolver;
use Livewire\Component;

class Schedule3 extends Component
{
    public int $year;

    public Schedule3Method $method;

    private Schedule3MethodResolver $methodResolver;

    private TaxService $taxService;

    public function boot(TaxService $taxService, Schedule3MethodResolver $resolver): void
    {
        $this->taxService = $taxService;
        $this->methodResolver = $resolver;
    }

    public function mount(): void
    {
        $this->initYear();
        $this->method = $this->methodResolver->resolve();
    }

    public function getSchedule3Property(): Schedule3Data
    {
        return $this->taxService->buildSchedule3($this->year);
    }

    public function getAssetRowsProperty(): array
    {
        return $this->schedule3->asset_rows;
    }

    public function getYearOptionsProperty(): array
    {
        $years = AcbEvent::where('event_type', AcbEventType::Disposal)
            ->selectRaw('MIN(YEAR(event_at)) as min_year, MAX(YEAR(event_at)) as max_year')
            ->first();

        if (! $years || ! $years->min_year) {
            return [now()->year];
        }

        return range($years->max_year, $years->min_year);
    }

    private function initYear(): void
    {
        if ($year = session('schedule3_year')) {
            $this->year = $year;
        } else {
            $this->year = $this->yearOptions[0] ?? now()->year;
        }
    }

    public function render()
    {
        return view('livewire.tax.schedule3');
    }

    public function updatedMethod(): void
    {
        $this->methodResolver->set($this->method);
    }

    public function updatedYear(): void
    {
        session(['schedule3_year' => $this->year]);
    }
}
