=== TNG Suite ===
Tags: genealogy, family tree, tng, timeline, statistics
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Genealogy modules for websites running TNG (The Next Generation of Genealogy Sitebuilding): trees, timeline, heatmap, gallery, statistics, implex and inbreeding, family book. French and English.

== Description ==

TNG Suite brings the display of your TNG genealogy data into WordPress through shortcodes. One shared setting covers the database connection, the table prefix, the TNG URL and the privacy of living people; each module can then be enabled and tuned separately.

**Modules**

* Trees and descendants (`[arbre_tng]`, `[enfants_tng]`, `[patronyme_tng]`, `[liste_enfants_tng]`, `[stats_descendants_tng]`, `[lien_fiche_tng]`)
* Link button to a person's record (`[tng_link]`)
* Family timeline (`[tng_timeline]`)
* Places heatmap (`[tng_heatmap]`)
* Statistics and charts, with editable titles, labels, axes, legend and colour palettes (`[tng_stat_genre]`, `[tng_stat_vivants]`, `[tng_stat_villes]`…)
* Media gallery (`[tng_media_gallery]`)
* Random individual (`[tng_random_individual]`)
* Surname cloud and today's anniversaries (`[tng_name_cloud]`, `[tng_births_today]`…)
* Fan / kinship wheel: ancestors (up to 12 generations), descendants or mixed wheel, spouses, text that adapts when zooming, PDF export, generator in the administration, adjustable shapes and colour palettes (`[tng_fan]`)
* Implex and inbreeding analysis (`[tng_implexe]`, `[tng_consanguinite]`)
* Family book: Sosa ancestry and d'Aboville descendants, printable page or PDF (`[tng_book]`)
* Data consistency check (administrators only, no shortcode): impossible dates, ages, sex, family structure and possible duplicates with a score, read-only on your TNG data (TNG Suite > Stats & analyses)

**Connection to the TNG database** (TNG Suite > General), three modes:

1. Constants in `wp-config.php` (recommended; nothing is stored in the database).
2. Credentials entered in the plugin (the password is encrypted with libsodium using the WordPress security keys and is never displayed again).
3. TNG tables located in the WordPress database.

Use a MySQL account with read-only access (SELECT) for the plugin.

**Privacy of living people**

A default setting plus one setting per module: follow the shared setting, hide, or show (with a GDPR warning and a confirmation). Showing living people publicly may breach data-protection law: make sure you have their consent or restrict access to the pages.

**Languages**

The interface and the output of the modules are available in French and English (automatic, or forced in the settings). Names, places and notes coming from your TNG database are never translated.

== External services ==

TNG Suite reads data from **your own** TNG database. It does not send any data to the plugin author.

To display charts, graphs and maps, the plugin needs these JavaScript/CSS libraries. If a local copy is present in the plugin folder (see `libs/LISEZ-MOI.txt`), it is used and **no external request is made**. Otherwise the file is loaded from a public CDN, which receives the visitor's IP address and browser information, like any resource request:

* Chart.js 4.4.1 (MIT) – cdnjs.cloudflare.com – statistics and implex modules. Terms: https://cdnjs.com/ – Privacy: https://www.cloudflare.com/privacypolicy/
* vis-network 9.1.9 (MIT or Apache-2.0) – cdnjs.cloudflare.com – implex module.
* Leaflet 1.9.4 (BSD-2-Clause) – unpkg.com, and leaflet.heat 0.2.0 – cdnjs.cloudflare.com – heatmap module. Terms: https://unpkg.com/
* GLightbox 3.3.0 (MIT) – cdn.jsdelivr.net – media gallery module. Privacy: https://www.jsdelivr.com/terms/privacy-policy-jsdelivr-net
* The map module displays tiles from a tile provider chosen in its settings (Esri by default, or OpenStreetMap, OpenTopoMap, etc.), which receives the visitor's IP address and the tile coordinates being viewed.
* Automatic updates: the site periodically contacts the GitHub API (api.github.com) to look for a new release of this plugin (repository `cedlelensois-lang/Tng-Suite`). Only the request itself (server IP, WordPress and plugin version) reaches GitHub; nothing about your visitors or your TNG data. Terms: https://docs.github.com/site-policy/github-terms/github-terms-of-service – Privacy: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement. To disable: `define( 'TNGSUITE_GITHUB_REPO', '' );` in wp-config.php.

The vis-timeline library (8.5.4, MIT or Apache-2.0) is bundled in `modules/timeline/assets/` and is not loaded from any external site.

The PDF engine mPDF 8.3.1 (GPL-2.0-only) and its dependencies (setasign/fpdi, myclabs/deep-copy, psr/log and others, MIT), with the DejaVu fonts (free Bitstream Vera / DejaVu licence), are bundled in `modules/book/vendor/` (licence files included). They run on your own server: no external request is made to build a PDF.

== Installation ==

1. Upload the `tng-suite` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Deactivate the old standalone TNG plugins if you were using them (their settings are imported automatically).
3. Activate TNG Suite and open **TNG Suite > General**: choose the connection mode, then enter the TNG URL, the table prefix and the default tree.
4. Insert the shortcodes of the modules you want in your pages.

== Frequently Asked Questions ==

= I do not want to edit wp-config.php. =
Choose "Enter the credentials here" in TNG Suite > General.

= How do I switch the language? =
TNG Suite > General > Language: Automatic (WordPress language), French or English.

= Can living people be displayed? =
Yes, per module, in TNG Suite > General > Modules. A warning and a confirmation are shown. By default living and private people are hidden.

= Which files are removed when I delete the plugin? =
Only the plugin's own settings, caches, scheduled task and the tables it created in the WordPress database (`tngi_inbreeding` for the implex module; `tngs_coh_res` and `tngs_coh_key` for the data consistency check). Your TNG data is never touched.

== Changelog ==

