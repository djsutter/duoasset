@props(['title', 'items'])

<h2>{{ $title }}</h2>

@foreach ($items as $item)
    <h3 class="font-bold">{{ $item['name'] }}</h3>
    <table class="w-3/4 mb-4 dark:text-gray-400">
        @foreach ($item['wallets'] as $wallet)
            <tr>
                <td class="pl-4"><a class="hover:underline" href="{{ route('wallets.show', $wallet->id) }}">{{ $wallet->name }}</a></td>
                <td class="text-right">{{ $wallet->balance?->format() }}</td>
            </tr>
        @endforeach
    </table>
@endforeach
