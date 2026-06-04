<div class="rounded border p-4 space-y-4">

    <h3 class="text-md font-semibold">
        Tax Year {{ $yearReport->tax_year }}
    </h3>

    @include('reports.capital-gains.cra.summary', [
        'summary' => $yearReport->summary,
    ])

    @include('reports.capital-gains.cra.dispositions', [
        'dispositions' => $yearReport->dispositions,
    ])

</div>
