# Liste flash des patronymes

Une **liste flash** est le tableau des patronymes que vous étudiez, avec pour chacun le **nombre de personnes**, la **date minimale** et la **date maximale** et les **lieux** avec leur intervalle de dates. Elle sert à échanger avec d’autres généalogistes pour repérer vite d’éventuelles branches communes. Le module la construit automatiquement à partir de votre base TNG (lecture seule).

## Dans l’administration

Menu **TNG Suite > Documents**, section **Liste flash**.

| Réglage | Rôle |
|---|---|
| Niveau des lieux | Commune, département, région ou pays. Les lieux TNG sont du texte libre : on suppose « Commune, Département, Région, Pays » (les codes postaux sont ignorés). Défaut : département. |
| Patronymes minimum | Les patronymes portés par moins de personnes sont masqués (défaut : 2 ; 1 = tout afficher). |
| Variantes | Regroupe les variantes d’orthographe (Lecoq, Lecocq, Lecoc…) ; elles restent affichées entre parenthèses. |
| Lieux par patronyme | Nombre de lieux conservés pour chaque patronyme (les plus fréquents). |
| Export public | Propose aux visiteurs les exports CSV, TXT et « Copier pour un forum ». |

La page montre aussi un **aperçu** de la liste, les liens d’export et le bouton **Recalculer**. Le résultat est mis en cache (durée : réglage commun « cache »).

## Utilisation

Collez le shortcode `[tng_flash]` dans une page ou un article.

| Attribut | Valeurs | Défaut | Rôle |
|---|---|---|---|
| level | commune, departement, region, pays | réglage | Niveau des lieux. |
| min | 1 à 50 | réglage | Nombre minimum de personnes par patronyme. |
| variants | 1 / 0 | réglage | Regrouper les variantes d’orthographe. |
| tree | identifiant | arbre des réglages communs | Arbre TNG. |
| search | 1 / 0 | 1 | Recherche et filtres. |
| export | 1 / 0 | réglage | Boutons d’export. |
| limit | 10 à 500 | 50 | Lignes affichées par page (bouton « Afficher plus »). |
| title | texte | vide | Titre au-dessus de la liste. |

## Ce que voit le visiteur

- Un tableau : **patronyme** (avec ses variantes entre parenthèses), **personnes**, **dates** (min – max sur les naissances, mariages et décès) et **lieux** avec leur intervalle et le nombre d’événements ; une info-bulle détaille naissances, mariages et décès.
- Une **recherche** par patronyme, un **filtre** par lieu et par **période** (sans lieu : le patronyme est gardé si ses dates recoupent la période ; avec un lieu : il faut un lieu correspondant dont les dates recoupent la période ; les autres lieux sont estompés ; le filtre cherche dans tous les lieux du patronyme, pas seulement ceux affichés) ; le **tri** par patronyme, nombre de personnes ou dates (clic sur l’en-tête).
- **Exporter** : CSV (séparateur point-virgule, UTF-8 avec BOM, s’ouvre dans Excel ou LibreOffice), TXT (une ligne par patronyme : « NOM ; date min ; date max ; lieux ») et **Copier pour un forum** (copie les lignes affichées après filtrage).

## Confidentialité

Les personnes vivantes ou privées sont exclues ou incluses selon **TNG Suite > Général > Modules** (réglage « Vivants / privés » du module Liste flash).
