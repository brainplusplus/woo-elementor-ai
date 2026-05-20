<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: php obfuscate.php <file> [--skip-encrypt]\n");
    exit(1);
}

$file = $argv[1];
if (!file_exists($file)) {
    fwrite(STDERR, "File not found: $file\n");
    exit(1);
}

$skipEncrypt = in_array('--skip-encrypt', $argv);
$dir = __DIR__;
$php = PHP_BINARY;

echo "Obfuscating: $file\n";

$layers = [
    0 => "$dir/layer0-rename.php",
    1 => "$dir/layer1-strings.php",
    3 => "$dir/layer3-deadcode.php",
    5 => "$dir/layer5-integrity.php",
];

if (!$skipEncrypt) {
    $layers[4] = "$dir/layer4-encrypt.php";
}

ksort($layers);

foreach ($layers as $num => $script) {
    if (!file_exists($script)) {
        echo "Layer $num: skipped (not found)\n";
        continue;
    }
    passthru("$php $script $file", $ret);
    if ($ret !== 0) {
        fwrite(STDERR, "Layer $num failed for $file\n");
        exit(1);
    }
}

echo "Done: $file (" . count($layers) . " layers)\n";
