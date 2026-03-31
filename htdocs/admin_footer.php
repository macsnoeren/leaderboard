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

        function updateAIStatus() {
            fetch('teacher.php?ajax_ai_status=1')
                .then(r => r.text())
                .then(status => {
                    const el = document.getElementById('ai-agent-status');
                    if (!el) return;
                    if (status === 'active') {
                        el.style.color = '#4caf50';
                        el.innerText = '● Actief';
                    } else {
                        el.style.color = '#f44336';
                        el.innerText = '● Inactief';
                    }
                });
        }

        setInterval(() => { updateUnreadBadge(); updateAIStatus(); }, 10000);
        updateAIStatus(); // Directe check bij laden
    </script>
    <?php if (isset($extraJS)) echo $extraJS; ?>
</body>
</html>