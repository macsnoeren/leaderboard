    </div> <!-- end main-content -->
    <script>
        function updateUnreadBadge() {
            // Gebruik teacher.php als endpoint voor de unread count
            fetch('teacher.php?ajax_unread=1')
                .then(r => r.text())
                .then(count => {
                    const container = document.getElementById('unread-badge-container');
                    const statDisplay = document.getElementById('unread-stat'); // Optioneel in dashboard
                    
                    if (statDisplay) statDisplay.innerText = count;

                    if (parseInt(count) > 0) {
                        container.innerHTML = `<span class="badge">${count}</span>`;
                    } else {
                        container.innerHTML = '';
                    }
                });
        }

        // Teams waarbij de AI een docent nodig heeft: badge bij Messages en de teller op het dashboard
        function updateEscalationBadge() {
            fetch('teacher.php?ajax_escalations=1')
                .then(r => r.text())
                .then(count => {
                    const container = document.getElementById('escalation-badge-container');
                    const statDisplay = document.getElementById('escalation-stat'); // Alleen in het dashboard
                    if (statDisplay) statDisplay.innerText = count;
                    if (container) {
                        container.innerHTML = parseInt(count) > 0 ? `<span class="badge badge-escalation" title="Docent nodig">🙋 ${parseInt(count)}</span>` : '';
                    }
                });
        }

        // De AI Status wordt al afgehandeld in admin_header.php
        setInterval(() => { updateUnreadBadge(); updateEscalationBadge(); }, 10000);
        updateUnreadBadge();
        updateEscalationBadge();
    </script>
    <?php if (isset($extraJS)) echo $extraJS; ?>
</body>
</html>