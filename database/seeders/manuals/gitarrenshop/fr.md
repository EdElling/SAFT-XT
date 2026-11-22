# Magasin de guitares

## Vue d’ensemble

L’app **Magasin de guitares** est une comptabilité simple des recettes et dépenses avec TVA pour un petit commerce de
pièces uniques : instruments, accessoires, services et divers. Tous les montants sont en francs suisses (CHF).

| Table | Contenu |
|---|---|
| Clients | Numéro de client, prénom, nom ou entreprise, adresse, pays, e-mail, newsletter oui/non, téléphone |
| Fournisseurs | Nom, pays, localité, e-mail, téléphone, site web, notre numéro de client chez le fournisseur |
| Articles | Numéro d’inventaire, désignation, catégorie, statut, description, jusqu’à 10 images ; calculés : coûts, produit, résultat |
| Justificatifs | Justificatifs d’achat et factures : numéro, date, type (recette/dépense), client ou fournisseur, fichiers (PDF ou image) ; calculés : total, payé, ouvert |
| Écritures | Lignes d’un justificatif : article, genre, texte, net, taux de TVA ; calculés : TVA, brut, recette, dépense, solde |
| Paiements | Paiements d’un justificatif avec date, montant, mode de paiement et ticket de caisse |

## Tâches courantes

### Acheter de la marchandise à l’étranger

1. Sous **Comptabilité › Justificatifs**, créer un justificatif de type *Dépense*, choisir le **fournisseur** et joindre
   sa facture en PDF. Les nouveaux fournisseurs se saisissent sous **Données de base › Fournisseurs**.
2. En dessous, saisir une **écriture** par article avec le genre *Valeur marchandise* (taux de TVA 0 %, achat à
   l’étranger).
3. À l’arrivée de la marchandise, créer un second justificatif pour la douane : par article une écriture *Douane* et
   une écriture *TVA à l’importation* (montant selon la quittance douanière, taux 0 %).
4. Saisir le paiement sous **Paiements** – sous le justificatif ou dans la table elle-même.

### Vendre

Créer un justificatif de type *Recette* avec le **client** (nouveaux clients sous **Données de base › Clients**) et
une écriture du genre **Vente**, le prix net et le taux de TVA (en général
8,1 %). SAFT calcule TVA et brut. Passer le statut de l’article à *Vendu*.

Créez la **facture** du client dans la ligne du justificatif avec **« Créer un document »** (modèle « Rechnung ») : SAFT
la télécharge en PDF et la dépose, protégée en écriture, dans les fichiers du justificatif. Avec **« Envoyer par
e-mail »** (droit *Documents : envoyer*), elle part directement à l’adresse e-mail du client.

### Paiements partiels et échelonnés

Saisir autant de paiements que nécessaire pour un justificatif, chacun avec son ticket. Sur le justificatif, **payé**
et **ouvert** montrent ce qui reste dû.

### Copier des articles

Pour saisir un instrument semblable, utilisez **Copier** dans la ligne de l’article : toutes les données et images sont
reprises, le numéro d’inventaire reste vide. Copier un justificatif copie aussi ses écritures, pas les paiements.

## Important : recette ou dépense

Qu’une écriture soit une recette ou une dépense dépend de son **genre** : *Vente* est une recette, tous les autres
genres (valeur marchandise, douane, TVA à l’importation, service, divers) sont des dépenses. Le type du justificatif
sert à la vue d’ensemble.

## Rapports

- **Recettes et dépenses par année** – avec le solde (bénéfice ou perte) par année de la date du justificatif.
- **Solde total** – un indicateur sur toutes les écritures ; positif = bénéfice, négatif = perte.
- **Toutes les écritures** – les plus récentes d’abord, avec justificatif, article, genre et montants.
- **Recettes et dépenses par article** – coûts, produit et résultat par article ; les écritures sans article (p. ex.
  fret) apparaissent dans une ligne à part, sans article.

## Conseils

- Sur un article, **Coûts**, **Produit** et **Résultat** montrent aussitôt ce qu’une pièce a coûté et rapporté ; ses
  écritures figurent en dessous.
- Sous **Clients**, la case **Newsletter** indique qui souhaite recevoir des e-mails ; filtrez dessus et utilisez
  **Exporter** pour obtenir la liste d’adresses.
- Un client ou fournisseur affiche ses justificatifs en dessous.
- Le rôle **Gitarrenshop** a tous les droits sur cette app – pour la personne qui gère le magasin.
- L’**assistant d’aide** (bouton **?** en haut à droite) répond aux questions d’utilisation – y compris sur ce manuel.
