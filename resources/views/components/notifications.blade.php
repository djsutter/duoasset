<div
    x-data="notificationList()"
    x-on:notify.window="add($event.detail)"
    class="fixed top-4 right-4 space-y-2 z-50"
>
    <template x-for="note in notes" :key="note.id">
        <div
            x-show="note.visible"
            x-transition.duration.300ms
            class="rounded-xl px-4 py-3 shadow text-white"
            :class="{
                'bg-green-600': note.type === 'success',
                'bg-yellow-500': note.type === 'warning',
                'bg-red-600': note.type === 'error',
                'bg-blue-600': note.type === 'info',
            }"
        >
            <strong x-text="note.message"></strong>
        </div>
    </template>
</div>

<script>
    function notificationList() {
        return {
            notes: [],
            add({ type, message }) {
                const id = Date.now() + Math.random();

                const note = {
                    id,
                    type,
                    message,
                    visible: true,
                };

                this.notes.push(note);

                // Auto hide after 3 seconds
                setTimeout(() => {
                    note.visible = false;
                    setTimeout(() => {
                        this.notes = this.notes.filter(n => n.id !== id);
                    }, 300);
                }, 3000);
            }
        }
    }
</script>
