<?php
// Contrôle de publication : génère un PDF avec mPDF tel qu'embarqué dans le zip (polices élaguées) et vérifie qu'il est valide.
require __DIR__ . '/../modules/book/vendor/autoload.php';
$tmp = sys_get_temp_dir() . '/mpdf-check';
@mkdir($tmp, 0777, true);
$m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmp, 'default_font' => 'dejavuserif']);
$m->SetCompression(true);
$m->SetFooter('{PAGENO}');
$m->WriteHTML('<h1>Généalogie — Élodie Ça</h1><p>àéîôü ñ ç œ 1850–1920 ★ Ωμέγα Кириллица</p><table border="1"><tr><td>a</td><td>b</td></tr></table><b>gras</b> <i>italique</i> <b><i>gras italique</i></b>');
$f = $tmp . '/check.pdf';
$m->Output($f, 'F');
$s = (string) file_get_contents($f);
if (strncmp($s, '%PDF', 4) !== 0 || strlen($s) < 1500) {
    fwrite(STDERR, "PDF invalide\n");
    exit(1);
}
echo 'mPDF OK : ' . strlen($s) . " octets\n";
