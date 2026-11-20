<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * mPDF du livre : la couverture (page 1) n'affiche pas de numéro et n'est pas comptée ; la page suivante porte le numéro 1.
 * Le numéro est calculé sur la position finale de la page : la table des matières, que mPDF insère après coup, et ses renvois
 * restent donc cohérents avec les numéros imprimés.
 * Ce fichier n'est chargé que si mPDF est présent (TNGBook::mpdf_available()).
 */
class TNGBook_Mpdf extends \Mpdf\Mpdf {
    public function docPageNum($num = 0, $extras = false) {
        if ($num < 1) {
            $num = $this->page;
        }
        if ($num < 2) {
            return '';
        }
        $n = (string) ($num - 1);
        return $extras ? $this->pagenumPrefix . $n . $this->pagenumSuffix : $n;
    }
}
