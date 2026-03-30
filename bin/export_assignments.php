<?php
/**
 * Script om alle assignments inclusief artifacts te exporteren naar een tar.gz bestand.
 * Gebruik: php export_assignments.php [bestandsnaam.tar.gz]
 */

require_once __DIR__ . '/../conf/config.php';
require_once __DIR__ . '/../conf/database.php';

$db = getDB();

// 1. Haal alle assignments op
$assignments = $db->query("SELECT * FROM assignments ORDER BY assignment_number ASC")->fetchAll(PDO::FETCH_ASSOC);

if (empty($assignments)) {
    die("Geen opdrachten gevonden om te exporteren.\n");
}

$exportName = $argv[1] ?? 'assignments_export_' . date('Ymd_His') . '.tar';
// PharData heeft de .tar extensie nodig om mee te beginnen
if (substr($exportName, -4) !== '.tar') {
    $exportName .= '.tar';
}

try {
    if (file_exists($exportName)) unlink($exportName);
    if (file_exists($exportName . '.gz')) unlink($exportName . '.gz');

    $tar = new PharData($exportName);
    
    // 2. Maak een manifest bestand (JSON)
    $manifest = [];
    foreach ($assignments as $a) {
        $manifest[] = [
            'assignment_number' => $a['assignment_number'],
            'title'             => $a['title'],
            'description'       => $a['description'],
            'instruction'       => $a['instruction'],
            'criteria'          => $a['criteria'],
            'time_limit'        => $a['time_limit'],
            'artifact_file'     => $a['artifact_file'] ? basename($a['artifact_file']) : null
        ];

        // 3. Voeg het fysieke bestand toe aan de tar indien aanwezig
        if ($a['artifact_file']) {
            $filePath = __DIR__ . '/../htdocs/' . $a['artifact_file'];
            if (file_exists($filePath)) {
                $tar->addFile($filePath, 'files/' . basename($a['artifact_file']));
            } else {
                echo "Waarschuwing: Bestand niet gevonden: $filePath\n";
            }
        }
    }

    $tar->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    
    // 4. Comprimeer naar gz
    echo "Inpakken van " . count($assignments) . " opdrachten...\n";
    $tar->compress(Phar::GZ);
    
    // Verwijder de tijdelijke .tar (behoud de .tar.gz)
    unlink($exportName);

    echo "Export succesvol afgerond: " . $exportName . ".gz\n";

} catch (Exception $e) {
    echo "Fout bij export: " . $e->getMessage() . "\n";
    if (isset($exportName) && file_exists($exportName)) unlink($exportName);
}
>?