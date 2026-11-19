# Boutique

## Aperçu

L’app **Shop** (Boutique) montre, à l’exemple d’une petite boutique en ligne, comment les données sont liées dans SAFT :
les clients commandent des articles, chaque commande se compose de lignes de commande. Tous les montants sont en euros.

| Table | Contenu |
|---|---|
| Articles | numéro d’article, désignation, catégorie, prix, stock, actif oui/non |
| Clients | numéro de client, société, interlocuteur, e-mail, localité, pays, client depuis |
| Commandes | numéro de commande, client, date de commande, statut, date de livraison, total et TVA |
| Lignes de commande | commande, position, article, quantité, prix unitaire, montant |

## Tâches courantes

### Saisir un nouveau client

Ouvrez **Clients** et cliquez sur **« Nouvel enregistrement »**. Le numéro de client et la société sont obligatoires ;
le numéro de client ne peut apparaître qu’une fois. Le format de l’adresse e-mail est contrôlé à l’enregistrement.

### Prendre une commande

1. Sélectionnez le client sous **Clients** – ses commandes apparaissent en dessous.
2. Créez-y la commande avec **« Nouvel enregistrement »**. Le client est déjà renseigné ; choisissez d’abord le statut *Nouvelle*.
3. Sélectionnez la nouvelle commande et saisissez en dessous les **lignes de commande** : l’article et la quantité
   suffisent. Le **prix unitaire** est repris de l’article et peut être modifié au besoin (p. ex. un rabais).

SAFT calcule lui-même à l’enregistrement le **montant** de chaque ligne (quantité × prix unitaire) ainsi que le
**total** et la **TVA 8,1 %** de la commande.

### Expédier une commande

Mettez le **statut** sur *Expédiée* et saisissez la **date de livraison**. Celle-ci ne peut pas précéder la date de
commande – sinon SAFT refuse l’enregistrement avec une remarque au champ. Les commandes annulées reçoivent le statut
*Annulée* ; elles restent disponibles pour les rapports.

### Gérer les articles

Modifiez prix et stock directement dans l’article. Un changement de prix ne vaut que pour les nouvelles lignes de
commande ; les lignes existantes gardent leur prix unitaire. Mettez les articles qui ne sont plus vendus sur *inactif*
au lieu de les supprimer – un article figurant dans des lignes de commande ne peut de toute façon pas être supprimé.

## Rapports

Sous **Rapports**, **« Umsatz je Monat und Kategorie »** (chiffre d’affaires par mois et catégorie) montre les montants
des lignes de commande par mois et par catégorie d’article. Vous pouvez créer vos propres rapports, p. ex. chiffre
d’affaires par client ou par pays ; la table de base pour le chiffre d’affaires est **Lignes de commande**.

## Conseils

- Un clic sur une ligne la sélectionne et affiche les données liées en dessous : pour un client ses commandes, pour une
  commande ses lignes, pour un article les commandes où il figure.
- **Exporter** enregistre la liste actuelle en fichier Excel ou CSV, avec vos filtres et colonnes.
- L’**assistant d’aide** (bouton **?** en haut à droite) répond aux questions sur l’utilisation – y compris sur ce
  manuel.
