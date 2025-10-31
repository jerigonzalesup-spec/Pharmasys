<?php
$s = file_get_contents('c:\\xampp\\htdocs\\Pharma_Sys\\pages\\products.php');
$tokens = token_get_all($s);
$len = count($tokens);
for ($i=max(0,$len-40); $i<$len; $i++){
  $t = $tokens[$i];
  if (is_array($t)){
    echo sprintf("%3d: %s -> %s\n", $i, token_name($t[0]), str_replace("\n","\\n", substr($t[1],0,60)));
  } else {
    echo sprintf("%3d: '%s'\n", $i, $t);
  }
}
