{{--
    NAF Feedback-1 (Documents page) — "Scroll right feature needed at top of page
    also". Wide Filament tables only expose a horizontal scrollbar at the BOTTOM.
    This adds a second, synced scrollbar ABOVE the table so staff can scroll a
    wide grid (e.g. Documents) without first scrolling to the bottom.

    Pure progressive enhancement: if the markup ever changes it silently does
    nothing. Targets Filament's `.fi-ta-content` scroll container and re-runs on
    Livewire navigation / updates.
--}}
<style>
    .nra-top-scroll { overflow-x: auto; overflow-y: hidden; }
    .nra-top-scroll > div { height: 1px; }
</style>
<script>
    (function () {
        function attach(container) {
            if (!container || container.dataset.nraTopScroll) return;
            if (container.scrollWidth <= container.clientWidth) return; // nothing to scroll
            container.dataset.nraTopScroll = '1';

            const bar = document.createElement('div');
            bar.className = 'nra-top-scroll';
            const spacer = document.createElement('div');
            spacer.style.width = container.scrollWidth + 'px';
            bar.appendChild(spacer);
            container.parentNode.insertBefore(bar, container);

            let lock = false;
            bar.addEventListener('scroll', function () {
                if (lock) return; lock = true; container.scrollLeft = bar.scrollLeft; lock = false;
            });
            container.addEventListener('scroll', function () {
                if (lock) return; lock = true; bar.scrollLeft = container.scrollLeft; lock = false;
            });

            if ('ResizeObserver' in window) {
                new ResizeObserver(function () {
                    spacer.style.width = container.scrollWidth + 'px';
                }).observe(container);
            }
        }

        function scan() {
            document.querySelectorAll('.fi-ta-content').forEach(attach);
        }

        function scanSoon() { window.requestAnimationFrame(scan); }

        document.addEventListener('DOMContentLoaded', scanSoon);
        document.addEventListener('livewire:navigated', scanSoon);
        document.addEventListener('livewire:updated', function () { setTimeout(scan, 50); });
        scanSoon();
    })();
</script>
