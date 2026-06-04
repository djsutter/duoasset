<div class="space-y-8">
    @foreach ($report->assets as $assetReport)
        @include('reports.capital-gains.cra.asset', [
            'assetReport' => $assetReport,
        ])
    @endforeach

    @include('reports.capital-gains.cra.totals')
</div>
