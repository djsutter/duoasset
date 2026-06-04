document.addEventListener('alpine:init', () => {
    Alpine.directive('rowlink', (el, { expression }, { evaluate }) => {
        let downX = 0;
        let downY = 0;

        el.style.cursor = 'pointer';

        el.addEventListener('mousedown', (e) => {
            downX = e.clientX;
            downY = e.clientY;
        });

        el.addEventListener('mouseup', (e) => {
            // Ignore clicks originating from interactive elements
            if (e.target.closest('a, button, input, textarea, select, label')) {
                return;
            }

            // Suppress navigation if user dragged (text selection)
            const dx = Math.abs(e.clientX - downX);
            const dy = Math.abs(e.clientY - downY);

            if (dx > 5 || dy > 5) return;

            const url = evaluate(expression);
            if (!url) return;

            // Support cmd/ctrl-click to open in new tab
            if (e.metaKey || e.ctrlKey) {
                window.open(url, '_blank');
            } else {
                window.location.href = url;
            }
        });
    });
});
