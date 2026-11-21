# Ventes

## Vue d’ensemble

L’app **Ventes** montre comment deux apps travaillent ensemble : les ventes gèrent les clients, les demandes et les
commandes, mais les **articles** proviennent de l’app **Entrepôt**. L’entrepôt partage ses articles via une
**interface** (numéro d’article, désignation, unité, prix de vente) ; les ventes peuvent les lire et les choisir, mais
pas les modifier. Tous les montants sont en euros.

| Table | Contenu |
|---|---|
| Clients | Numéro de client, nom, pays, localité, e-mail, téléphone, client depuis |
| Événements | Salons, salons maison, webinaires et journées clients avec début, fin, lieu, en ligne oui/non |
| Demandes | Date, client, objet, statut, événement (origine de la demande), commande, total |
| Lignes de demande | Demande, ligne, article de l’entrepôt, quantité, prix, montant |
| Commandes | Numéro de commande, client, date de commande, statut, montant |

## Tâches courantes

### Saisir une demande

1. Sous **Clients et événements › Clients**, sélectionnez le client – ses demandes s’affichent en dessous.
2. Créez-y la demande avec **« Nouvel enregistrement »** : date, objet et statut *Ouverte*. Si la demande provient d’un
   salon ou d’un webinaire, choisissez l’**événement**.
3. Sélectionnez la demande et saisissez en dessous les **lignes de demande** : l’article et la quantité suffisent. Les
   articles viennent de l’entrepôt ; le **prix** est repris du prix de vente de l’article et peut être modifié pour un
   rabais.

SAFT calcule lui-même, à l’enregistrement, le **montant** de chaque ligne (quantité × prix) et le **total** de la demande.

### De l’offre à la commande

Passez le statut à *En cours* puis, une fois l’offre envoyée, à *Offre envoyée*. Si le client accepte, créez la commande
sous **Vente › Commandes**, indiquez la **commande** dans la demande et passez le statut à *Gagnée*. Les demandes
refusées reçoivent le statut *Perdue* ; elles restent disponibles pour les rapports.

### Planifier des événements

Saisissez salons et webinaires sous **Clients et événements › Événements**. La fin ne peut pas précéder le début. Sous
un événement, vous voyez toutes les demandes qui en sont issues.

## Rapports

- **Demandes par statut** – nombre et volume des demandes ouvertes, avec offre, gagnées et perdues.
- **Articles demandés** – les articles de l’entrepôt les plus demandés, avec quantité et montant.
- **Gagnées par client** – le volume des demandes gagnées par client.

## Conseils

- Un clic sur une ligne affiche les données liées en dessous : demandes et commandes d’un client, lignes d’une demande,
  demandes d’un événement.
- Les articles se modifient dans l’app **Entrepôt**. Les articles nouveaux ou modifiés sont aussitôt disponibles dans
  les ventes.
- Le **modèle de données** montre les liens entre les tables – y compris le lien vers les articles de l’entrepôt.
- L’**assistant d’aide** (bouton **?** en haut à droite) répond aux questions d’utilisation – y compris sur ce manuel.
