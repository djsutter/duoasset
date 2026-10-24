<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Admin\LogReaderService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ErrorLogs extends Component
{
    public string $filterType = LogReaderService::TYPE_ALL;
    public string $filterLevel = LogReaderService::LEVEL_ALL;
    public string $search = '';
    public int $limit = 100;

    public ?int $selectedEntryIndex = null;

    protected $queryString = [
        'filterType' => ['except' => LogReaderService::TYPE_ALL],
        'filterLevel' => ['except' => LogReaderService::LEVEL_ALL],
        'search' => ['except' => ''],
    ];

    public function boot(): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Unauthorized. Administrator access required.');
        }
    }

    public function setFilterType(string $type): void
    {
        if (array_key_exists($type, LogReaderService::typeLabels())) {
            $this->filterType = $type;
            $this->selectedEntryIndex = null;
        }
    }

    public function setFilterLevel(string $level): void
    {
        if (array_key_exists($level, LogReaderService::levelLabels())) {
            $this->filterLevel = $level;
            $this->selectedEntryIndex = null;
        }
    }

    public function clearFilters(): void
    {
        $this->filterType = LogReaderService::TYPE_ALL;
        $this->filterLevel = LogReaderService::LEVEL_ALL;
        $this->search = '';
        $this->selectedEntryIndex = null;
    }

    public function showDetails(int $index): void
    {
        $this->selectedEntryIndex = $index;
    }

    public function closeDetails(): void
    {
        $this->selectedEntryIndex = null;
    }

    public function render()
    {
        /** @var LogReaderService $service */
        $service = app(LogReaderService::class);
        $result = $service->getLogs(
            null,
            $this->filterType,
            $this->filterLevel,
            $this->search,
            $this->limit
        );

        $selectedEntry = null;
        if ($this->selectedEntryIndex !== null && isset($result['entries'][$this->selectedEntryIndex])) {
            $selectedEntry = $result['entries'][$this->selectedEntryIndex];
        }

        return view('livewire.admin.error-logs', [
            'entries' => $result['entries'],
            'summary' => $result['summary'],
            'fileExists' => $result['file_exists'],
            'filePath' => $result['file_path'],
            'fileSize' => $result['file_size'],
            'selectedEntry' => $selectedEntry,
            'typeLabels' => LogReaderService::typeLabels(),
            'levelLabels' => LogReaderService::levelLabels(),
        ])->layout('components.layouts.app', ['title' => 'Admin Error Log Viewer - DuoAsset']);
    }
}