= 3.1.1 =
* Flash list: with a place or period filter, the matching places are shown even when they were hidden behind "+N" (the others are dimmed).
* Flash list: the administration preview now includes living and private people (administrators only, unless the module is set to "Always hide"); new `rgpd="0"` shortcode attribute to show them on a given page (same safeguards as the fan chart: page author able to edit others' posts, module not set to "Always hide"); the exports follow.
* The "Flash list" module moves to the "Documents" tab (it is a document to export and share).
* Flash list: the place and period filters now apply to the same place (a surname is no longer kept because it has the place in another period); places that do not match are dimmed; the place filter searches all the places of a surname, not only the ones displayed.

= 3.1.0 =
* New module "Flash list" (shortcode `[tng_flash]`, Stats & analyses tab): table of surnames with the number of people, the minimum and maximum dates (births, marriages, deaths) and the places with their date range; spelling variants grouped but shown; minimum number of people per surname; place level (town, department, region, country); search, place and period filters, sorting, CSV/TXT export and "Copy for a forum". Living/private people follow the module privacy setting.
* The "Analyses" menu tab is now called "Stats & analyses".

= 3.0.2 =
* Fan chart: new option to show the TNG nickname in brackets after the first name ("Nicknames" setting, panel tick-box, `nicknames="1"` attribute; also in the PDF/PNG export). 10 new colour palettes suited to genealogy (Sepia, Heritage, Autumn, Sunset, Coast, Spring, shades of green/red/purple, Blue and pink for colouring by sex).

= 3.0.1 =
* Fan chart export (PDF/PNG): the downloaded file now shows what the on-screen preview shows when zoomed. Names and dates were missing in the outer generations because the text was dropped below 6 pt; it is now scaled down to fit (down to 0.4 pt, readable by zooming in the PDF). New "Maximum size" sheet; "Fit to the text" no longer falls back to A0 when everything cannot fit, it uses the largest possible sheet; the dialog defaults to "Complete" content and reports how many boxes are readable at 4 pt or more.
* Fan chart: the text of the centre disc no longer overflows the circle (each line is fitted to the width of the disc at its height), and the PDF no longer draws a stray vertical line from the rim to the centre.

= 3.0.0 =
* Première version stable : toutes les fonctions (livre, cohérence des données, éventail, implexe, fiche, statistiques, cartes…) sont figées ; les correctifs suivants seront des 3.0.x.
* Audit de sécurité : tous les points d’entrée (admin_post, AJAX, REST, shortcodes) vérifiés (droits et jeton), requêtes SQL préparées, sorties échappées, fichiers d’état signés ; aucune faille trouvée.
* Compatibilité PHP 7.4 à 8.5 : les polygones des illustrations de couverture n’utilisent plus le paramètre « nombre de points » (obsolète depuis PHP 8.1), la libération des images GD n’appelle plus `imagedestroy()` sur PHP 8 (obsolète en 8.5), l’export CSV de la cohérence précise son caractère d’échappement (obsolète en 8.4) : plus d’avis « Deprecated » sur les hébergements qui les affichent.
* Durcissement : fichiers `index.php` dans les dossiers du plugin ; outils de développement réservés à la ligne de commande et exclus du zip de la Release.
* Documentation FR/EN et catalogue de traductions revus (1 728 textes, aucun manquant).
* Livre : la couverture n’affiche plus de numéro de page et n’est plus comptée (PDF, Word, OpenDocument) : la page suivante porte le numéro 1, la table des matières suit les mêmes numéros.
* Livre : nouvelle pochette pleine page « campagne espagnole » (variante du chêne ensoleillé : herbes sèches dorées, feuillage olive, ciel plus profond) ; aperçu de chaque pochette sous la liste « Illustration de couverture ».

= 2.20.5 =
* Livre : un aperçu de la pochette s’affiche sous la liste « Illustration de couverture » et change dès que l’on choisit une autre illustration (17 vignettes, titre d’exemple ; les illustrations dessinées prennent ensuite la couleur du livre).

= 2.20.4 =
* Cohérence des données : une date saisie « Y » (ou « Yes », « Oui »), convention de TNG et de GEDCOM pour « l’événement a eu lieu, date inconnue », n’est plus signalée comme date non reconnue (contrôles D02 et E02). Relancer l’analyse pour retirer les anciens résultats.

= 2.20.3 =
* Livre : sur les pochettes pleine page (chêne, peinture…), un titre long associé à un sous-titre (auteur / mention) ne fait plus déborder « Document généré le… » sur la page 2 ; la police et les espacements du cartouche s’adaptent à la longueur du titre.

= 2.20.2 =
* Cohérence des données : après une analyse partielle, le résumé indique les parties relancées et précise que les autres anomalies datent de leur analyse précédente ; les effectifs par type sont cliquables (filtre) ; la liste s’ouvre sur le type analysé quand une seule partie (événements, sources et médias, lieux, doublons) a été relancée.

= 2.20.1 =
* Cohérence des données : une erreur PHP survenue dans la partie « Événements » ou « Sources et médias » ne provoque plus d’erreur 500 : la partie est passée et l’erreur (message et ligne) s’affiche sous le résumé de l’analyse.

= 2.20.0 =
* Cohérence des données : on choisit les parties à analyser avant de lancer l’analyse (personnes, familles, boucles, enfants de plusieurs familles, doublons, événements, sources et médias, lieux). Seules les parties cochées sont relancées, les résultats des autres sont conservés avec leur date.
* Cohérence des données, nouveaux contrôles avant un export GEDCOM : vie privée (personne probablement vivante non marquée « vivante » ni « privée », seuil réglable), événements (date impossible, non reconnue, dans le futur ; avant la naissance ; plus d’un an après le décès), sources et médias (citation d’une source inexistante, source jamais citée, part des personnes sans source, lien vers un média inexistant), caractères GEDCOM (barre oblique, caractère invisible, nom de plus de 120 caractères).
* Cohérence des données : la case « boucles d’ascendance » des réglages passe dans « Parties à analyser ».

= 2.19.5 =
* Cohérence des données : nouveau contrôle T16, « patronyme différent de celui du père » (enfant de naissance dont le nom de famille diffère de celui de son père ; les enfants adoptés, naturels ou recueillis sont exclus, de même que les noms voisins, les noms doubles, les « dit » et les noms inconnus). Seuil de similarité réglable, 0 pour désactiver. Le mariage posthume (D11) et l’âge de la mère à la naissance (D16, seuils réglables) étaient déjà contrôlés.

= 2.19.4 =
* Livre : les livres générés en arrière-plan depuis le formulaire (sans profil) sont listés sur une ligne « Hors profil » de la vue d’ensemble, avec les mêmes suppressions (« Supprimer », « Tout supprimer »).

= 2.19.3 =
* Livre : dans la vue d’ensemble des profils, chaque fichier généré (génération automatique ou manuelle) peut être supprimé du serveur (lien « Supprimer », ou « Tout supprimer » pour le profil), après confirmation.
* Livre (PDF) : les renvois (A1, D12, numéros des listes et des index…) ouvrent maintenant la bonne page au lieu de la première.

= 2.19.2 =
* Cohérence des données : filtre par anomalie (liste des contrôles groupée par type, avec le nombre de cas), colonne « Anomalie » (intitulé du contrôle) et colonne « Fiches » triables, colonne « Détail » pour le message ; les liens des fiches ouvrent la page d'édition de TNG (admin_editperson.php / admin_editfamily.php) ; l'export CSV reprend l'intitulé de l'anomalie.
* Cohérence des données : le chiffre dans un nom et le point d'interrogation sont deux contrôles distincts. Un nom ou un prénom réduit à « ? » ou placé entre crochets (« [Marie] »), conventions généalogiques d'un nom inconnu ou supposé, n'est plus signalé ; un « ? » mêlé à des lettres (« Dup?nt ») est signalé en information comme lecture incertaine.

= 2.19.1 =
* Livre (PDF) : avec une pochette pleine page à panneau en bas (chênes et peinture) et un titre long, la ligne « Document généré le… » passait seule sur une page presque blanche ; le titre long est désormais réduit pour tenir sur deux lignes dans le panneau.

= 2.19.0 =
* Nouveau module « Cohérence des données » (TNG Suite > Stats & analyses, réservé aux administrateurs, sans shortcode) : contrôle en lecture seule de l'arbre TNG. Dates impossibles ou incohérentes (naissance après le décès, enfant né après la mort de la mère ou plus de 9 mois après celle du père, mariage hors de la vie des époux, âges au mariage et à la naissance des enfants, personne « vivante » trop âgée…), sexe incompatible avec le rôle de père ou de mère ou avec le prénom, structure des familles (boucles d'ascendance, enfant de plusieurs familles, couples en double, références à des personnes absentes), noms mal saisis, lieux écrits de plusieurs façons, et doublons possibles avec un score de probabilité triable. Seuils réglables, anomalies ignorables et restaurables, export CSV, lancement manuel par lots avec barre de progression (adapté aux grands arbres), résultats conservés jusqu'à la prochaine analyse. Rien n'est écrit dans la base TNG ; les résultats sont stockés dans deux tables de la base WordPress, supprimées à la désinstallation.
* Livre : formulaire simplifié. Palettes de couleurs regroupées par famille (24 palettes, mieux différenciées, six renommées) ; « Titre, auteur, préface et page sur le patronyme » dans un volet repliable (replié quand tout est vide) ; « Génération automatique de ce profil » repliable ; une case à cocher par partie directement dans la liste « Ordre et affichage des parties du livre » (les parties masquées restent à leur place) ; phrases détaillées, lieux traduits (pays et départements) et lieux de résidence toujours actifs (cases retirées) ; index des noms et index des lieux séparés. Les réglages et profils enregistrés sont convertis automatiquement.
* Livre (PDF, modèle Moderne) : le filet vert à gauche des fiches reste continu même quand une photo accompagne le mini-arbre.
* Éventail : export PNG en plus du PDF, avec les mêmes options que le PDF (format de feuille, orientation, contenu) ; résolution au choix (150, 200 ou 300 dpi), fond blanc ou transparent ; la résolution est réduite automatiquement si elle dépasse la limite d'image du navigateur.

= 2.18.0 =
* Livre : deux ornements à feuilles d'arbre, dessinés par le plugin en camaïeu de la couleur du livre : le cadre « Cadre feuillage » (filet fin et petites branches de feuilles aux quatre coins, 9 mm) et l'illustration de couverture « Petit arbre et feuillages » (un petit arbre entre deux branches feuillues, au-dessus et au-dessous du titre). Word et OpenDocument : double filet pour le cadre, comme les autres cadres ornés.

= 2.17.4 =
* Livre : coins du cadre fleuri divisés par deux (6 mm, tiges plus fines).

= 2.17.3 =
* Livre : coins du cadre fleuri encore plus petits (12 mm, environ 1 cm de fleurs), visibles mais discrets.

= 2.17.2 =
* Livre : le cadre fleuri (style Monet) a des coins deux fois plus petits (19 mm) qui ne recouvrent plus le titre, et les fleurs, feuillages et nymphéas des deux ornements Monet sont dessinés en camaïeu de la couleur du livre (« en-têtes et liens ») au lieu de coquelicots rouges fixes.

