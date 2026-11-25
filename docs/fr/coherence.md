# Cohérence des données

Contrôle de votre arbre TNG : dates impossibles ou incohérentes, âges, sexe, structure des familles, noms mal saisis, lieux écrits de plusieurs façons et **doublons possibles** (avec un score de probabilité). Module réservé aux administrateurs, **sans shortcode** et en **lecture seule** : rien n’est modifié dans TNG, chaque anomalie renvoie vers la page d’édition de la fiche dans TNG pour que vous la corrigiez vous-même.

## Dans l’administration

Menu **TNG Suite > Stats & analyses**, sections **Cohérence : contrôle des données** et **Cohérence : seuils et anomalies ignorées**.

## Utilisation

1. Choisissez l’**arbre**, déroulez au besoin **Parties à analyser** pour ne cocher que ce qui vous intéresse (voir plus bas), puis cliquez sur **Lancer l’analyse**. L’analyse se fait par lots avec une barre de progression (personnes, familles, boucles d’ascendance, enfants de plusieurs familles, couples en double, doublons, lieux) : elle convient aux grands arbres et peut durer plusieurs minutes. Vous pouvez l’**annuler** (les résultats sont alors partiels) ; si la page est fermée en cours de route, un bouton **Reprendre** apparaît au retour.
2. Les anomalies s’affichent dans un tableau : **gravité** (Erreur, Avertissement, Information), **type**, **anomalie** (l’intitulé du contrôle, par exemple « Décès avant la naissance »), **détail** (le message avec les noms et dates), **fiches** concernées (un clic ouvre la fiche **en édition** dans TNG, dans un nouvel onglet ; il faut être connecté à l’administration de TNG) et **score** pour les doublons. Les colonnes Gravité, Type, Anomalie, Fiches et Score sont **triables** ; la liste se filtre par type, par **anomalie** (liste groupée par type, avec le nombre de cas), par gravité et par texte (nom, identifiant…). Le CSV exporté respecte les filtres et le tri.
3. Corrigez dans TNG, puis relancez l’analyse pour mettre les résultats à jour.
4. **Ignorer cette anomalie** retire une fausse alerte de la liste ; elle reste ignorée aux analyses suivantes (même contrôle, mêmes personnes ou famille). La section **Seuils et anomalies ignorées** les liste et permet de les **restaurer** une à une ou toutes ensemble.
5. **Exporter en CSV** télécharge la liste filtrée (séparateur point-virgule, UTF-8 avec BOM : s’ouvre directement dans Excel ou LibreOffice).
6. **Effacer les résultats** supprime les résultats de l’arbre affiché.

## Ce qui est contrôlé

| Type | Exemples de contrôles |
|---|---|
| Dates | date impossible (31 février, mois inexistant), date non reconnue (une date « Y », « Yes » ou « Oui », convention TNG pour « événement avéré, date inconnue », n’est pas signalée), date dans le futur ; décès, baptême ou sépulture avant la naissance ou le décès ; âge au décès excessif ; personne marquée « vivante » alors qu’elle est née il y a très longtemps ou qu’un décès est renseigné ; mariage avant la naissance ou après le décès d’un époux ; âge minimal au mariage ; enfant né avant ses parents, après le décès de sa mère, ou plus de 9 mois après le décès de son père ; âge de la mère et du père à la naissance des enfants ; divorce avant le mariage. Les dates approximatives (« vers », « avant », « après »…) sont comparées avec une tolérance. |
| Sexe | père (époux) de sexe féminin, mère (épouse) de sexe masculin, sexe inconnu alors que la personne a des enfants, couple de même sexe, prénom généralement du sexe opposé (information indicative, d’après une liste de prénoms français courants). |
| Structure | nom de famille d’un enfant de naissance différent de celui de son père (hors enfants adoptés, naturels ou recueillis ; ignoré si les noms sont voisins, partagent un mot ou contiennent « dit » ; réglable ou désactivable), personne sans aucun lien, boucle d’ascendance (une personne qui est son propre ancêtre), personne son propre conjoint, famille sans époux ni épouse, famille vide, enfant naturel de plusieurs familles, parent et enfant de la même famille, enfant enregistré deux fois, couple présent dans plusieurs familles, référence à une personne absente de la base ; noms : nom de famille en minuscules, espaces superflus, chiffre dans le nom, point d’interrogation mêlé à des lettres (lecture incertaine), personne sans nom ni prénom. |
| Vie privée | personne sans décès ni sépulture connus, née il y a moins de 100 ans (réglable), qui n’est marquée ni « vivante » ni « privée » : elle serait exportée en clair. |
| Événements | pour les événements de la table d’événements de TNG (profession, résidence, émigration, événements personnalisés des personnes et des familles) : date impossible, non reconnue ou dans le futur ; événement avant la naissance ou plus d’un an après le décès (sépulture, funérailles, testament, succession et commémorations sont acceptés). |
| Sources et médias | citation d’une source qui n’existe plus ; source jamais citée ; part des personnes sans aucune source (seulement si la base cite des sources) ; lien vers un média supprimé ; **média rattaché plusieurs fois à la même personne** (liens en double au même endroit = avertissement ; même média rattaché à des événements différents = information). |
| Export GEDCOM | barre oblique « / » dans un nom (elle délimite le nom de famille en GEDCOM), caractère de contrôle ou invisible, nom de plus de 120 caractères (limite GEDCOM). |
| Lieux | lieu écrit de plusieurs façons (majuscules, accents, espaces…). |
| Doublons | personnes qui semblent n’en faire qu’une ; enfants d’une même famille qui semblent être la même personne. |

