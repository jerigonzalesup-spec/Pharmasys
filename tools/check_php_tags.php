<?php
$file = 'c:\\xampp\\htdocs\\Pharma_Sys\\pages\\products.php';
$s = file_get_contents($file);
$open = substr_count($s, '<?php');
$close = substr_count($s, '?>');
$shortEcho = substr_count($s, '<?=');
echo "<?php count: $open\n";
echo "?> count: $close\n";
echo "<?= count: $shortEcho\n";
// show last few php open tags context
$pos = strrpos($s, '<?php');
if ($pos !== false) {
  echo "Last <?php at pos: $pos (context)\n";
  echo substr($s, max(0,$pos-80), 200) . "\n";
}
$pos2 = strrpos($s, '?>');
if ($pos2 !== false) {
  echo "Last ?> at pos: $pos2 (context)\n";
  echo substr($s, max(0,$pos2-80), 200) . "\n";
}
