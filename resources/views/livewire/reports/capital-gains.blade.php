<div class="space-y-6">

    {{-- Header --}}
    <div>
        <h1 class="text-xl font-semibold">
            Capital Gains Report
        </h1>
        <p class="text-sm text-gray-600">
            CRA-style capital gains by asset and tax year
        </p>
    </div>

    {{-- Selectors --}}
    <div class="flex gap-4 items-end">

        {{-- Asset Selector --}}
        <div class="w-64">
            <label class="block text-sm font-medium text-gray-700">
                Asset
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="allAssets">
                <span>All assets</span>
            </label>
            <select
                wire:model.live="assetCodes"
                multiple
                size="5"
                @disabled($allAssets)
                class="mt-1 block decorated border-1 rounded-md py-2 w-full focus:border-indigo-500 focus:ring-indigo-500 dark:focus:border-sky-300 dark:bg-zinc-800"
            >
                <option value="">Select asset…</option>
                @foreach ($this->assetOptions as $asset)
                    <option value="{{ $asset->asset_code }}">
                        {{ $asset->asset_code }} — {{ $asset->asset_name }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Tax Year Selector --}}
        <div class="w-40">
            <label class="block text-sm font-medium text-gray-700">
                Tax year
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="allTaxYears">
                <span>All tax years</span>
            </label>
            <select
                wire:model.live="taxYears"
                multiple
                size="5"
                @disabled($allTaxYears)
                class="mt-1 block decorated border-1 rounded-md py-2 w-full  focus:border-indigo-500 focus:ring-indigo-500 dark:focus:border-sky-300 dark:bg-zinc-800"
            >
                @foreach ($this->taxYearOptions as $year)
                    <option value="{{ $year }}">
                        {{ $year }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Report type Selector --}}
        <div class="w-40">
            <label class="block text-sm font-medium text-gray-700">
                Report type
            </label>
            <select
                wire:model.live="reportType"
                class="mt-1 block decorated border-1 focus:border-indigo-500 focus:ring-indigo-500 dark:focus:border-sky-300 rounded-md py-2 w-full dark:bg-zinc-800"
            >
                @foreach ($this->reportTypes as $type => $name)
                    <option value="{{ $type }}">
                        {{ $name }}
                    </option>
                @endforeach
            </select>
        </div>

    </div>

    <div class="rounded border p-4 text-gray-400">
        @php
            $report = $this->report;
        @endphp

        @if ($assetCodes === [] || $taxYears === [])
            <x-ui.placeholder>
                Select an asset and tax year to generate a report.
            </x-ui.placeholder>
        @elseif (! $report)
            <x-ui.placeholder>
                No capital gains data available for the selected criteria.
            </x-ui.placeholder>
        @else
            @include('reports.capital-gains.partials.header', [
                'assetCodes' => $assetCodes,
                'taxYears' => $taxYears,
            ])

        @switch($reportType)
                @case(\App\Livewire\Reports\CapitalGains::REPORT_LEDGER)
                    @include('reports.capital-gains.ledger.report')
                    @break

                @case(\App\Livewire\Reports\CapitalGains::REPORT_CRA)
                    @include('reports.capital-gains.cra.report')
                    @break
            @endswitch
        @endif
    </div>

    @if ($reportType === \App\Livewire\Reports\CapitalGains::REPORT_LEDGER)
        @include('reports.capital-gains.ledger.totals')
    @endif

</div>
