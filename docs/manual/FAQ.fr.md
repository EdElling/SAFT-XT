# SAFT XT – Questions fréquentes

[Deutsch](FAQ.de.md) · [English](FAQ.md) · **Français** · [Italiano](FAQ.it.md)

**État :** version 0.1 · **Public cible :** tout le monde

**Autres guides :** [Guide utilisateur](USER_GUIDE.fr.md) · [Guide d’administration](ADMIN_GUIDE.fr.md) · [Administration système](SYSADMIN_GUIDE.md)

---

## Sommaire

- [Questions fréquentes](#questions-fréquentes)
   - [Je ne vois pas une table dans le menu.](#je-ne-vois-pas-une-table-dans-le-menu)
   - [Aucune ligne n’apparaît sous une commande.](#aucune-ligne-napparaît-sous-une-commande)
   - [Je ne peux pas supprimer un enregistrement.](#je-ne-peux-pas-supprimer-un-enregistrement)
   - [Je ne peux pas changer le type d’une colonne.](#je-ne-peux-pas-changer-le-type-dune-colonne)
   - [J’ai utilisé « Mot de passe oublié », mais je n’ai reçu aucun e-mail.](#jai-utilisé--mot-de-passe-oublié--mais-je-nai-reçu-aucun-e-mail)
   - [J’ai perdu mon téléphone avec l’application d’authentification.](#jai-perdu-mon-téléphone-avec-lapplication-dauthentification)
   - [Dans quelle langue l’application s’affiche-t-elle ?](#dans-quelle-langue-lapplication-saffiche-t-elle-)
   - [J’ai choisi le français, mais une entrée de menu ou une table reste en allemand.](#jai-choisi-le-français-mais-une-entrée-de-menu-ou-une-table-reste-en-allemand)
   - [Je ne vois pas de bouton « Exporter ».](#je-ne-vois-pas-de-bouton--exporter-)
   - [Pour une table, « Nouvel enregistrement » et « Modifier » manquent.](#pour-une-table--nouvel-enregistrement--et--modifier--manquent)
   - [Comment transmettre une vue précise à quelqu’un ?](#comment-transmettre-une-vue-précise-à-quelquun-)
   - [Un fichier ne se téléverse pas.](#un-fichier-ne-se-téléverse-pas)
   - [Une table manque dans le choix d’une liaison.](#une-table-manque-dans-le-choix-dune-liaison)

---
## Questions fréquentes

### Je ne vois pas une table dans le menu.

Vous n’avez pas le droit de lecture, il n’existe pas d’entrée de menu, ou votre rôle masque l’entrée. Adressez-vous à
l’administration (Rôles → Droits sur les données ou entrées de menu, ou Menus). Si la table appartient à une autre **app**, passez d’abord à
cette app avec le sélecteur en haut.

### Aucune ligne n’apparaît sous une commande.

Soit aucun détail n’est configuré pour la table (Concepteur de tables → Détails), soit il vous manque le droit de
lecture sur les lignes.

### Je ne peux pas supprimer un enregistrement.

D’autres enregistrements y font encore référence (voir le message). Supprimez-les d’abord.

### Je ne peux pas changer le type d’une colonne.

Le changement modifierait des données existantes. Il n’est possible que si la colonne est vide ou si le changement de
type se fait sans perte (voir [Guide d’administration, chapitre 4](ADMIN_GUIDE.fr.md#4-concepteur-de-tables)).

### J’ai utilisé « Mot de passe oublié », mais je n’ai reçu aucun e-mail.

La confirmation s’affiche toujours, même s’il n’existe aucun compte pour cette adresse. Vérifiez l’orthographe de
l’adresse et votre dossier de courrier indésirable, et patientez un moment avant un nouvel essai, car les demandes
répétées sont bloquées. Si cela ne suffit pas, adressez-vous à l’administration.

### J’ai perdu mon téléphone avec l’application d’authentification.

Connectez-vous avec l’un de vos **codes de récupération** : après le mot de passe, cliquez sur **« Utiliser un code de
récupération à la place »**. Chaque code ne vaut qu’une fois. Configurez ensuite l’application sur le nouveau
téléphone dans *Mes paramètres*. Si vous n’avez plus les codes non plus, l’administration réinitialise le second
facteur ; le mot de passe suffit alors jusqu’à la nouvelle configuration.

### Dans quelle langue l’application s’affiche-t-elle ?

L’ordre est le suivant : le choix enregistré dans le compte, le choix fait pendant cette session ou sur cet appareil,
la langue du navigateur, et enfin la langue par défaut définie par l’administration.

### J’ai choisi le français, mais une entrée de menu ou une table reste en allemand.

Aucune désignation française n’est saisie pour cette entrée ; la désignation allemande s’affiche alors. L’administration
peut la compléter sous Menus ou dans le Concepteur de tables. Les données, comme les noms d’entreprises, ne sont jamais
traduites.

### Je ne vois pas de bouton « Exporter ».

Soit l’export n’est pas autorisé pour cette table (Concepteur de tables → Autoriser l’export), soit votre rôle n’a pas
le droit (Rôles → Droits sur les données → Exporter).

### Pour une table, « Nouvel enregistrement » et « Modifier » manquent.

Il s’agit d’une table liée d’une base de données externe (Connecteur de tables) pour laquelle l’écriture n’est pas
activée (Connecteur de tables → Écriture), ou votre rôle n’a pas le droit (Rôles → Droits sur les données).

### Comment transmettre une vue précise à quelqu’un ?

Copiez l’adresse dans le navigateur : elle contient la table, le choix des colonnes et les enregistrements
sélectionnés. Le destinataire ne voit que ce pour quoi il a des droits.

### Un fichier ne se téléverse pas.

Le champ n’autorise que certains types de fichiers, ainsi qu’une taille et un nombre maximaux (Concepteur de tables →
champ → Fichiers autorisés, Taille maximale par fichier). Le contenu est contrôlé : un fichier renommé avec une mauvaise
extension est refusé. Si même de petits fichiers autorisés échouent, la limite du serveur est trop basse
(administration système : `upload_max_filesize` et `post_max_size`).

### Une table manque dans le choix d’une liaison.

Les liaisons ne sont possibles qu’entre tables de la même app (guide administrateur, chapitre 16). Si la table voulue
appartient à une autre app, créez la nouvelle table dans cette app – ou étendez cette app.
