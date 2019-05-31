<?php declare(strict_types=1);

$backupPath = dirname(__DIR__, 1) . '/backup';

$jsons = array_slice(scandir($backupPath, SCANDIR_SORT_ASCENDING), 2);

$fileName     = ($_GET['user'] ?? 'user') . '.json';
$combinedJSON = fopen(implode('/', [$backupPath, $fileName]), 'wb+');
fwrite($combinedJSON, '[');

$amountOfJSONs = count($jsons) - 1;
foreach($jsons as $index => $json) {
    $json = implode('/', [$backupPath, $json]);
    $json = json_decode(file_get_contents($json), true);

    if($json === NULL) {
        continue;
    }

    $json = substr(json_encode($json), 1, -1);

    if($index !== $amountOfJSONs) {
        $json .= ',';
    }

    fwrite($combinedJSON, $json);
}

fwrite($combinedJSON, ']');
fclose($combinedJSON);

echo 'Done! Individual files as well as one combined file called ' . $fileName . ' are within /backup/.';
