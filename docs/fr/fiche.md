# Fiche & listes de documents

Listes d’ascendants et de descendants et fiche individuelle, en PDF, Excel ou Word. Langues des documents : français, anglais, catalan, espagnol. Deux sections : **Générer des documents** (administration) et **Réglages**.

## Dans l’administration

Menu **TNG Suite > Documents**, sections **Fiche : générer des documents** et **Fiche : réglages**.

![Fiche & listes de documents](../images/individual%20sheet.png)

## Réglages du module

| Réglage | Effet |
|---|---|
| Personne de référence (Sosa 1) | Identifiant TNG de la personne de départ pour numéroter les fiches. |
| Réserver aux utilisateurs connectés | Les visiteurs non connectés ne peuvent pas générer de documents. |
| Les visiteurs peuvent cocher / décocher les champs | Affiche la liste des champs sur la page publique. |
| Langue des documents | Automatique (langue de Général), français, anglais, catalan ou espagnol. |
| Les visiteurs peuvent choisir la langue | Sélecteur de langue sur la page publique. |
| Champs affichés par défaut | Colonnes des listes d’ascendants / descendants et rubriques de la fiche (tout est coché par défaut). |

## Shortcodes

```
[tng_listes]
```

Outil public : le visiteur choisit le type de document, la personne, le nombre de générations et le format.

```
[tng_fiche]
```

Ancien nom du même outil : affiche la même chose que `[tng_listes]`.

## Utilisation

1. Section « Générer des documents » : choisissez le type, la personne et les générations, déployez « Champs affichés », puis **Créer le document**.
2. Section Réglages : champs par défaut, langues, accès réservé aux connectés.
3. Pour les visiteurs, collez `[tng_listes]` dans une page.

## Résultat sur le site

![Fiche & listes de documents](../images/Exemple%20individual%20sheet.png)

## Remarques

- Les personnes vivantes ou privées suivent le réglage de **Général > Modules** (masquées par défaut, recommandé pour le RGPD).
- Dans l’administration, la langue suit **Général** (français ou anglais), sauf catalan / espagnol choisis dans les réglages.
- La mémorisation du choix de langue du visiteur utilise `localStorage` (aucun cookie).

---
[← Guide des modules](README.md)
