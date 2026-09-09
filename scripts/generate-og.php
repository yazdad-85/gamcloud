<?php

$font = '/System/Library/Fonts/Supplemental/Arial Bold.ttf';
$fontReg = '/System/Library/Fonts/Supplemental/Arial.ttf';
$w = 1200;
$h = 630;
$im = imagecreatetruecolor($w, $h);
imagealphablending($im, true);
$bg = imagecolorallocate($im, 14, 58, 46);
$deep = imagecolorallocate($im, 10, 40, 32);
$acc = imagecolorallocate($im, 34, 197, 154);
$txt = imagecolorallocate($im, 246, 250, 247);
$mut = imagecolorallocate($im, 170, 205, 190);
imagefilledrectangle($im, 0, 0, $w, $h, $bg);
imagefilledrectangle($im, 0, 0, 18, $h, $acc);
imagefilledellipse($im, 1050, -40, 520, 520, $deep);
imagefilledellipse($im, 1180, 520, 380, 380, $acc);
imagettftext($im, 52, 0, 72, 250, $txt, $font, 'Ular Tangga Edukatif');
imagettftext($im, 24, 0, 72, 320, $mut, $fontReg, 'Kuis kelas interaktif berbasis papan permainan');
imagettftext($im, 18, 0, 72, 520, $acc, $fontReg, 'edugame.cloudedu.id');
$path = __DIR__ . '/../public/assets/brand/og-default.png';
imagepng($im, $path);
imagedestroy($im);
echo "wrote {$path}\n";