**Conventions généalogiques respectées** : un nom ou un prénom réduit à « ? » (« ??? », « … », « - ») ou placé entre crochets (« [Marie] », « [de la Tour] ») désigne un nom inconnu ou supposé : il n’est jamais signalé. Le point d’interrogation (lecture incertaine, par exemple « Dup?nt ») et le chiffre (erreur de saisie probable) sont deux contrôles distincts, de gravité « Information » ; un « ? » placé entre crochets (« Anne [Marie?] ») n’est pas signalé. Ces personnes ne sont pas comparées pour les doublons faute de nom exploitable.

## Choisir les parties à analyser

Avant de lancer l’analyse, **Parties à analyser** liste huit parties : personnes, familles, boucles d’ascendance, enfants de plusieurs familles et couples en double, doublons possibles, événements, sources et médias, lieux. Le choix est mémorisé. Seules les parties cochées sont relancées : **les résultats des autres parties sont conservés**, avec leur date d’analyse (« Parties analysées »). Les doublons possibles, la partie la plus longue sur une grande base, ont besoin de la partie « Personnes » (elle est cochée d’office). Si une table ou une colonne attendue manque dans votre version de TNG, la partie concernée est passée et signalée en jaune au lieu d’interrompre l’analyse.

## Doublons et score

Deux personnes sont rapprochées d’après leur **nom** (identique, ou proche : prononciation et orthographe voisines), leur **prénom**, leur **date ou année de naissance**, leur **année de décès**, leur **lieu de naissance** et leurs **parents** (mêmes parents, même père ou même mère). Chaque critère ajoute ou retire des points ; le résultat est un **score de 0 à 100**, affiché dans la colonne Score (triable). Le détail des critères retenus figure dans le message (« noms identiques, même année de naissance, mêmes parents »). Deux personnes de sexe connu et différent ne sont jamais rapprochées. Seules les paires dont le score atteint le **score minimal** (70 par défaut) sont signalées. Les groupes de personnes aux noms extrêmement répandus ne sont pas comparés (le nombre de groupes écartés est indiqué).

Un score élevé n’est qu’une **probabilité** : vérifiez toujours les deux fiches avant de fusionner dans TNG.

## Réglages

Section **Cohérence : seuils et anomalies ignorées**. Les seuils s’appliquent à la prochaine analyse.

| Réglage | Défaut | Effet |
|---|---|---|
| Âge minimal / maximal d’une mère à la naissance d’un enfant | 12 / 50 ans | Au-delà : avertissement (erreur si extrême). |
| Âge minimal / maximal d’un père à la naissance d’un enfant | 13 / 75 ans | Au-delà : avertissement. |
| Âge minimal au mariage | 15 ans | En dessous : avertissement. |
| Âge maximal au décès | 105 ans | Au-delà : avertissement. |
| Personne marquée « vivante » née il y a plus de | 110 ans | Au-delà : avertissement. |
| Enfant né plus de (mois) après le décès de son père | 9 mois | Au-delà : erreur. |
| Tolérance sur les dates approximatives | 2 ans | Marge appliquée aux dates « vers », « avant », « après »… |
| Score minimal pour signaler un doublon possible | 70 | De 30 à 100. |
| Profondeur de la recherche des boucles d’ascendance | 40 générations | La recherche peut allonger l’analyse d’un grand arbre ; elle se coche ou se décoche dans « Parties à analyser ». |
| Personne sans décès connu, non marquée « vivante » ni « privée », née il y a moins de (ans) | 100 | Contrôle de vie privée avant export (bornes 50 à 130). |
| Patronyme de l’enfant et de son père : similarité minimale pour les juger identiques, en % | 75 | 0 désactive le contrôle. Deux noms sont aussi considérés comme identiques s’ils partagent un mot (noms doubles) ou contiennent « dit » ou « alias ». |
| Nombre maximal d’anomalies conservées par contrôle | 5 000 | Les contrôles qui atteignent ce plafond sont signalés. |

## Remarques

- **Lecture seule** : le module n’exécute que des requêtes de lecture sur la base TNG. Les résultats sont enregistrés dans deux tables de la base WordPress (`tngs_coh_res`, `tngs_coh_key`) ; la liste des anomalies ignorées est conservée dans les options du site.
- **Confidentialité** : les résultats contiennent des noms et des dates de personnes, **y compris vivantes**. Ils ne sont visibles que des administrateurs (droit `manage_options`), jamais affichés sur le site ; toutes les actions sont protégées par des jetons de sécurité. « Effacer les résultats » les supprime ; la désinstallation du plugin supprime les tables et les options.
- Les résultats sont conservés jusqu’à la prochaine analyse ou jusqu’à « Effacer les résultats » ; le bouton « Vider tous les caches » de **Général > Maintenance** ne les efface pas.
- Les noms, dates et lieux affichés viennent de votre base TNG : ils ne sont jamais traduits.
- Le module se désactive dans **Général > Modules** (rien n’est alors chargé ; résultats et réglages sont conservés).

---
[← Guide des modules](README.md)
