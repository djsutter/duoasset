<?php

namespace App\Livewire\Import;

use App\Data\Stage\StageTransactionViewData;
use App\Models\StageTransaction;
use App\Services\ImportStageService;
use App\Traits\SendsNotifications;
use Livewire\Component;
use Livewire\WithPagination;

class Stage extends Component
{
    use SendsNotifications;
    use WithPagination;

    public bool $show_matched = true;

    public bool $show_manual = true;

    public bool $show_unmatched = true;

    public bool $show_automatched = true;

    public bool $show_ignored = true;

    public bool $show_external = true;

    public bool $show_error = true;

    public function autoMatch(ImportStageService $stageService): void
    {
        $numMatched = $stageService->autoMatch();
        $this->success("$numMatched transactions were auto-matched.");
    }

    public function export(): void
    {
        $service = app(ImportStageService::class);
        $service->export();
        $this->success('Export is complete.');
    }

    public function import(): void
    {
        $service = app(ImportStageService::class);
        $service->import();
        $this->success('Import is complete.');
    }

    public function render()
    {
        $query = StageTransaction::orderBy('tx_at')->with('entries');
        $includeStatus = [];
        if ($this->show_matched) {
            $includeStatus[] = 'matched';
        }
        if ($this->show_matched) {
            $includeStatus[] = 'manual';
        }
        if ($this->show_unmatched) {
            $includeStatus[] = 'unmatched';
        }
        if ($this->show_automatched) {
            $includeStatus[] = 'automatched';
        }
        if ($this->show_ignored) {
            $includeStatus[] = 'ignored';
        }
        if ($this->show_external) {
            $includeStatus[] = 'external';
        }
        if ($this->show_error) {
            $includeStatus[] = 'error';
        }
        $query->whereIn('status', $includeStatus);
        $pageSize = config('app.page_size');
        $transactionPage = $query->paginate($pageSize);

        return view('livewire.import.stage', [
            'transactions' => $transactionPage->map(function ($transaction) {
                return StageTransactionViewData::fromModel($transaction);
            }),
            'transactionPage' => $transactionPage,
        ]);
    }

    public function toggleFilter(string $filter): void
    {
        $allFilters = ['show_matched', 'show_manual', 'show_unmatched', 'show_automatched', 'show_ignored', 'show_external', 'show_error'];

        if ($filter == 'show_all') {
            foreach ($allFilters as $filter) {
                $this->{$filter} = true;
            }

            return;
        }

        if (in_array($filter, $allFilters)) {
            $this->{$filter} = ! $this->{$filter};
        }
    }

    public function updated($property): void
    {
        $this->resetPage();
    }

    public function updateStatus(int $id, string $option): void
    {
        if ($tx = StageTransaction::find($id)) {
            $tx->status = $option;
            $tx->save();
        }
    }
}
