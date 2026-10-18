    </main> <!-- end main-content -->
    <?= themeScript() ?>
    <script>
        // Zijbalk als lade op smalle schermen
        (function () {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebar-toggle');
            const overlay = document.getElementById('sidebar-overlay');
            function setOpen(open) {
                sidebar.classList.toggle('is-open', open);
                overlay.hidden = !open;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.setAttribute('aria-label', open ? 'Menu sluiten' : 'Menu openen');
                if (open) sidebar.querySelector('a, select, button')?.focus();
            }
            toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-open')));
            overlay.addEventListener('click', () => setOpen(false));
            document.addEventListener('keydown', e => { if (e.key === 'Escape' && sidebar.classList.contains('is-open')) { setOpen(false); toggle.focus(); } });
        })();

        // AI-agent: active (n) | model_down (n) | inactive; startsWith voorkomt verwarring met 'inactive'
        function updateAIStatus() {
            fetch('teacher.php?ajax_ai_status=1&t=' + Date.now())
                .then(r => r.ok ? r.text() : '')
                .then(status => {
                    const el = document.getElementById('ai-agent-status');
                    if (!el || !status) return;
                    const count = (status.match(/\((\d+)\)/) || ['', ''])[1];
                    let state = 'inactive', label = 'AI-agent: inactief';
                    if (status.startsWith('active')) { state = 'active'; label = 'AI-agent: actief (' + count + ')'; }
                    else if (status.startsWith('model_down')) { state = 'model_down'; label = 'AI-agent: model niet bereikbaar'; }
                    el.dataset.state = state;
                    el.querySelector('.label').textContent = label;
                });
        }

        const handIcon = <?= json_encode(icon('hand')) ?>;
        function updateUnreadBadge() {
            fetch('teacher.php?ajax_unread=1')
                .then(r => r.ok ? r.text() : null)
                .then(count => {
                    if (count === null) return;
                    const n = parseInt(count, 10) || 0;
                    const stat = document.getElementById('unread-stat'); // alleen op het dashboard
                    if (stat) stat.textContent = n;
                    const container = document.getElementById('unread-badge-container');
                    if (container) container.innerHTML = n > 0 ? `<span class="count" title="Ongelezen berichten">${n}<span class="sr-only"> ongelezen</span></span>` : '';
                });
        }

        // Teams waarbij de AI een docent nodig heeft: badge bij Berichten en de tegel op het dashboard
        function updateEscalationBadge() {
            fetch('teacher.php?ajax_escalations=1')
                .then(r => r.ok ? r.text() : null)
                .then(count => {
                    if (count === null) return;
                    const n = parseInt(count, 10) || 0;
                    const stat = document.getElementById('escalation-stat');
                    if (stat) {
                        stat.textContent = n;
                        stat.closest('.stat')?.classList.toggle('is-warning', n > 0);
                    }
                    const container = document.getElementById('escalation-badge-container');
                    if (container) container.innerHTML = n > 0 ? `<span class="count count-warning" title="Operator nodig">${handIcon}${n}<span class="sr-only"> keer operator nodig</span></span>` : '';
                });
        }

        updateAIStatus();
        updateUnreadBadge();
        updateEscalationBadge();
        setInterval(() => { updateAIStatus(); updateUnreadBadge(); updateEscalationBadge(); }, 10000);
    </script>
    <?php if (isset($extraJS)) echo $extraJS; ?>
</body>
</html>
