=== TNG Suite ===
Tags: genealogy, family tree, tng, timeline, statistics
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.35.6
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
* The map module displays tiles from a tile provider chosen in its settings (OpenStreetMap or another provider), which receives the visitor's IP address and the tile coordinates being viewed.

The vis-timeline library (8.5.4, MIT or Apache-2.0) is bundled in `modules/timeline/assets/` and is not loaded from any external site.

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
Only the plugin's own settings, caches, scheduled task and the `tngi_inbreeding` table created by the implex module. Your TNG data is never touched.

== Changelog ==

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
