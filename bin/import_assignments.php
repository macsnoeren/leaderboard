<?php
/**
 * Script om assignments te importeren vanuit een tar.gz bestand.
 * Gebruik: php import_assignments.php <bestandsnaam.tar.gz>
 */

require_once __DIR__ . '/../conf/config.php';
require_once __DIR__ . '/../conf/database.php';

if (!isset($argv[1]) || !file_exists($argv[1])) {
    die("Gebruik: php import_assignments.php <bestandsnaam.tar.gz>\n");
}

$archiveFile = $argv[1];
$db = getDB();
$uploadDir = __DIR__ . '/../htdocs/artifacts/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

try {
    $phar = new PharData($archiveFile);
    
    // Tijdelijke map voor extractie
    $tmpDir = __DIR__ . '/import_tmp_' . time();
    mkdir($tmpDir);
    $phar->extractTo($tmpDir);

    $manifestPath = $tmpDir . '/manifest.json';
    if (!file_exists($manifestPath)) {
        throw new Exception("Ongeldig archief: manifest.json ontbreekt.");
    }

    $assignments = json_decode(file_get_contents($manifestPath), true);
    
    // 1. Verwijder huidige assignments (Schoon begin)
    echo "Huidige opdrachten database opschonen...\n";
    $db->exec("DELETE FROM assignments");
    
    $stmt = $db->prepare("INSERT INTO assignments (assignment_number, title, description, instruction, criteria, time_limit, artifact_file) VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($assignments as $a) {
        $newArtifactPath = null;
        
        // 2. Verplaats bestanden naar de juiste plek
        if ($a['artifact_file']) {
            $tmpFilePath = $tmpDir . '/files/' . $a['artifact_file'];
            if (file_exists($tmpFilePath)) {
                $safeName = 'assignment_' . $a['assignment_number'] . '_' . time() . '_' . $a['artifact_file'];
                copy($tmpFilePath, $uploadDir . $safeName);
                $newArtifactPath = 'artifacts/' . $safeName;
            }
        }

        // 3. Database vullen
        $stmt->execute([
            $a['assignment_number'],
            $a['title'],
            $a['description'],
            $a['instruction'] ?? '',
            $a['criteria'] ?? '',
            $a['time_limit'] ?? 0,
            $newArtifactPath
        ]);
        
        echo "Geïmporteerd: #" . $a['assignment_number'] . " - " . $a['title'] . "\n";
    }

    // 4. Cleanup
    echo "Opschonen tijdelijke bestanden...\n";
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    rmdir($tmpDir);

    echo "Import succesvol voltooid!\n";

} catch (Exception $e) {
    echo "Fout bij import: " . $e->getMessage() . "\n";
}
?>
```

### Hoe te gebruiken:

*   **Exporteren**: Ga naar de `bin` map en voer uit:
   `php export_assignments.php backup.tar.gz`
*   **Importeren**:
   `php import_assignments.php backup.tar.gz`

**Let op:** Het import-script verwijdert de bestaande opdrachten uit de database voordat het de nieuwe set inlaadt. Dit zorgt voor een schone "restore" situatie.

<!--
[PROMPT_SUGGESTION]Voeg een check toe aan het import script die vraagt om bevestiging voordat de database wordt geleegd.[/PROMPT_SUGGESTION]
[PROMPT_SUGGESTION]Maak een vergelijkbaar script om de hele database (inclusief teams en scores) te backuppen.[/PROMPT_SUGGESTION]
