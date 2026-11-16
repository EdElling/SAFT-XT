# Transfert de stock – guide utilisateur

## Vue d’ensemble

Dans la démo entrepôt, le plugin *Transfert de stock* tient à jour les **stocks** de chaque emplacement à partir des
**mouvements de stock**. Vous saisissez uniquement des mouvements – entrée, sortie, transfert, inventaire –, SAFT calcule
lui-même le stock. S’y ajoutent le masque **Transfert**, le bouton **Transférer** dans les stocks, une notification
lorsqu’un article passe sous son stock minimum et la tuile **Entrepôt : stock minimum** sur le tableau de bord.

Les tables se trouvent dans le menu sous **Entrepôt (démo)** : *Articles*, *Emplacements*, *Stocks* et *Mouvements de
stock*.

## Saisir des mouvements de stock

Sous **Entrepôt (démo) › Mouvements de stock**, **Nouveau** crée un mouvement : *Article*, *Emplacement*, *Type*,
*Quantité*, *Date et heure* (préremplie avec maintenant) et, si nécessaire, une *Remarque*.

| Type | Effet sur le stock de l’emplacement |
|---|---|
| **Entrée** | augmente le stock de la quantité |
| **Sortie** | diminue le stock de la quantité |
| **Transfert** | créé par le masque *Transfert* (voir ci-dessous) : une sortie à l’emplacement source et une entrée à l’emplacement cible |
| **Inventaire** | corrige le stock de la différence saisie et enregistre la date du *Dernier comptage* sur le stock |

Pour **Entrée** et **Sortie**, saisissez la quantité sans signe ; SAFT le fixe lui-même (sortie toujours négative). S’il
n’existe pas encore de stock pour l’article à cet emplacement, la première entrée le crée.

**Modifier ou supprimer des mouvements :** si vous modifiez un mouvement (p. ex. la quantité ou l’emplacement), SAFT
annule l’ancienne écriture et comptabilise la nouvelle. Un mouvement supprimé est sorti du stock ; si vous le restaurez
depuis la corbeille, il est de nouveau comptabilisé.

## Consulter et corriger les stocks

**Entrepôt (démo) › Stocks** affiche par article et emplacement la *Quantité* et la date du *Dernier comptage*.

Vous **ne modifiez pas les stocks directement** : SAFT refuse la création, la modification et la suppression dans cette
table – y compris à l’import – en indiquant que les stocks sont tenus par les mouvements de stock. Ainsi, chaque stock
reste traçable.

**Corriger un stock (inventaire) :** saisissez un mouvement de type **Inventaire** avec la **différence** entre la
quantité comptée et la quantité comptabilisée – négative s’il y en a moins. Exemple : 10 comptabilisés, 8 comptés →
inventaire avec la quantité −2. Le stock est alors de 8, et le *Dernier comptage* porte la date du mouvement.

**Pas de stock négatif :** si un mouvement faisait passer le stock d’un emplacement sous 0, il n’est pas enregistré. Le
message indique l’article, l’emplacement et le stock disponible.

## Transférer des marchandises

Transférer signifie déplacer des marchandises d’un emplacement à un autre sans modifier le stock total.

1. Ouvrir **Entrepôt (démo) › Transfert** – ou, dans **Stocks**, cliquer sur **Transférer** dans la ligne de
   l’emplacement source ; l’article et l’emplacement source sont alors déjà choisis. Sans stock à l’emplacement, le bouton
   est grisé.
2. Choisir l’**Article**. **Depuis l’emplacement** ne propose que les emplacements avec du stock de cet article, avec la
   quantité.
3. Indiquer **Vers l’emplacement** et la **Quantité** (au plus le stock à la source), et si nécessaire une **Remarque**.
4. **Transférer**. SAFT saisit deux mouvements de type *Transfert* – moins à la source, plus à la cible – en une seule
   étape : soit les deux réussissent, soit aucun.

## Stock minimum

Chaque article a un *Stock minimum*. Si un mouvement fait passer le **stock total** d’un article (tous emplacements
confondus) sous cette valeur, la personne qui a saisi le mouvement reçoit la notification **Stock minimum non atteint**
dans la cloche. Les transferts ne déclenchent pas de notification, car le stock total ne change pas ; les augmentations
non plus.

La tuile **Entrepôt : stock minimum** du tableau de bord indique combien d’articles sont sous le stock minimum, avec les
premiers noms, ainsi que le stock total ; un clic mène au transfert.

## Droits

| Pour | Droit |
|---|---|
| Masque *Transfert*, bouton *Transférer*, tuile | *Transfert de stock : Effectuer des transferts* |
| Transférer | en plus des droits sur les données : *afficher* sur Articles et Emplacements, *afficher*, *créer* et *modifier* sur Stocks, *créer* sur Mouvements de stock |
| Saisir des mouvements | *créer* (ou *modifier*, *supprimer*) sur Mouvements de stock |

Qui ne fait que saisir des mouvements de stock n’a besoin d’aucun droit sur les stocks – SAFT les tient lui-même. S’il
manque des droits pour transférer, le masque *Transfert* nomme les tables concernées.
