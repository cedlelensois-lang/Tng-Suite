# Livre généalogique

Génère un livre complet (ascendance, descendance, index, cartes, statistiques) en page imprimable/PDF, Word (.docx) ou OpenDocument (.odt), en français, anglais, espagnol ou catalan.

## Dans l’administration

![Livre généalogique](../images/book.png)

## Shortcodes

```
[tng_book]
```

## Utilisation

1. Saisissez l’identifiant de la personne, l’arbre et le nombre de générations.
2. Cochez le contenu voulu, ordonnez les parties, choisissez couleurs et modèle de mise en page.
3. Choisissez le format puis générez. Un profil peut être enregistré et rechargé.

## Résultat sur le site

![Livre généalogique](../images/exemple%20book.png)

## Remarques

- Page sur le patronyme : un éditeur (mise en forme, tableaux, images) permet de rédiger une page de présentation du nom ; elle est enregistrée dans le profil du livre et se place dans « Ordre des parties ». Vide = pas de page.
- Chronologie familiale : option à cocher (désactivée par défaut) ; ajoute une partie qui liste, par ordre chronologique et regroupés par décennie, les naissances, mariages et décès des personnes du livre (avec renvoi vers leur fiche, lieu et âge au décès). Les personnes masquées (vivantes) en sont exclues. Elle se place dans « Ordre des parties » comme toutes les autres.
- Chaque partie commence sur une nouvelle page.
- Le shortcode [tng_book] ouvre le générateur aux visiteurs connectés si l’option est activée.
- Les personnes vivantes sont incluses en entier si vous choisissez de les afficher : ne diffusez pas ce livre sans accord.

## Génération automatique (bêta)

Sous les profils, la section **Génération automatique** permet de produire le livre d’un profil sans rien faire, chaque jour, chaque semaine ou chaque mois.

1. Enregistrez un profil de livre (format PDF, Word, OpenDocument ou HTML : celui du profil est utilisé).
2. Dans la section, cochez **Actif** sur la ligne du profil, puis choisissez la fréquence, le jour et l’heure.
3. Indiquez le **dossier de destination** (chemin absolu sur le serveur). Laissé vide, le plugin utilise un dossier protégé, au nom aléatoire, dans les téléversements de WordPress. Pour synchroniser avec OneDrive, Dropbox, etc., choisissez un dossier que votre outil de synchronisation surveille.
4. Réglez le nombre de fichiers conservés par profil (les plus anciens sont supprimés ; seuls les fichiers créés par le plugin sont concernés), l’option **Seulement si les données ont changé** et l’e-mail de notification.
5. **Enregistrer**, puis **Générer maintenant** pour tester.

Remarques :

- WordPress ne lance ses tâches que lorsque le site est visité : l’heure est approximative. Pour une heure précise, programmez une tâche cron du serveur qui appelle `wp-cron.php` (par exemple toutes les heures).
- Le PDF direct utilise mPDF, déjà inclus dans le zip des Releases GitHub. Si le plugin est installé depuis le code source, lancez `composer install --no-dev` dans `modules/book` ; sans mPDF, la génération d’un profil au format PDF échoue (message dans la colonne « Dernière génération »).
- Les photos ou notes modifiées sans changement des fiches ne sont pas détectées par l’option « seulement si les données ont changé » : utilisez **Générer maintenant**.

---
[← Guide des modules](README.md)
