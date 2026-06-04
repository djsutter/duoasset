<div class="space-y-6">

    {{-- Asset header --}}
    <div class="border-b pb-2">
        <h2 class="text-lg font-semibold">
            {{ $assetReport->asset_code }}
            <span class="text-gray-500 font-normal">
                — {{ $assetReport->asset_name }}
            </span>
        </h2>
    </div>

    @foreach ($assetReport->years as $yearReport)
        @include('reports.capital-gains.cra.year', [
            'yearReport' => $yearReport,
        ])
    @endforeach

</div>
