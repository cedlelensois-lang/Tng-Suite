# Général

Page de départ de TNG Suite : elle règle tout ce qui est commun aux modules (connexion à la base TNG, URL, préfixe, arbre par défaut, langue, confidentialité, activation des modules) et propose le diagnostic et la maintenance. La connexion n’est jamais à ressaisir dans un module.

## Dans l’administration

Menu **TNG Suite > Général**.

![Général](../images/general.png)

## Réglages du module

| Réglage | Effet |
|---|---|
| Mode de connexion : wp-config.php (recommandé) | Rien n’est stocké dans la base. Ajoutez dans `wp-config.php`, avant « That’s all, stop editing! » : `DB_HOSTTNG`, `DB_NAMETNG`, `DB_USERTNG`, `DB_PASSWORDTNG` (facultatif : `DB_CHARSETTNG`, `DB_COLLATETNG`). |
| Mode de connexion : saisir les identifiants ici | Hôte MySQL (`localhost` ou `hote:port`), nom de la base, utilisateur, mot de passe, jeu de caractères. Le mot de passe est **chiffré** (libsodium, clés de sécurité de WordPress) et n’est jamais réaffiché ; laissez le champ vide pour le conserver, ou cochez « Effacer ». Utilisez un compte MySQL en **lecture seule** (SELECT). |
| Mode de connexion : mes tables TNG sont dans la base de WordPress | Aucun identifiant : les tables TNG sont lues dans la même base que WordPress. |
| Diagnostic | Teste la connexion et la présence des tables ; refait à chaque affichage de la page (enregistrez vos réglages pour tester une nouvelle connexion). |
| Langue / Language | Automatique (langue de WordPress), français ou anglais : interface et textes publics des modules. Les noms, lieux et notes de votre base ne sont jamais traduits. |
| URL de votre site TNG | Adresse de TNG (ex. `https://exemple.fr/tng`), utilisée pour les liens vers les fiches et les photos. |
| Préfixe des tables TNG | Préfixe des tables (`tng_` par défaut ; lettres, chiffres et `_`, 32 caractères au plus). |
| Identifiant de l’arbre TNG | Colonne `gedcom` : arbre utilisé par défaut. Laisser vide pour ne pas filtrer (tous les arbres). |
| Vivants / privés (par défaut) | Masquer les personnes vivantes ou privées (recommandé, RGPD). Valeur utilisée par les modules réglés sur « Comme le réglage commun ». |
| Cache des arbres (heures) | 0 = pas de cache (12 par défaut). Les autres modules ont leur propre durée dans leur section. |
| Tableau Modules | Pour chaque module : activé / désactivé et réglage Vivants / privés (Comme le réglage commun, Masquer, Afficher ⚠️ RGPD). « Bouton vers la fiche » suit « Arbres & descendance » ; « Individu au hasard » suit « Nuage de noms & anniversaires » ; ces deux derniers sont masqués par défaut. « Cohérence des données » (page d’administration, sans shortcode) n’a pas de réglage Vivants / privés : rien n’est publié. |
| Maintenance : vider tous les caches | Vide les caches de tous les modules ; utile après une modification dans TNG. |
| Migration depuis les anciens plugins | Si d’anciens plugins autonomes sont détectés, leurs réglages sont repris et comparés ; désactivez-les pour activer les modules correspondants. Un bouton efface les mots de passe MySQL qu’ils conservent encore en clair. |

## Utilisation

1. Choisissez le mode de connexion et renseignez la base TNG ; vérifiez le **Diagnostic**.
2. Indiquez l’URL de TNG, le préfixe des tables et l’arbre par défaut.
3. Choisissez la langue et le réglage Vivants / privés (laissez « Masquer » sauf besoin précis).
4. Activez les modules voulus : chacun apparaît dans le menu de sa famille (Arbres & visualisations, Analyses, Documents, Médias & widgets).
5. Enregistrez.

## Remarques

- Le compte MySQL ne doit avoir que le droit de lecture (SELECT) sur les tables TNG.
- À la désinstallation (suppression du plugin), les réglages, caches, tâches planifiées et la table `tngi_inbreeding` sont supprimés ; vos données TNG et les anciens plugins ne sont jamais touchés.
- Les mises à jour automatiques viennent des Releases GitHub du plugin ; elles se désactivent avec `define( 'TNGSUITE_GITHUB_REPO', '' );` dans `wp-config.php`.

---
[← Guide des modules](README.md)
