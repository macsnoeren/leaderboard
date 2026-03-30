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
        setInterval(updateUnreadBadge, 10000);
    </script>
    <?php if (isset($extraJS)) echo $extraJS; ?>
</body>
</html>