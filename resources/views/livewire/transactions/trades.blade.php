<div>
    <h1 class="mb-6">Matched Trades</h1>

    <table class="w-full border">
        <thead>
        <tr class="bg-gray-100">
            <th class="px-4">Send Date</th>
            <th class="px-4">Send Amount</th>
            <th class="px-4">Send Asset</th>
            <th class="px-4">Receive Date</th>
            <th class="px-4">Receive Amount</th>
            <th class="px-4">Receive Asset</th>
            <th class="px-4">CAD Diff</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($trades as $trade)
            <tr class="hover:bg-green-100">
                <td class="px-4">{{ $trade['send']->transaction_at->format('Y-m-d H:i') }}</td>
                <td class="px-4 text-right">@money($trade['send']->amount)</td>
                <td class="px-4">{{ $trade['send']->foreign_amount?->currency }}</td>

                <td class="px-4">{{ $trade['receive']->transaction_at->format('Y-m-d H:i') }}</td>
                <td class="px-4 text-right">@money($trade['receive']->amount)</td>
                <td class="px-4">{{ $trade['receive']->foreign_amount?->currency }}</td>

                <td class="px-4 text-right">@money($trade['cad_diff']) CAD</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
