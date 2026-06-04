@props(['type', 'id' => null])

@php
    $statusMap = [
        'unmatched'   => 'text-gray-600 bg-gray-100',      // neutral: awaiting match
        'automatched' => 'text-blue-700 bg-blue-100',      // informational: matched by system
        'matched'     => 'text-green-700 bg-green-100',    // confirmed match
        'manual'      => 'text-lime-800 bg-lime-200',      // user match
        'confirmed'   => 'text-emerald-800 bg-emerald-200',// verified or user-confirmed
        'ignored'     => 'text-rose-700 bg-rose-100',      // intentionally skipped
        'external'    => 'text-purple-700 bg-purple-100',  // from external or synced source
        'error'       => 'text-red-800 bg-red-200',        // problem detected
    ];
@endphp

<div
        wire:key="status-badge-{{ $id }}-{{ $type }}"
        x-data="{
        open: false,
        status: '{{ $type }}',
        statusMap: @js($statusMap),
        async updateStatus(newStatus) {
            try {
                const response = await fetch(`/transactions/{{ $id }}/status`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ status: newStatus }),
                });
                if (!response.ok) throw new Error('Update failed');
                this.status = newStatus;
                this.open = false;
            } catch {
                this.status = newStatus; // temporary fallback
                this.open = false;
            }
        }
    }"
        class="relative inline-block"
>
    <!-- Badge -->
    <button
            type="button"
            @click="open = !open"
            @click.outside="open = false"
            class="inline-block rounded px-2 py-0.5 text-xs font-semibold cursor-pointer transition"
            :class="statusMap[status] ?? 'text-gray-500 bg-gray-100'"
            x-text="status"
    ></button>

    <!-- Dropdown -->
    <template x-if="['unmatched', 'external', 'ignored', 'matched', 'automatched'].includes(status)">
        <div
                x-show="open"
                x-transition.scale.origin.top
                class="absolute left-0 mt-1 w-32 bg-white border border-gray-200 rounded shadow z-10"
        >
            <template x-for="option in (() => {
                switch (status) {
                    case 'unmatched': return ['external', 'ignored'];
                    case 'external': return ['unmatched'];
                    case 'ignored': return ['unmatched'];
                    case 'matched':
                    case 'automatched': return ['unmatched'];
                    default: return [];
                }
            })()" :key="option">
                <button
                        @click="
                        $wire.call('updateStatus', {{ $id }}, option)
                        .then(() => { status = option; open = false })
                        .catch(() => { alert('Failed to update status.'); open = false })
                    "
                        class="block w-full text-left px-3 py-1 text-sm rounded hover:bg-gray-50"
                        :class="statusMap[option] ?? 'text-gray-500 bg-gray-100'"
                        x-text="option.charAt(0).toUpperCase() + option.slice(1)"
                ></button>
            </template>
        </div>
    </template>
</div>
