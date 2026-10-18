<?php
// conf/config.php in de worker-container: alleen nodig voor bin/migrate.php na een restore.
// Zelfde paden als de webcontainer (volumes); mail en BASE_URL gebruikt migrate.php niet.
define('DB_FILE', '/var/www/leaderboard/database/leaderboard.db');
define('STORAGE_DIR', '/var/www/leaderboard/storage');
define('ENABLE_EMAIL', false);
define('BASE_URL', 'http://localhost');
