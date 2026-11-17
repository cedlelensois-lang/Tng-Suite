# Bouton vers la fiche

Un bouton (emoji + texte) qui renvoie vers la fiche d’une personne dans TNG. Activé avec le module Arbres & descendance.

## Dans l’administration

Menu **TNG Suite > Arbres & visualisations**, section **Bouton vers la fiche**.

![Bouton vers la fiche](../images/record%20link.png)

## Réglages du module

| Réglage | Effet |
|---|---|
| Emoji | Symbole affiché avant le texte. |
| Texte du bouton | Libellé par défaut (modifiable par bouton avec `text`). |
| Couleur de fond / du texte | Apparence normale du bouton. |
| Couleur au survol / texte au survol | Apparence quand la souris passe dessus. |
| Aperçu | Affiche le bouton en direct pendant le réglage. |

## Shortcodes

```
[tng_link id="I12"]
```

Bouton vers la fiche de la personne I12.

```
[tng_link id="I12" tree="monarbre" text="Voir la fiche"]
```

Avec un arbre précis et un texte personnalisé.

### Attributs

| Attribut | Valeurs | Défaut | Rôle |
|---|---|---|---|
| id | I12 | (vide : rien) | Identifiant TNG de la personne. |
| tree | identifiant d’arbre | arbre de Général | Arbre (gedcom) de la personne. |
| text | texte | texte des réglages | Libellé de ce bouton. |

## Utilisation

1. Réglez le style du bouton (emoji, texte, couleurs) dans la section du module.
2. Collez le shortcode avec l’identifiant de la personne.

## Résultat sur le site

![Bouton vers la fiche](../images/Exemple%20record%20link.png)

## Remarques

- L’URL de TNG se règle dans **Général**.

---
[← Guide des modules](README.md)
