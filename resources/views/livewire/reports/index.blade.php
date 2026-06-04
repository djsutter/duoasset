<div>
    <h1>Reports</h1>

    <ul class="list-disc ml-5">
        <li><a href="{{ route('reports.transactions') }}">Transactions</a></li>
        <li><a href="{{ route('transactions.external') }}">External transactions</a></li>
        <li><a href="{{ route('transactions.replay') }}">Replay</a></li>
        @if (! config('app.demo_mode'))
        <li><a href="{{ route('invest-events.holdings') }}">Holdings</a></li>
        @endif
        <li><a href="{{ route('acb.index') }}">ACB</a></li>
        <li><a href="{{ route('reports.acb-audit-ledger') }}">ACB Audit Ledger</a></li>
        <li><a href="{{ route('reports.capital-gains') }}">Capital Gains</a></li>
        <li><a href="{{ route('tax.schedule3') }}">Schedule 3</a></li>
        <li><a href="{{ route('tax.dispositions-ledger') }}">Dispositions Ledger</a></li>
        <li><a href="{{ route('tax.pool-ledger') }}">Pool Ledger</a></li>
        <li><a href="{{ route('tax.continuity-summary') }}">Continuity Summary</a></li>
    </ul>
</div>
