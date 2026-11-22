# École de musique

## Vue d'ensemble

L'app **École de musique** gère une petite école de musique : élèves et leurs personnes de contact, enseignants, cours
par semestre, leçons avec présences, instruments en location, factures semestrielles avec paiements, ainsi que concerts
et auditions. Tous les montants sont en francs suisses (CHF).

C'est un exemple d'application de taille moyenne : 21 tables, conçues avec le **concepteur d'apps IA** puis affinées à
la main. La démo est installée à partir d'une définition d'app – exactement comme via **Apps › Installer une app**.

| Menu | Tables |
|---|---|
| Enseignement | Cours, Inscriptions, Leçons, Présences, Événements, Prestations |
| Personnes | Élèves, Personnes de contact, Contacts des élèves, Enseignants, Branches des enseignants |
| Finances & instruments | Factures, Positions de facture, Paiements, Instruments de location, Contrats de location |
| Données de base | Branches, Types de cours (avec tarif semestriel), Semestres, Salles, Vacances |

Les données d'exemple montrent un semestre terminé (**HS 2026**) avec leçons, factures et paiements, et un semestre
commencé (**FS 2027**) avec les premiers cours et inscriptions.

## Tâches courantes

### Créer un cours et inscrire des élèves

1. Sous **Enseignement › Cours**, créer un cours avec branche, type de cours, enseignant, salle, semestre, jour et
   heure. Le **tarif** est repris du type de cours et peut être adapté.
2. Sous **Inscriptions**, saisir les participants – statut *actif*, *liste d'attente* ou *désinscrit*, avec un rabais
   en pour-cent si nécessaire (p. ex. pour les frères et sœurs).
3. Sur le cours, **inscriptions**, **places libres** et **taux d'occupation** montrent le remplissage ; seules les
   inscriptions actives comptent.

### Leçons et présences

Pour chaque leçon, saisir date, durée et thème, puis les **présences** (présent, excusé, non excusé). Le **tarif
horaire** vient de l'enseignant ; SAFT calcule les **heures** et les **honoraires**. Sur l'élève, le **taux de
présence** montre sa régularité.

**Données de base › Vacances** contient les vacances scolaires et jours fériés sans cours (un seul jour férié :
*Du* = *Au*). Avec le plugin **École de musique : planification**, vous créez d'un coup les leçons d'un semestre – sans
ces jours – et saisissez les présences d'une leçon sur une seule page.

### Facture semestrielle

1. Sous **Finances & instruments › Factures**, créer une facture à la personne de contact (pour les mineurs) ou à
   l'élève adulte, avec semestre et échéance.
2. En dessous, une **position de facture** de type *cours* par cours – SAFT reprend le **prix unitaire** du tarif du cours
   et déduit le rabais. Les instruments en location sont une position de type *location* avec le nombre de mois.
3. Saisir les **paiements** ; **payé** et **ouvert** montrent l'état. Une facture annulée n'a plus rien d'ouvert.

En copiant une facture (p. ex. pour le semestre suivant), ses positions sont reprises, pas les paiements.

Créez la facture en PDF sur le papier à lettres dans la ligne de la facture avec **« Créer un document »** (modèle
« Semesterrechnung ») – adressée à la personne de contact, sinon à l’élève.
**« Déposer dans le champ ‹ Documents › »** est présélectionné : le PDF est ajouté, protégé en écriture, au champ
**Documents** de la facture (chaque création comme nouvelle version). Déposer requiert le droit de modifier les
factures ; le rôle *Musikschule* l’a. **« Envoyer par e-mail »** envoie la facture à l’adresse e-mail de la personne
de contact, sinon de l’élève – il faut pour cela en plus le droit *Documents : envoyer* (volontairement pas prédéfini).
En bas de la facture semestrielle figure la **QR-facture** (sinon sur une page propre) avec le montant ouvert et le
numéro de facture comme référence, payable par la personne de contact ou l’élève. Le bénéficiaire est l’expéditeur du papier à
lettres (démo : IBAN d’exemple de SIX si aucun IBAN n’est encore saisi).

### Louer des instruments

Saisir chaque instrument sous **Instruments de location** avec numéro d'inventaire, état et prix de location, et la
location sous **Contrats de location** avec début, fin, caution et contrat (PDF).

### Concerts et auditions

Créer un événement sous **Événements** et, en dessous, les **prestations** avec morceau, compositeur, instrument et
ordre de passage – cela donne le programme.

## Rapports

- **Occupation par cours** – inscriptions, places libres et taux d'occupation.
- **Recettes par branche et semestre** – montants facturés des positions de cours.
- **Factures ouvertes par personne de contact** – qui doit encore combien.
- **Taux de présence par élève** – présences, dont présent, taux.
- **Heures d'enseignement et honoraires par enseignant** – base pour les salaires.

## Conseils

- Sur la personne de contact, **montant ouvert** indique le total de ses factures ouvertes.
- Les frères et sœurs partagent leurs personnes de contact ; la case **destinataire de la facture** dans les contacts
  des élèves indique qui reçoit la facture.
- Le rôle **Musikschule** a tous les droits sur cette app – pour la direction ou le secrétariat.
- L'**assistant d'aide** (bouton **?** en haut à droite) répond aux questions d'utilisation – aussi sur ce manuel.
