<?php

function run_layer4(string $in, string $out): void
{
    $code = file_get_contents($in);

    $namespace = '';
    if (preg_match('/^<\?php\s+namespace\s+([^;]+);/m', $code, $m)) {
        $namespace = $m[1];
    }

    $compressed = gzcompress($code, 9);
    if ($compressed === false) {
        fwrite(STDERR, "gzcompress failed for $in\n");
        exit(1);
    }
    $payload = base64_encode($compressed);

    $header = "<?php\n";
    if ($namespace) {
        $header .= "namespace $namespace;\n";
    }
    $header .= "(function(){\$f=tempnam(sys_get_temp_dir(),'wai');file_put_contents(\$f,gzuncompress(base64_decode('" . $payload . "')));require \$f;unlink(\$f);})();\n";

    file_put_contents($out, $header);
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $f = $argv[1];
    if (!file_exists($f)) { fwrite(STDERR, "Not found: $f\n"); exit(1); }
    run_layer4($f, $f);
    echo "Layer 4 done: $f\n";
}
