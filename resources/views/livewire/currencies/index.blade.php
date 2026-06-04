<div>
    <table>
        <thead>
        <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Symbol</th>
            <th>Type</th>
            <th>Scale</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($currencies as $currency)
            <tr>
                <td class="px-4">{{ $currency['currency_code'] }}</td>
                <td class="px-4">{{ $currency['name'] }}</td>
                <td class="px-4">{{ $currency['symbol'] }}</td>
                <td class="px-4">{{ $currency['type'] }}</td>
                <td class="px-4">{{ $currency['scale'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
