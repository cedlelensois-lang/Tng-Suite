<?php
/**
 * Génère les catalogues gettext du plugin à partir des sources de tools/i18n-src/ :
 *   languages/tng-suite.pot            modèle (textes sources en français)
 *   languages/tng-suite-en_US.po/.mo   traduction anglaise
 * Usage : php tools/build-i18n.php
 * Les textes JavaScript sont dans le même catalogue, avec le contexte « js » (msgctxt).
 * Pour une autre langue : copiez le .pot (Poedit, Loco Translate…) et déposez le .po/.mo dans languages/ (ex. tng-suite-es_ES.mo).
 */
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}
$root = dirname( __DIR__ );
$fr   = require __DIR__ . '/i18n-src/fr-en.php';
$js   = require __DIR__ . '/i18n-src/js-fr-en.php';

function q( $s ) {
    return '"' . str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $s ) . '"';
}

$head = "Project-Id-Version: TNG Suite\nReport-Msgid-Bugs-To: \nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n";
$entries = array(); // [ctxt|null, msgid, msgstr]
foreach ( $fr as $k => $v ) {
    $entries[] = array( null, (string) $k, (string) $v );
}
foreach ( $js as $k => $v ) {
    $entries[] = array( 'js', (string) $k, (string) $v );
}

function po( $entries, $head, $lang, $blank ) {
    $o = "# TNG Suite – textes sources en français.\nmsgid \"\"\nmsgstr " . q( $head . ( $lang ? "Language: $lang\n" : '' ) . "Plural-Forms: nplurals=2; plural=(n != 1);\n" ) . "\n";
    foreach ( $entries as $e ) {
        $o .= ( $e[0] ? 'msgctxt ' . q( $e[0] ) . "\n" : '' ) . 'msgid ' . q( $e[1] ) . "\nmsgstr " . q( $blank ? '' : $e[2] ) . "\n\n";
    }
    return $o;
}

function mo( $entries, $head, $lang ) {
    $m = array( '' => $head . "Language: $lang\nPlural-Forms: nplurals=2; plural=(n != 1);\n" );
    foreach ( $entries as $e ) {
        if ( '' === $e[2] ) {
            continue;
        }
        $m[ ( $e[0] ? $e[0] . "\x04" : '' ) . $e[1] ] = $e[2];
    }
    ksort( $m, SORT_STRING );
    $n = count( $m );
    $ids = '';
    $str = '';
    $io = array();
    $so = array();
    foreach ( $m as $k => $v ) {
        $io[] = array( strlen( $k ), strlen( $ids ) );
        $ids .= $k . "\0";
        $so[] = array( strlen( $v ), strlen( $str ) );
        $str .= $v . "\0";
    }
    $keys_start = 28 + 16 * $n;
    $vals_start = $keys_start + strlen( $ids );
    $out = pack( 'V*', 0x950412de, 0, $n, 28, 28 + 8 * $n, 0, 0 );
    foreach ( $io as $x ) {
        $out .= pack( 'VV', $x[0], $keys_start + $x[1] );
    }
    foreach ( $so as $x ) {
        $out .= pack( 'VV', $x[0], $vals_start + $x[1] );
    }
    return $out . $ids . $str;
}

@mkdir( $root . '/languages' );
file_put_contents( $root . '/languages/tng-suite.pot', po( $entries, $head, '', true ) );
file_put_contents( $root . '/languages/tng-suite-en_US.po', po( $entries, $head, 'en_US', false ) );
file_put_contents( $root . '/languages/tng-suite-en_US.mo', mo( $entries, $head, 'en_US' ) );
file_put_contents( $root . '/includes/i18n-js-keys.php', "<?php\n// Généré par tools/build-i18n.php : textes JavaScript traduisibles (contexte gettext « js »).\nif ( ! defined( 'ABSPATH' ) ) {\n    exit;\n}\nreturn " . var_export( array_map( 'strval', array_keys( $js ) ), true ) . ";\n" );
echo count( $entries ), " entrées.\n";
