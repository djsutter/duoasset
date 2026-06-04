<?php

namespace App\Livewire\Import;

use App\Models\UploadedFile;
use App\Services\Import\ImportService;
use App\Traits\SendsNotifications;
use Livewire\Component;

class Index extends Component
{
    use SendsNotifications;

    public array $data = [];

    public string $message = '';

    public function import(): void
    {
        $importService = app(ImportService::class);
        $importService->import();
        $this->success('Imported');
    }

    public function render()
    {
        $filesToImport = [];

        foreach (UploadedFile::all() as $file) {
            $filesToImport[] = $file->filename;
        }

        return view('livewire.import.index', compact('filesToImport'));
    }
}
