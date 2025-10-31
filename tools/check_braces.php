<?php
$file = 'c:\\xampp\\htdocs\\Pharma_Sys\\pages\\products.php';
$s = file_get_contents($file);
$chars = str_split($s);
$stack = [];
$line = 1;
$col = 0;
for ($i=0;$i<strlen($s);$i++){
    $ch = $s[$i];
    if ($ch === "\n") { $line++; $col=0; continue; }
    $col++;
    if ($ch === '{') { $stack[] = ["line"=>$line, "col"=>$col, "pos"=>$i]; }
    if ($ch === '}') { array_pop($stack); }
}
if (count($stack)===0) { echo "All braces closed\n"; exit(0); }
echo "Unclosed braces count: " . count($stack) . "\n";
foreach ($stack as $s) echo "Unclosed { at line {$s['line']}, col {$s['col']}\n";
