<div class="space-y-12">
    @if ($report && count($report->assets))
        @foreach ($report->assets as $assetReport)
            <section class="space-y-10 border-t pt-6">
                <header>
                    <h2 class="text-xl font-semibold">
                        {{ $assetReport->asset_name }}
                        <span class="text-sm text-gray-500">
                            ({{ $assetReport->asset_code }})
                        </span>
                    </h2>
                </header>

                @foreach ($assetReport->years as $yearReport)
                    <section class="space-y-6">
                        <h3 class="text-lg font-medium">
                            Tax Year {{ $yearReport->tax_year }}
                        </h3>

                        @include('reports.capital-gains.ledger.summary', [
                            'summary' => $yearReport->summary,
                        ])

                        @include('reports.capital-gains.ledger.dispositions', [
                            'dispositions' => $yearReport->dispositions,
                        ])
                    </section>
                @endforeach
            </section>
        @endforeach
    @else
        <x-ui.placeholder>
            No capital gains data available.
        </x-ui.placeholder>
    @endif
</div>