= 2.17.1 =
* Documentation entièrement revue en français et en anglais : une page par menu avec tous les réglages, un tableau des attributs de chaque shortcode (dont les 11 statistiques), référence rapide des shortcodes, rubrique confidentialité et sécurité ; correction de `[tng_stat stat="…"]` (l'exemple indiquait `type`), de `[liste_enfants_tng]` (identifiant de famille) et des guides texte (numéros de version obsolètes). README GitHub complété et fichier SECURITY.md ajouté ; le zip des Releases n'embarque plus le dossier docs/ (5 Mo de captures).
* Désinstallation : supprime aussi les options de la fiche (`tngfg_options`), de l'éventail (`tngfan_gen_saved`), des listes de faits historiques du livre, le verrou de purge du cache du livre et l'état du vérificateur de mises à jour GitHub.
* Conformité : texte de confidentialité suggéré dans Réglages > Confidentialité (français / anglais) ; section « External services » complétée (vérification des mises à jour auprès de l'API GitHub, fond de carte Esri par défaut).

= 2.17.0 =
* Livre : deux ornements dans le style de Monet, dessinés par le plugin : l'illustration de couverture « Nymphéas et coquelicots » (nymphéas, galettes, coquelicots et roseaux au-dessus et au-dessous du titre, rides dans la couleur du livre) et le cadre de page « Cadre fleuri » (filet fin et coins de coquelicots et de feuillages ; double filet dans Word et OpenDocument).

= 2.16.0 =
* Livre : neuf nouvelles palettes de couleurs accordées aux pochettes illustrées (Chêne et prairie, Écorce et ciel d'été, Ciel de campagne, Terre et racines, Ferme en pierre et tuiles, Peinture impressionniste, Coquelicot et feuillage, Prairie fleurie, Pastel de la campagne).

= 2.15.2 =
* Livre : pochettes à chêne ou peinture : le panneau du texte est un cadre blanc net (sans flou) à 80 % d'opacité, le décor reste visible au travers.

= 2.15.1 =
* Livre : pochettes à chêne ou peinture : le panneau flouté du texte est transparent à 80 % (le décor reste bien visible), le texte d'auteur, de chiffres et de date est en gras foncé pour rester lisible.

= 2.15.0 =
* Livre : quatre pochettes pleine page de plus (PDF) : trois chênes et une peinture impressionniste, avec racines et campagne. Sur ces pochettes à chêne, le texte de la couverture (titre, auteur, chiffres, date) est maintenant en bas de page, sur un panneau flouté peu transparent.

= 2.14.1 =
* Livre : pochette « chêne » : le panneau du texte de couverture est un flou translucide (le feuillage reste visible au travers) au lieu d'un blanc opaque.

= 2.14.0 =
* Livre : troisième pochette pleine page (PDF), « chêne et ses racines, campagne, texte sur fond blanc flouté » : le texte de la couverture (titre, auteur, chiffres, date) se pose sur un panneau blanc flouté en haut de page.

= 2.13.3 =
* Livre : arbre « racines » de la couverture : racines dessinées à part, courbes souples qui s'effilent et s'infléchissent vers le bas, avec quelques radicelles (au lieu de branches rectilignes).

= 2.13.2 =
* Livre : arbre « racines » de la couverture : système racinaire plus clairsemé (trois racines maîtresses, ramifications réduites).

= 2.13.1 =
* Livre : arbres de couverture, racines plus naturelles : le pied du tronc s'évase en courbe (plus de pointes ni de brindilles au-dessus du sol) ; dans l'arbre « racines », collet évasé et racines maîtresses irrégulières qui restent sous la ligne de sol, tronc plus long sans croisement ; dans l'arbre en couleurs, plus de branches de racines au-dessus de l'herbe.

= 2.13.0 =
* Livre : deux nouvelles pochettes pleine page (PDF), à l'aide d'illustrations fournies : « arbre aux étiquettes vierges et oiseaux, prairie fleurie » et « grand tronc, feuillage pastel et campagne ». Le titre se place dans la zone libre ; dans les autres formats, un arbre dessiné par le plugin les remplace.

= 2.12.0 =
* Livre : un nouveau livre (bouton « Nouveau livre », première installation) part des réglages recommandés : modèle Élégant, filet simple, arbre de couverture en couleurs, PDF (HTML si le PDF direct est indisponible), photos avec silhouettes, baptême et sépulture, phrases détaillées, lieux avec pays, militaire, notes, fiche de chaque conjoint, chronologie et fiches avec contexte historique, « En chiffres », arbres, listes, cousinages, index et carte. Les profils enregistrés ne changent pas.
* Livre : génération automatique, fin de la bêta (bandeau retiré) ; fichiers conservés par profil : 1 par défaut, 3 au maximum ; la vue d'ensemble des profils liste les fichiers conservés dès qu'il y en a plusieurs.
* Statistiques : sur la page de réglages, les types d'événement « profession » et les catégories de métiers passent sous le tableau des shortcodes ; la note de [tng_stat_metiers] demande de vérifier ces paramètres ; le champ « profession » vaut OCCU par défaut (aussi s'il est vide).

= 2.11.6 =
* Livre : formulaire des listes de faits historiques : l'intitulé « Listes fournies » n'apparaît plus quand toutes les listes fournies sont retirées ; la « liste par défaut » ne s'affiche que pour l'application « selon le lieu de naissance ».

= 2.11.5 =
* Livre : retrait de l'option « faits marquants de son vivant » des fiches (2.11.3), trop longue et redondante avec la ligne des périodes : les événements datés des listes s'affichent uniquement dans la chronologie familiale ; les fiches gardent la ligne « Contexte historique » (périodes).

= 2.11.4 =
* Livre : les listes de faits historiques fournies par défaut peuvent être retirées du formulaire du livre (Livre > Réglages > Faits historiques : décochez-les). Elles restent disponibles comme modèles à copier dans vos listes.

= 2.11.3 =
* Livre : nouvelle option « Dans les fiches, ajouter aussi les événements datés des listes cochées survenus entre la naissance et le décès » (cochée par défaut) : une ligne « Faits marquants de son vivant : 1939 : … ; 1975 : … » sous la fiche. Les fiches n'affichaient que les périodes (« date .. date | nom ») : une liste faite d'événements seulement ne donnait donc rien.

= 2.11.2 =
* Livre : gestion des listes de faits historiques refaite. Une liste déroulante « Liste à modifier » ouvre chacune de vos listes pour la modifier ou la supprimer, ou crée une nouvelle liste (vide ou copiée d'une liste fournie, remplie tout de suite pour être modifiée avant l'enregistrement) ; après l'enregistrement la liste reste sélectionnée.

= 2.11.1 =
* Livre : le contexte historique des fiches n'est plus une phrase composée (« décès pendant… ; a aussi vécu… ») mais une simple énumération des périodes concernées, avec les noms écrits dans les listes : plus de mélange de langues dans un livre catalan ou espagnol qui utilise une liste fournie en anglais.

= 2.11.0 =
* Livre : pour chaque livre (et chaque profil), vous choisissez les listes de faits historiques à afficher et comment : « à tout le livre » (par exemple les listes France et Espagne pour toutes les personnes) ou « selon le lieu de naissance » (mots-clés de lieu). Les périodes de même nom issues de plusieurs listes (Première Guerre mondiale…) sont fusionnées dans la phrase des fiches ; avec plusieurs listes, chaque fait de la chronologie indique sa liste entre parenthèses.

= 2.10.1 =
* Livre : après l'enregistrement d'une liste de faits historiques, la page se rouvre sur la section « Livre : réglages » (elle s'ouvrait sur « Générer un livre ») ; le formulaire du livre renvoie vers la gestion des listes.

= 2.10.0 =
* Livre : faits historiques gérés par listes. Dans Livre > Réglages > Faits historiques, vous créez vos propres listes (France, England, Catalunya, España…) en écrivant les textes dans la langue de votre choix (« date | texte » pour un événement, « date .. date | nom » pour une période), avec des mots-clés de lieu ; import et export en fichier .txt, copie possible d'une liste fournie (France, Belgique, Suisse, Espagne, Italie, Allemagne, Royaume-Uni, États-Unis, Canada ; mots-clés du Canada étendus aux provinces). Dans le formulaire du livre, des cases à cocher choisissent les listes à utiliser (chronologie et contexte historique des fiches), plus une liste par défaut. Les anciens réglages (pays par défaut, événements personnalisés) sont convertis automatiquement ; une liste sans mot-clé de lieu vaut pour tout le monde.

= 2.9.1 =
* Livre : dans l'ascendance, la petite photo du conjoint et le lien vers sa fiche sont conservés ; dans la descendance (option « fiche détaillée pour chaque conjoint »), la fiche suit directement, sans petite photo.

= 2.9.0 =
* Livre : nouvelle option « Fiche pour le conjoint de chaque descendant » (D12c, D12c2…) ; la phrase d'union se limite au nom avec un lien. Cochée pour un nouveau réglage, décochée pour les réglages déjà enregistrés. Textes « Époux de / Épouse de » en fr/en/es/ca.

= 2.8.9 =
* Livre : les notes des événements sont affichées pour tous les événements présents dans la fiche (profession, résidences, baptême, divorce en plus de la naissance, du mariage, du décès, de l'inhumation et des événements militaires).
* Livre (PDF) : la photo suit désormais le titre de la fiche ; le filet sous le titre ne passe plus par-dessus la photo.

= 2.8.8 =
* Livre : le contexte historique des fiches (« a vécu… ») fonctionne aussi sans date de naissance, à partir d'un mariage connu : une personne décédée deux jours après l'armistice a bien vécu la guerre.

= 2.8.7 =
* Livre : le contexte historique des fiches ne s'applique plus seulement aux personnes dont une date tombe dans une guerre : la ligne ajoute « a vécu la Première Guerre mondiale… » pour les périodes traversées de leur vivant (naissance et décès connus). Textes fr/en/es/ca.

= 2.8.6 =
* Livre : nouvelle couverture « Pochette pleine page » (PDF) : fond crème sur toute la première page, arbre à l'aquarelle qui part du bord gauche, feuilles gris-bleu, titre dessous ; dans les autres formats, l'arbre penché habituel.
* Livre (PDF) : la couverture avec illustration ne déborde plus sur une 2e page quand le titre est long (trois lignes) ; marges de la date et des chiffres réduites.

= 2.8.5 =
* Livre (PDF direct) : plus de page blanche entre la table des matières et la première partie (mPDF saute déjà une page après la table des matières) ; le filet sous le titre d'une fiche ne traverse plus la photo (modèle Élégant).

= 2.8.4 =
* Livre : nouvelle illustration de couverture « Arbre en couleurs » (feuillage vert, tronc brun, racines dans l'îlot de terre, médaillons ronds vides sur les branches), image large de 14 cm. Dessin original généré par le plugin.

= 2.8.3 =
* Livre : l'arbre à racines de la couverture est maintenant feuillu (houppier à l'aquarelle, deux maîtresses racines symétriques dessinées sous le sol, comme un arbre généalogique). Le réglage « sans feuilles » de la 2.8.2 devient ce choix.

= 2.8.2 =
* Livre : quatre arbres de couverture au choix (chêne ample, arbre penché aux branches légères, saule pleureur, arbre sans feuilles avec ses racines dessinées dessous) ; racines arrondies.
* Sécurité : les fichiers d'état de la génération en arrière-plan sont signés (HMAC) avant d'être relus, un fichier modifié ou déposé dans le dossier de travail n'est jamais désérialisé.
* Traductions : une douzaine de libellés manquants en anglais (avertissements RGPD, « Hauteur (px) », « Mode d'affichage », arbre : « Conjoint(e) », « Mariage »…).
* Documentation : mPDF inclus (guides), alias `[tng_fiche]`, section « Services externes » complétée (mPDF et polices DejaVu) ; capture d'écran égarée retirée du dépôt ; le workflow de Release n'exécute composer que si mPDF manque.

= 2.8.1 =
* Correctif : l'aperçu en direct et l'export PDF de l'éventail (« Générer une roue ») ne s'affichaient plus depuis le nouveau menu ; les scripts des sections sont de nouveau chargés.
* mPDF (PDF direct) est maintenant inclus dans le code source du dépôt : le « Source code (zip) » de GitHub contient lui aussi le moteur PDF, plus de message « ce plugin ne contient pas mPDF ».
* Livre : nouvel arbre de couverture, plus fourni et plus doux (cime de feuilles semi-transparentes à l'aquarelle, lavis, tronc évasé, racines, quelques feuilles qui tombent).

= 2.8.0 =
* Livre : illustration de couverture (arbre de vie, ou fleurons au-dessus et au-dessous du titre), dessinée par le plugin dans la couleur du livre ; nouveau « Cadre orné » (angles creusés et filet intérieur) pour le cadre autour des pages. PDF, Word, OpenDocument et page imprimable.
* Menu d'administration simplifié : 4 onglets de familles (Arbres & visualisations, Analyses, Documents, Médias & widgets) en plus de « Général », chaque ancienne page devenant une section. Le tableau « Modules » est groupé par famille.
* « Bouton vers la fiche » est regroupé avec « Arbres & descendance », et « Individu au hasard » avec « Nuage de noms & anniversaires » : une seule case d'activation et un seul réglage « Vivants / privés » pour chaque couple. Les shortcodes et les réglages enregistrés ne changent pas.

= 2.7.1 =
* Livre, PDF : (1) les numéros de page avaient disparu quand « Cadre autour des pages » était activé ; ils sont rétablis, à l'intérieur du cadre. (2) La table des matières du PDF affiche maintenant les numéros de page (points de conduite, niveaux Parties / Générations, liens cliquables).

= 2.7.0 =
* Livre : option « Contexte historique dans les fiches » (décochée par défaut). Une ligne en italique ajoute, sous la fiche, « Contexte historique : naissance pendant la Première Guerre mondiale ; décès pendant la Seconde Guerre mondiale » quand la date tombe dans une guerre ou une période marquante du pays de naissance (9 pays). Une date imprécise (année seule) n'est retenue que si toute l'année est dans la période.

= 2.6.0 =
* Livre : réglages simplifiés. Les 29 cases sont regroupées en « Contenu des fiches », « Parties du livre », « Poids du fichier » et « Options avancées » (mode diagnostic), et plusieurs réglages sont réunis en un seul choix : Photos (aucune / photos / photos et silhouettes), Baptême et sépulture, Chronologie (aucune / familiale / avec contexte historique), Phrases détaillées (avec âge au mariage et résidences), Lieux traduits (pays et départements), Informations militaires (guerre et « Mort pour la France »), Arbres, Listes récapitulatives, « En chiffres » (prénoms et noms fréquents inclus). Les réglages et profils déjà enregistrés sont convertis automatiquement.

= 2.5.2 =
* Livre : enfants des couples dans l'ascendance (nouvelle option, cochée par défaut ; modèles Classique, Élégant, Moderne) : « X et Y ont N enfants : » puis la liste (années, renvoi vers la fiche pour l'enfant de la lignée), placée dans la fiche du mari. Dans la descendance, la liste d'enfants porte maintenant la même phrase avec le nombre d'enfants.

= 2.5.1 =
* Livre : cocher « Contexte historique » active maintenant automatiquement la chronologie familiale (les événements historiques s'y affichent ; ils restaient invisibles quand la chronologie n'était pas cochée).

= 2.5.0 =
* Livre : trois nouveautés demandées par un utilisateur. (1) Contexte historique dans la chronologie : option à cocher qui insère, en italique, les grands événements (guerres, révolutions, épidémies, lois…) du pays de naissance de chaque personne (France, Belgique, Suisse, Espagne, Italie, Allemagne, Royaume-Uni, États-Unis, Canada) ; un lieu sans pays reconnu se rattache au pays par défaut (France) ; événements personnalisés possibles. (2) Option « Cadre autour des pages » (filet simple ou double filet, couleur du livre, pas de cadre sur la couverture) pour la page imprimable, le PDF, Word et OpenDocument. (3) Page de titre : titre plus grand, en couleur, encadré de filets. Corrige aussi une page blanche après la couverture dans le PDF.

= 2.4.7 =
* Livre, PDF, modèle « Élégant » : les lettres accentuées des titres en petites capitales (ç, è, ë, à…) s'affichaient comme un carré vide (« Fran□ois »). Les petites capitales sont maintenant imitées dans le PDF (initiale puis majuscules réduites), sans changement d'aspect.

= 2.4.6 =
* Heatmap : les chiffres au centre des ronds ne se chevauchent plus. À partir de l'échelle d'une région (zoom 8), les plus gros nombres sont posés en premier et un chiffre qui en recouvrirait un autre est omis jusqu'au zoom suivant ; le nombre reste toujours dans l'infobulle au survol.

= 2.4.5 =
* Version de republication de la 2.4.4 (mêmes correctifs : reprise du PDF en arrière-plan).

= 2.4.4 =
* Livre, génération en arrière-plan du PDF : correction de la reprise entre deux étapes (erreur « __PHP_Incomplete_Class » : la bibliothèque PDF n'était pas encore chargée quand le document en pause était relu).

= 2.4.3 =
* Livre, génération en arrière-plan du PDF : le PDF est maintenant écrit en plusieurs étapes courtes (environ 12 s de travail chacune) au lieu d'un seul long processus. Entre deux étapes, le document est mis en pause sur le serveur puis repris par une nouvelle tâche planifiée. Les hébergeurs qui interrompent un processus long (constaté vers 40 à 60 s) ne bloquent donc plus un gros livre ; le résultat est identique au PDF généré d'un seul tenant. Le panneau indique le numéro de l'étape.
* Livre, PDF : un très gros livre n'est plus confié à mPDF en morceaux de plusieurs centaines de Ko. Coupures tous les 100 Ko au plus entre deux blocs et tous les 80 Ko au plus entre deux lignes d'un long tableau (listes des ascendants et des descendants, chronologie) : le tableau est refermé puis rouvert avec sa ligne d'en-tête. Un tableau de plus de 1 Mo, qui faisait échouer la génération, passe maintenant. Le journal indique la durée du morceau précédent.

= 2.4.2 =
* Livre, génération en arrière-plan : le journal d'avancement indique maintenant, pour le PDF, le morceau du livre en cours (numéro, taille, début du texte) et la mémoire utilisée, et l'interruption d'un travail affiche ces informations pour identifier ce qui l'arrête. Le moteur PDF travaille sans le compilateur JIT de PCRE (aucun effet sur la vitesse), ce qui écarte un plantage silencieux de PHP sur les très gros livres.

= 2.4.1 =
* Livre, génération en arrière-plan : un travail qui ne donne plus signe de vie est signalé au bout de 5 minutes (au lieu de 10). Le message d'interruption indique la durée de travail avant l'arrêt, les limites du serveur (temps, mémoire, PHP) et la mémoire maximale utilisée, pour identifier la limite de l'hébergeur ; le panneau « en cours » affiche l'âge du dernier signe d'activité quand il dépasse une minute.
* Heatmap : l'option « Afficher les chiffres au centre » ne montre plus les chiffres qu'à fort zoom (à partir de l'échelle d'un département). Vus de loin ils se chevauchaient et devenaient illisibles ; le nombre reste toujours visible dans l'infobulle au survol.

= 2.4.0 =
* Nouveau shortcode [tng_recent_updates limit="10"] (module « Nuage de noms & anniversaires ») : liste des derniers individus mis à jour dans TNG, le plus récent en premier, avec la date de dernière modification. Réglages du shortcode : limit (nombre d'individus, 1 à 100), tree, date, date_format, link et title. Les personnes vivantes ou privées sont exclues par défaut (réglage « vivants / privés » du module) ; la liste est mise en cache une heure.

= 2.3.0 =
* Livre : nouveau bouton « Générer en arrière-plan » (et « En arrière-plan » pour chaque profil, dans la vue d'ensemble). Pour un gros livre, que l'hébergeur coupe avant la fin (erreur 500 après une longue attente), le livre est préparé par une tâche planifiée du serveur : un panneau affiche l'état et l'avancement (lecture de la base, mise en page, création du PDF…), propose l'annulation, puis le téléchargement quand le livre est prêt. Une interruption par l'hébergeur est détectée et expliquée (dernière étape atteinte, mémoire utilisée). Fonctionne pour tous les formats ; le fichier est déposé dans le dossier de la génération automatique.
* Désinstallation : la tâche planifiée de génération automatique et l'état de la génération en arrière-plan sont aussi supprimés.
* Heatmap : nouvelle palette par défaut, orange clair (#FFA500) / orange foncé (#F0590C) / rouge sombre (#B30000). Les sites restés sur l'ancienne palette d'origine (bleu / jaune / rouge) passent automatiquement à la nouvelle ; une palette personnalisée n'est jamais modifiée, et le bouton « Revenir à la palette recommandée » (réglages du module) la rétablit en un clic.
* Heatmap : l'intensité suit maintenant une échelle logarithmique et les lieux isolés restent colorés à tous les niveaux de zoom. Les foyers secondaires (quelques dizaines d'événements) ressortent nettement en orange, et seul le plus gros foyer de la sélection affichée atteint le rouge sombre.
* Heatmap : les réglages « Seuil faible max » et « Seuil moyen max » sont supprimés (l'échelle est désormais automatique).

= 2.2.1 =
* PDF direct : les gros livres (beaucoup de photos) ne provoquent plus d'erreur 500. Les images sont confiées à mPDF une seule fois chacune (mémoire nettement réduite), le plugin demande plus de mémoire et de temps au serveur quand il l'autorise, et un manque de ressources affiche un message explicite avec la marche à suivre (« Compresser les images », moins de générations, ou Word / OpenDocument).
* Livre > Réglages : la ligne « PDF direct » explique précisément ce qui manque quand mPDF est absent (installer tng-suite.zip des Assets de la Release, pas « Source code (zip) »).

= 2.2.0 =
* Livre : dans le Word et l'OpenDocument, les images alignées à gauche ou à droite dans le texte de la page patronyme gardent leur place, avec le texte autour (elles étaient recentrées sous le paragraphe).
* Livre et module « Individu au hasard » : nouveau réglage « Style des silhouettes » pour les personnes sans photo, entre « Portrait » (cercle sur fond clair, comme avant) et « Rond » (sans fond). Par défaut : portrait, rien ne change.

= 2.1.0 =
* Livre : génération automatique par profil (fonction encore jeune, signalée « Bêta » dans l'écran). Pour chaque profil : fréquence (chaque jour, semaine ou mois), jour et heure ; le livre (PDF, Word, OpenDocument ou HTML, selon le profil) est écrit dans un dossier du serveur (protégé par défaut, ou le dossier de votre choix, par exemple un dossier synchronisé avec OneDrive), avec rotation des anciens fichiers, option « seulement si les données ont changé » et notification par e-mail.
* Livre : la page d'administration est organisée en trois onglets, comme l'éventail : « Générer un livre », « Réglages » et « Vue d'ensemble des profils ». Quand des profils existent, « Générer un livre » n'affiche d'abord que le choix du profil (formulaire avec « Nouveau livre » ou en chargeant un profil).
* PDF direct intégré : le zip publié sur GitHub (Releases) embarque mPDF (polices DejaVu uniquement) : plus aucune installation à faire. Installation depuis le code source : composer install --no-dev dans modules/book.
* PDF direct : le PDF était inutilisable (milliers de pages blanches) et plantait sur les gros livres ; corrigé. Le texte des fiches passe maintenant à côté de la photo, avec un espace entre les deux ; les fiches peuvent se couper entre deux pages.
* Traductions anglaises complétées (écrans du livre). Aucun changement de réglages ni de shortcodes : la mise à jour est directe.

= 2.0.1 =
* Heatmap : retrait des fonds CARTO (Positron, Voyager, Dark Matter), qui demandent une inscription ; 8 fonds restent, tous sans clé d'API ni inscription. Le fond par défaut devient « Esri Gris clair » (les sites qui utilisaient CARTO basculent dessus automatiquement).
* Version 2.0 : première version majeure, validée en production. Aucun changement de réglages ni de shortcodes par rapport à la 1.x : la mise à jour est directe.
* Au programme de cette version : livre généalogique complet (page patronyme, chronologie familiale, statistiques avec espérance de vie et pyramide des âges, compression d'images réglable, cache de photos protégé), 8 fonds de carte gratuits pour la heatmap, mises à jour automatiques depuis GitHub, audit sécurité / PHP 7.4 à 8.5 / WordPress 7.1 / traductions.
* Livre : la vignette du conjoint est affichée à côté de l'union même quand le conjoint a sa propre fiche dans le livre.

= 1.36.6 =
* Livre : un fichier de photo en cache vide ou tronqué est refait au lieu d'être réutilisé.

= 1.36.5 =
* Mises à jour automatiques actives par défaut depuis le dépôt GitHub du plugin (plus besoin de définir la constante dans wp-config.php).

= 1.36.4 =
* Espérance de vie (statistiques et livre) : toujours par siècle du décès (l'attribut basis est supprimé).

= 1.36.3 =
* Espérance de vie par siècle (statistiques et livre) : par siècle de naissance, les personnes nées il y a moins de 100 ans sont écartées (générations incomplètes qui faussaient le dernier siècle) ; courbes en segments droits (plus de dépassements).

= 1.36.2 =
* Audit : traduction anglaise de la note des statistiques (« Les siècles avec moins de… cas »).

= 1.36.1 =
* Livre, chapitre « En chiffres » : espérance de vie par siècle de naissance (colonne Ensemble + courbes hommes / femmes / ensemble) et pyramide des âges au décès (dessin en PDF / page imprimable, tableau dans Word / OpenDocument).

= 1.36.0 =
* Statistiques : espérance de vie moyenne par siècle ([tng_stat_esperance], par siècle de naissance ou de décès, hommes / femmes / ensemble) et pyramide des âges ([tng_stat_pyramide], âges au décès ou âges des vivants une année donnée).

= 1.35.8 =
* Livre : protection du cache des photos (noms de fichiers non devinables, .htaccess et web.config sans listing ni indexation, purge automatique des photos de plus de 24 h).

= 1.35.7 =
* Audit : traduction anglaise du titre de la page patronyme (champ d'aide), aucune faille trouvée.

= 1.35.6 =
* Livre : charger un profil enregistré avant la page patronyme ne conserve plus le texte patronyme du profil précédent (il est vidé).

= 1.35.5 =
* Livre : la compression des images devient une option (« Compresser les images », désactivée par défaut = qualité d'origine, comme avant 1.35.3). Activée : photos réduites en PDF direct et Word / OpenDocument, images du texte patronyme à 150 ppp.

= 1.35.4 =
* Livre : en PDF, Word et OpenDocument, les photos des personnes sont réduites (240 × 320 px, JPEG 72 %) puisqu'elles s'impriment sur 2 à 3 cm ; la page imprimable (écran) garde 360 × 480 px. Compression PDF explicite.

= 1.35.3 =
* Livre, page patronyme : les images sont réduites à la taille imprimée (150 ppp) et recompressées (JPEG 80 %, PNG conservé si transparent) : le PDF, Word et OpenDocument restent légers.

= 1.35.2 =
* Livre, page patronyme : l'alignement d'une image (à gauche / à droite, texte autour) est conservé à l'enregistrement et dans la page imprimable et le PDF. Dans Word et OpenDocument, l'image est placée après son paragraphe.

= 1.35.1 =
* Livre, page patronyme : le copier-coller conserve davantage de mise en forme (alignement, couleurs, titres, tableaux, légendes) et les images collées depuis le presse-papiers (captures d'écran) ou depuis une page web sont reprises.

= 1.35.0 =
* Livre : page de présentation du patronyme. Texte saisi dans un éditeur (mise en forme, listes, tableaux, images de la médiathèque), enregistré dans le profil du livre, placé où l'on veut dans « Ordre des parties » (en tête par défaut). Vide = pas de page. Reprise dans la page imprimable, le PDF, Word et OpenDocument.

= 1.34.4 =
* Générer des documents : la langue de TNG Suite > Général s'applique vraiment (un réglage « Français » enregistré dans le module ne la contredit plus ; seuls catalan et espagnol sont conservés).

= 1.34.3 =
* Correction de la traduction anglaise « Sheet & documents ».

= 1.34.2 =
* Anglais : « Fiche » (mot français) remplacé par « Sheet » dans les menus et titres.

= 1.34.1 =
* Menu : les pages d'aide des modules Arbres et Extra portent le nom de leur module (deux entrées « Aide » identiques prêtaient à confusion).

= 1.34.0 =
* Une seule page par module : réglages et outils sont réunis en sections (Implexe : analyse / consanguinité / réglages ; Statistiques : réglages / graphiques ; Éventail : générer / réglages ; Fiche : générer / réglages). Les anciens liens redirigent vers la bonne section.
* Pages d'aide pour les modules Arbres et Extra (shortcodes, attributs, exemples).
* Générer des documents : la langue suit celle choisie dans Général (elle n'est plus lue dans le navigateur).

= 1.33.0 =
* Traductions par gettext : domaine « tng-suite », catalogues languages/tng-suite.pot et tng-suite-en_US.po/.mo (1076 textes, JavaScript compris, contexte « js »). Une autre langue s'ajoute en déposant un .mo (Poedit, Loco Translate). Les textes du livre (français, anglais, espagnol, catalan) ne changent pas : ils restent dans modules/book/includes/lang*.php. Le dictionnaire includes/i18n-en.php devient la source tools/i18n-src/, régénérée par php tools/build-i18n.php.

= 1.32.1 =
* Publication GitHub : le script de téléchargement des bibliothèques est lancé avec bash (le droit d'exécution se perd au téléversement par le navigateur).

= 1.32.0 =
* Administration réorganisée : la barre d'onglets est groupée par module (un onglet par module, ses pages en sous-onglets), le menu est trié dans l'ordre des modules, et la page « Général » est découpée en sections (Tableau de bord avec état de chaque module et liens vers ses réglages, Connexion, Réglages communs, Modules, Maintenance). Aucun réglage enregistré n'est modifié.
* Bibliothèques : GLightbox et le CSS / images de Leaflet sont désormais fournis ; script tools/fetch-libs.sh (et publication GitHub) pour embarquer Chart.js, vis-network, Leaflet et Leaflet.heat sans CDN.

= 1.31.0 =
* Livre : option « Mini-arbre d'ascendance » : un petit arbre en cases (la personne, ses parents et grands-parents) en tête de la fiche de chaque ancêtre (écran, PDF, Word, ODT). Désactivée par défaut.

= 1.30.2 =
* Mort pour la France : le champ Date de l'événement militaire est toujours affiché dès qu'il est renseigné, même quand la cause de décès indique déjà un conflit.

= 1.30.1 =
* Mort pour la France : le conflit est lu dans le champ Date de l'événement militaire (« 1914-1918 ») et le champ Détail (grade, unité) est ajouté entre parenthèses. Les événements « Ancien combattant », « mobilisation », « prisonnier de guerre »… sont reconnus comme militaires.

= 1.30.0 =
* Livre : mention « Mort pour la France » (drapeau tricolore) pour toute personne dont une cause d'événement commence par « Mort pour la France », avec la guerre ou la bataille : complément de la cause, sinon texte des événements militaires. Option activée par défaut ; fonctionne même si l'option « informations militaires » est coupée.
* Mises à jour automatiques depuis les Releases GitHub (si TNGSUITE_GITHUB_REPO est défini), fichiers README / LICENSE / publication automatique.

= 1.29.1 =
* Livre : phrase de mariage « Il épousa, à l'âge de 26 ans, le 8 décembre 1939 à …, Narcisa … (A3), âgée de 26 ans lors de leur union, née le … et décédée le … » ; si les deux âges sont identiques : « tous deux âgés de 26 ans lors de leur union ». Surnoms entre guillemets droits remplacés par les guillemets de la langue.

= 1.29.0 =
* Livre : la date et le lieu du mariage précèdent désormais le conjoint (« Il épousa, le 8 décembre 1939 à Barcelona, Narcisa … ») dans les 4 langues.
* Arbres : un mariage est marqué ⚭ (deux anneaux) et une union sans mariage ×, dans les listes et dans les cases (SVG, PDF, Word, ODT).
* Arbres en cases : la largeur des cases s'adapte aux noms longs (jusqu'à un maximum), au lieu de les tronquer à 27 caractères.
* Catalan : « Taula de continguts », « Llocs de residència », « Germans més nombrosos ».

= 1.28.0 =
* Livre : les fiches des ascendants décrivent maintenant aussi les autres mariages et unions de l'ancêtre (jusqu'ici seule l'union dont descend la lignée était citée), dans l'ordre chronologique, avec « Veuf de … » / « Divorcé de … ». Option « Autres mariages et unions des ancêtres », activée par défaut.

= 1.27.1 =
* Traductions anglaises manquantes dans l'administration du livre (ordre des parties, noms des parties, mode diagnostic, limites de générations et de personnes). Contrôle automatique : plus aucune phrase française non traduite détectée dans les écrans d'administration du plugin.

= 1.27.0 =
* Livre, exports Word et OpenDocument : les schémas d'arbres (ascendance et descendance) sont désormais reproduits sous forme d'images (traits, cases, couleurs de la palette), au lieu de listes indentées. Dessinés avec la police Liberation Sans fournie (licence SIL OFL, dans modules/book/assets/fonts) ; nécessite l'extension GD avec FreeType, sinon retour à la liste indentée.

= 1.26.1 =
* Audit de compatibilité et de sécurité : contrôle PHP 7.4 à 8.5 et API WordPress (aucune fonction obsolète détectée) ; livre : confinement renforcé des photos dans le dossier TNG (comparaison avec séparateur, chemins Windows), nom du fichier de carte filtré, dossier de cache sans listing, appel GD obsolète protégé (PHP 8.5) ; désinstallation : suppression des profils de livre enregistrés.

= 1.26.0 =
* Livre, partie « Cousinages et implexes » : coefficient de consanguinité (méthode de Wright) de la personne de départ et, pour chaque union entre apparentés, de l'enfant du couple (ex. 6,25 % (1/16) pour des cousins germains). Calculé uniquement sur les générations chargées dans le livre : c'est un minimum (mention dans le livre). FR/EN/ES/CA.

= 1.25.0 =
* Livre : trois palettes (« Héritage & parchemin », « Ardoise & vieux rose », « Vert sauge & cuivre »). Les silhouettes de remplacement restent celles d'origine.

= 1.24.4 =
* Livre : intertitres « Génération N » renforcés sur tous les modèles : titre centré en capitales espacées, filet épais au-dessus et filet fin en dessous (couleur « Générations, codes et liens »), davantage d'espace avant. Aussi dans les exports Word.

= 1.24.3 =
* Livre : trois nouvelles palettes harmonisées : « Sépia & Tradition Chaleureuse », « Forêt Ancienne », « Bleu Nuit & Or ».

= 1.24.2 =
* Livre, profils : le profil chargé devient « actif » (affiché en haut) ; son nom est prérempli et un bouton « Mettre à jour le profil » enregistre vos modifications sous le même nom.

= 1.24.1 =
* Livre : plus de plafond sur les générations (valeurs de départ : 6 ascendants, 4 descendants). Le plafond de 4000 personnes devient un réglage administrateur « Nombre maximum de personnes » (4000 au départ, 0 = illimité) ; le formulaire public ne peut pas le modifier.

= 1.24.0 =
* Livre : profils de livre enregistrés. Nom + « Enregistrer comme profil » mémorise tous les réglages du livre (personne, arbre, générations, langue, options, ordre des parties, modèle, couleurs, titre, préface) ; une liste en haut de la page permet de charger ou supprimer un profil (jusqu'à 50). La connexion TNG, les photos et le formulaire public ne font pas partie des profils.

= 1.23.3 =
* Livre : unions de même sexe, âge au mariage : « (il avait 30 ans et Auguste 32 ans) » au lieu de « il … et il … ».

= 1.23.2 =
* Livre : la fin de la dernière union est maintenant mentionnée (« Le couple se sépara en septembre 2013. » ou « Le couple divorça. »), y compris quand la date de divorce vaut « Y » (fin connue, date inconnue). Avant, seules les unions suivies d'une nouvelle union étaient rappelées. Union avec date de mariage = divorce ; sans date de mariage = séparation.

= 1.23.1 =
* Livre : fin d'une union précédente sans répétition : « Séparée de X en septembre 2013, elle épousa… » (au lieu de « Séparée de X (séparés en septembre 2013) ») ; idem pour « Divorcé(e) de ».

= 1.23.0 =
* Livre : nouvelle partie « Cousinages et implexes », ajoutée automatiquement seulement si un même ancêtre apparaît plusieurs fois dans l'ascendance (désactivable). Elle liste les ancêtres présents plusieurs fois (numéros Sosa) et les unions entre apparentés : couple, lien (cousins germains, issus de germains, ou nombre de générations jusqu'à l'ancêtre commun) et ancêtres communs. Place réglable dans l'ordre des parties. FR/EN/ES/CA. Les cousinages au sein de la descendance ne sont pas analysés.

= 1.22.0 =
* Livre, deux nouvelles options : « Frères et sœurs sur les fiches » (ligne « Frères et sœurs : … » avec années et lien vers la fiche quand elle existe ; personnes vivantes masquées selon la confidentialité) et « Âge des époux au mariage » (« … le 12 mai 1950 à Lille (il avait 27 ans et elle 24 ans) » ; âge approximatif si seule l'année est connue ; rien si une date de naissance manque). FR/EN/ES/CA. Les demi-frères et demi-sœurs ne sont pas listés.

= 1.21.0 =
* Livre : l'administration permet de choisir l'ordre des parties (ascendance, descendance, en chiffres, carte des lieux de vie, index des noms, index des lieux, sources, liste des ascendants, liste des descendants) avec des boutons monter/descendre. Les parties désactivées sont ignorées ; la table des matières suit l'ordre choisi ; les index restent complets quelle que soit leur place.

= 1.20.2 =
* Livre, résidences : plusieurs lieux = une phrase rédigée : « Après avoir résidé successivement à Zaragoza (Espagne), Lille (France), Lambersart, Comines (Belgique) et Pérenchies (France), elle s'est établie à Nieppe (France), où elle réside actuellement. » (pays écrit à la première mention, quand il change et au dernier lieu ; passé pour les personnes décédées : « A résidé à …, puis à … »). FR/EN/ES/CA.

= 1.20.1 =
* Livre : « A résidé à … » (au lieu de « A vécu à ») pour les résidences ; pour une personne vivante (quand les vivants sont affichés), le texte passe au présent : « Réside à … », « Il exerce comme profession : … ».

= 1.20.0 =
* Livre : nouvelle option « Lieux de résidence » (désactivée par défaut) : « A vécu à Comines (Belgique), Nieppe (France). », lieux distincts du plus ancien au plus récent (événements Résidence de TNG ; sans date en dernier). Dans le modèle « récit », la phrase rejoint la liste des autres informations.
* Livre : un code postal seul (« 7780 ») n'est plus affiché comme niveau de lieu.

= 1.19.2 =
* Livre, carte des lieux de vie : « Province de Hainaut », « Département du Nord », « Provincia de Girona »… sont reconnus (mot de structure ignoré) ; les codes postaux belges (4 chiffres) donnent la province.

= 1.19.1 =
* Livre : le mode diagnostic détaille aussi la carte des lieux de vie : pour chaque personne, chaque lieu pris en compte (naissance, décès, mariage, résidence), sa classification (département/province, pays seul, autre pays, non reconnu) et la raison (nom trouvé, code postal, coordonnées TNG approximatives…).

= 1.19.0 =
* Livre : nouvelle option « Liste des ascendants » (tableau récapitulatif : n° Sosa, naissance, conjoint, décès, âge), placée avant la liste des descendants (FR/EN/ES/CA).
* Livre : la traduction des « régions » porte maintenant sur les départements, provinces et États (Bouches-du-Rhône / Bocas del Ródano, Girona, New South Wales…) ; les grandes régions (Bretagne, Catalogne…) ne sont plus traduites.

= 1.18.0 =
* Livre : option de traduction des départements, provinces et États dans la langue du livre (désactivée par défaut), y compris dans le tableau des cartes finales. Hors connexion.

= 1.17.0 =
* Livre : nouvelle option « Baptême » (désactivée par défaut) : affiche la date et le lieu du baptême (naissance alternative de type baptême/christening dans TNG, à défaut le champ baptême), quand ils sont renseignés, sous la naissance de chaque personne et dans la ligne de chaque conjoint.
* Livre : nouvelle option « Sépulture » (activée par défaut, comme avant) : inhumation, ou « Incinéré(e) » quand la fiche TNG est marquée incinération (même sans date ni lieu) ; reprise aussi dans la ligne des conjoints.
* Livre : nouvelle option « Phrases détaillées » (désactivée par défaut) : « Il est né le … à …, fils de … », « Il est décédé le …, à l'âge de … ans », « Il a été baptisé / inhumé / incinéré … », « Il exerçait comme profession : … » (FR, EN, ES, CA). Les mentions courtes restent utilisées pour les conjoints.
* Livre : nouvelle option « Pays entre parenthèses » (désactivée par défaut) : « Lyon, Rhône (France) », pays traduit dans la langue du livre (FR/EN/ES/CA) et déduit du département, de la province ou de l'État quand il n'est pas écrit. Fonctionne hors connexion (table interne de pays). Les cartes finales (autres pays, mentions France/Espagne/Belgique) sont aussi traduites, sans condition.

= 1.16.1 =
* Livre, arbres de descendance : une personne avec plusieurs unions n'a plus tous ses conjoints dans une même case ; chaque union est un nœud distinct (case en pointillés, ou ligne « × Nom » dans l'arbre en liste) auquel se rattachent ses propres enfants. Dans une case terminale, un conjoint par ligne.

= 1.16.0 =
* Livre, arbres visuels de descendance : les conjoints sont maintenant indiqués (« × Nom ») dans l'arbre en liste (avec leurs années) et dans les cases (ligne supplémentaire en italique) ; conjoints vivants masqués selon le mode choisi.

= 1.15.0 =
* Livre : option « Photo du conjoint » (cochée par défaut) : petite vignette à côté de la phrase d'union quand le conjoint a une photo par défaut dans TNG et n'a pas sa propre fiche dans le livre ; jamais pour une personne vivante masquée.

= 1.14.0 =
* Livre : neuf palettes de couleurs harmonisées (bordeaux, bleu marine, forêt, sienne, ardoise et cuivre, prune, bleu canard, vieux papier, sobre) à appliquer en un clic aux sept couleurs réglables ; chaque couleur reste modifiable ensuite.

= 1.13.0 =
* Livre, carte des lieux de vie : carte du monde schématique (contours de continents très simplifiés) avec un nombre par pays pour les autres pays, en plus des cartes France, Espagne et Belgique ; le tableau reste présent.

= 1.12.1 =
* Livre, carte des lieux de vie : contours des pays du fond schématique (France, Espagne, Belgique) beaucoup plus précis.

= 1.12.0 =
* Livre : nouvelle option « Carte des lieux de vie » (noir et blanc) : nombre de personnes par département (France), province (Espagne, Belgique) ou pays, d'après les lieux de naissance, de résidence, de mariage et de décès. Fond de carte précis si des fichiers GeoJSON (regions-fr/es/be.geojson) sont déposés dans modules/book/data/, sinon fond schématique. Tableau récapitulatif sous chaque carte (seul élément présent dans les exports Word/ODT).

= 1.11.1 =
* Livre, « En chiffres » : deux options distinctes (prénoms / noms de famille les plus fréquents) ; ces comptages ne portent plus que sur les personnes réellement présentes dans le livre (ascendants, descendants et conjoints).
* Livre : nouvelle option « prénoms et noms de famille les plus fréquents » (voir 1.11.1).
* Livre, « En chiffres » : la colonne du tableau des noms de famille s'intitule « Nom de famille » / « Surname » / « Apellido » / « Cognom » (au lieu de « Nom »).
* Catalan : « Frères et sœurs » devient « Germans » dans la fiche individuelle.
* « Y » TNG (décédé, date inconnue) : affiché « † » dans les listes/fiche du module Fiche, le tableau du livre et la frise ; ignoré ailleurs au lieu d'afficher « Y ».
* Livre, liste des descendants : séparateurs de génération, personne décrite en gras (conjoints en normal, union en gris), « Y » TNG remplacé par « décédé ».
* Livre, liste des descendants : repli sur le baptême (« bapt. … ») quand la naissance manque et sur l'inhumation (« inhum. … ») quand le décès manque ; l'âge utilise ces dates de repli.
* Livre : nouvelle option « Liste des descendants » (Contenu) — tableau récapitulatif (n° d'Aboville, naissance, conjoint et union, décès, âge) à la toute fin du document, avec renvois d'implexe ; vivants masqués absents ; HTML/PDF/Word/OpenDocument.
* Fiche individuelle : les événements dont la base ne donne que le code GEDCOM (OCCU, RESI, EDUC…) affichent un libellé lisible et traduit (Profession, Résidence, Éducation…).
* Fiche & documents : l'affiche généalogique A3, la clé API et la palette sont retirées ; [tng_fiche] affiche désormais l'outil de listes. « D'où » devient « Enfant(s) » dans la fiche individuelle.
* Documents (descendants) : quand les deux conjoints d'un couple descendent de la souche, les enfants sont numérotés sous le premier et l'autre ligne porte « enfants : voir n° … » (au lieu de lignes dupliquées).
* Documents : implexes pris en compte — un ancêtre ou descendant qui apparaît plusieurs fois est détaillé une seule fois, puis renvoyé à son premier numéro (« implexe : voir n° … »), en fr/en/ca/es.
* Documents : avec « Masquer » les personnes vivantes/privées, elles sont retirées des listes et fiches (plus de lignes « personne vivante ») ; compteur, rangs de fratrie et numérotation d'Aboville ne comptent que les personnes affichées.
* Documents (fiche, listes, affiche) : le surnom TNG est ajouté entre parenthèses après le prénom.
* Fiche & documents : la page « Générer des documents » reprend le style de l'administration WordPress.
* Interface d'administration : barre d'onglets commune à toutes les pages TNG Suite (Général + une page par module).
* Sécurité : listes IN() et purges de transients passées en requêtes préparées (livre, éventail, stats, implexe, désinstallation) ; nonce du livre assaini ; recherche fiche assainie.
* All settings pages share one layout (title, TNG connection state with living/private reminder, shortcodes, same buttons). English translation gaps in the administration filled; automatic check of untranslated texts.
* Family book: layout templates. "Classic" (the original, unchanged) plus "Narrative, 2 columns" (generation banners, sentences, parents' ages, other known information, couples and children), "Compact", "Elegant" and "Modern". Applied to the printable page, PDF, Word and OpenDocument.
* Fiche & documents: documents can also be generated from the administration (TNG Suite > Generate documents).
* Fiche & documents: the documents can be produced in French, English (UK), Catalan or Spanish (default language in the settings, optional language list for visitors).
* New module "Fiche & documents" ([tng_listes] ancestor/descendant lists and individual sheet; PDF, Excel, Word) replacing the standalone TNG Fiche Généalogique plugin; you choose which fields appear in the documents (admin defaults and optional per-visitor checkboxes). Uses the common connection, prefix and living/private settings.
* Family book: new higher-resolution default portraits (man, woman, unknown sex), replacing the old silhouettes; the random-person module uses them too (including unknown sex).

= 1.8.0 =
* Family book: new "By the numbers" chapter (option "By the numbers", on by default): overview, completeness of the ancestry by generation, demography (age at death, at first marriage, at first child, children per couple, largest families), infant mortality, most frequent first names and surnames (overall and by century), places of birth/marriage/death and moves between generations, three histograms and a timeline of direct ancestors.
* Family book: key figures (people, families, sources, period) on the title page.
* Word/OpenDocument export: simple tables supported; histograms become tables with text bars.

= 1.7.2 =
* Family book: the visual trees now have their own colours (lines and boxes, highlighted generation); names and years in the boxes follow the name and caption colours.

= 1.7.1 =
* Family book, Word/OpenDocument export: each entry with a photo is now a two-column borderless table (text | photo), so photos can no longer overlap or drift.

= 1.7.0 =
* Fan wheel: couples shown like Heredis. Each spouse is a thin strip on the outer edge of the ring, with "NAME First name (years)" and the marriage date ("M : date"), facing the children of that union. Coloured edge on every ring (light style). New "Text style" setting (° † x, or N : D : M :) in the module settings, in the on-screen options and as a shortcode attribute labels="letters".
* Fan wheel generator: the configuration is saved each time you click "Generate" and restored on the next visit.
* Family book: customisable text colours (part titles, generations/codes/links, names, body text, years/sources/captions), used by the printable page, the PDF and the new exports.
* Family book: download as Word (.docx) or OpenDocument (.odt), with photos, links between entries, page numbers and the classic trees as indented lists. Requires the PHP zip extension.

= 1.6.2 =
* Family book: the per-generation mini-wheels are replaced by classic box trees (root on the left, branches to the right, three generations per tree, current generation highlighted). The book no longer contains any wheel.

= 1.6.1 =
* Fan wheel: the spouses of the central person now form an inner ring (one arc per marriage, facing the children of that marriage), so children of several marriages can be told apart.
* Fan wheel PDF: a dialog to choose the sheet size (A4 to A0 or "Fit to the text"), the orientation and the content, with a live count of the boxes whose full text fits.
* Family book: the ancestors and descendants parts now open with an indented tree "Name (code)" instead of a wheel (the per-generation mini-wheels are kept).

= 1.6.0 =
* Fan wheel: ancestors up to 12 generations (8,191 ancestors); spouses drawn (outer ring of each descendant and in the centre); text recomputed when zooming (more lines, years, towns, full dates and places as you zoom in); vector PDF export (A4 to A0, text following the arcs); living people can be shown on a given wheel with the `living` shortcode parameter (signed, ignored if the module is set to "Always hide" or if the page author cannot edit others' posts); generate the wheel from the administration (TNG Suite > Fan chart: generate a wheel) with a live preview and a shortcode to copy.
* Family book: visual trees. A complete wheel opens the ancestors part (Sosa) and the descendants part (d'Aboville); at each generation a mini-wheel highlights that generation and numbers the people described below. Option "Visual trees" in the book settings.

= 1.5.0 =
* New module: fan / kinship wheel (ancestors, descendants, mixed), configurable generations on each side, full / three-quarter / half circle, colouring by generation, branch or sex, configurable palettes, zoom and pan, search and re-centring.
* Fixed: many English labels in the administration were wrong (the French to English dictionary was misaligned). The dictionary is now built from explicit French/English pairs.

= 1.5.0 =
* New module: fan / kinship wheel (ancestors, descendants, mixed) in SVG, without any external library.

= 1.4.5 =
* Family book: when the previous union was not a marriage (no marriage date), the remarriage clause says "separated" instead of "divorced", and "after the death of" instead of "widower / widow".

= 1.4.4 =
* Record link button: new "Text colour on hover" setting.

= 1.4.3 =
* Family book: before a remarriage, the book states how the previous union ended (divorce, or death of the spouse) with the exact date when known.

= 1.4.2 =
* Fixed: settings of the Statistics module (chart type, palette, labels…) showed "saved" but were not kept. Same safeguard added to the Implex, Timeline and General settings.

= 1.4.1 =
* Family book: notes attached to events (birth, marriage, death, burial, war/military events) are printed; event dates such as "1939-1945" are kept as written; the military heading reads "Military service / War event"; only photos with the "Default photo" status are used.

= 1.4.0 =
* French/English language setting.
* Places statistic counts births, baptisms, marriages, deaths and burials.
* Family book: a couple without a marriage date is described as living together instead of married.
* vis-timeline 8.5.4 bundled; local-first loading of third-party libraries.

= 1.3.0 =
* Statistics module with editable charts and palettes; new living/deceased chart; privacy setting per module.

= 1.2.0 =
* Implex and family book modules; credentials can be entered in the plugin.

= 1.1.0 =
* Link button module.

= 1.0.0 =
* First unified release.

== Upgrade Notice ==

= 1.6.1 =
Deactivate any old standalone TNG plugin before activating TNG Suite.
