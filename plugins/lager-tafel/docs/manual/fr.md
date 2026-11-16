# Tableau des stocks – guide utilisateur

## Vue d’ensemble

Le plugin *Tableau des stocks* ajoute à la démo entrepôt deux vues et quelques règles :

- **Matrice des stocks** – tous les articles avec leur stock par emplacement d’un coup d’œil.
- **Tableau des stocks** – transférer le stock d’un article vers un autre emplacement en le faisant glisser.
- Une tuile **Entrepôt : stock par emplacement** sur le tableau de bord.
- Des règles pour le **numéro d’article** et deux types de **tâches planifiées** (rappel d’inventaire, signaler le stock
  minimum).

Les écritures passent par le plugin *Transfert de stock* ; son guide décrit les stocks, les mouvements de stock et
l’inventaire. S’il est désactivé, la matrice et le tableau des stocks affichent un avertissement.

## Matrice des stocks

**Entrepôt (démo) › Matrice des stocks** affiche une ligne par article et une colonne par emplacement avec le stock ; les
emplacements sans stock affichent 0 en gris. **Total** additionne tous les emplacements et apparaît en rouge lorsque
l’article est sous son **stock minimum**.

- **Rechercher** par numéro d’article ou désignation avec le champ de recherche.
- **Trier** par numéro, article ou stock minimum avec les en-têtes de colonne (les colonnes des emplacements et *Total*
  ne se trient pas).
- **Filtre « Seulement sous le stock minimum »** n’affiche que les articles dont le stock total est sous le stock
  minimum.
- Le bouton **Répartir sur le tableau des stocks** d’une ligne ouvre le tableau des stocks pour cet article.

## Tableau des stocks

**Entrepôt (démo) › Tableau des stocks** affiche, pour un article, une carte par emplacement avec le stock.

1. Choisir l’**Article** (ou venir de la matrice des stocks ou des articles avec **Répartir sur le tableau des stocks**).
2. **Faire glisser** le stock d’une carte **sur une autre carte** avec la souris.
3. Dans la boîte de dialogue, vérifier la **Quantité** – tout le stock de l’emplacement source est prérempli – puis
   **Transférer**. Plus que le disponible n’est pas possible.

L’écriture se fait comme dans le masque *Transfert* : deux mouvements de type *Transfert*, tout ou rien. Annuler la boîte
de dialogue ne change rien. Le glisser-déposer ne fonctionne qu’à la souris ; au clavier, transférez via **Entrepôt
(démo) › Transfert**.

## Articles : boutons et règles

Dans **Entrepôt (démo) › Articles**, deux boutons s’ajoutent : **Matrice des stocks** au-dessus de la table et
**Répartir sur le tableau des stocks** dans chaque ligne.

Pour le **numéro d’article**, les règles suivantes s’appliquent en plus :

| Règle | Effet |
|---|---|
| **Écriture uniforme** | les espaces sont supprimés, les lettres passent en majuscules : « a-1001 » devient « A-1001 » |
| **Bloqué après des mouvements** | dès qu’un article a des mouvements de stock, son numéro ne peut plus être modifié (une autre écriture du même numéro est permise). Les autres champs restent modifiables |
| **Format A-1234** | une lettre, un tiret, quatre chiffres – seulement si l’administration a choisi « Numéro d’article (démo entrepôt) » pour le champ sous **Contrôles supplémentaires** |

Les règles s’appliquent à la saisie, à l’import et via l’interface. Unique, obligatoire et au plus 20 caractères sont
définis par le concepteur de tables.

## Tuile et tâches planifiées

**Tuile « Entrepôt : stock par emplacement » :** diagramme à barres avec la somme de tous les stocks par emplacement sur
le tableau de bord.

L’administration configure deux types de tâches planifiées sous **Administration › Tâches planifiées** :

| Tâche | Ce qu’elle fait |
|---|---|
| **Entrepôt : rappel d’inventaire** | indique dans le journal les stocks avec marchandise non comptés depuis le nombre de jours réglé (*Non compté depuis*, 90 par défaut) – occasion d’un inventaire |
| **Entrepôt : signaler le stock minimum** | si des articles sont sous le stock minimum, toutes les personnes ayant le droit *Afficher la matrice des stocks* reçoivent une notification dans la cloche, avec le bouton **Ouvrir la matrice des stocks** |

## Droits

| Pour | Droit |
|---|---|
| Matrice des stocks, tuile, bouton *Matrice des stocks*, notification *stock minimum* | *Tableau des stocks : Afficher la matrice des stocks* |
| Tableau des stocks, bouton *Répartir sur le tableau des stocks* | *Tableau des stocks : Transférer sur le tableau des stocks* |
| Lecture | droits sur les données *afficher* sur Articles, Emplacements et Stocks |
| Transférer sur le tableau | les mêmes droits sur les données que pour le masque *Transfert* (voir le guide *Transfert de stock*) |
