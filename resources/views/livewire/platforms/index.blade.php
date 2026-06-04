<div>
    <table>
        <thead>
        <tr>
            <th>Name</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($platforms as $platform)
            <tr>
                <td><a href="{{ route('platforms.show', $platform->id) }}">{{ $platform->name }}</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
