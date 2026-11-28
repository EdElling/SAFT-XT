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
   - [Pourquoi ai-je été déconnecté ?](#pourquoi-ai-je-été-déconnecté-)
   - [Dans quelle langue l’application s’affiche-t-elle ?](#dans-quelle-langue-lapplication-saffiche-t-elle-)
   - [J’ai choisi le français, mais une entrée de menu ou une table reste en allemand.](#jai-choisi-le-français-mais-une-entrée-de-menu-ou-une-table-reste-en-allemand)
   - [Je ne vois pas de bouton « Exporter ».](#je-ne-vois-pas-de-bouton--exporter-)
   - [Pour une table, « Nouvel enregistrement » et « Modifier » manquent.](#pour-une-table--nouvel-enregistrement--et--modifier--manquent)
   - [Comment transmettre une vue précise à quelqu’un ?](#comment-transmettre-une-vue-précise-à-quelquun-)
   - [Un fichier ne se téléverse pas.](#un-fichier-ne-se-téléverse-pas)
   - [Une table manque dans le choix d’une liaison.](#une-table-manque-dans-le-choix-dune-liaison)
   - [Une app a disparu du sélecteur.](#une-app-a-disparu-du-sélecteur)
   - [Je ne peux pas retirer un document d’un champ de fichiers.](#je-ne-peux-pas-retirer-un-document-dun-champ-de-fichiers)
   - [Dans une liste, les cases à cocher pour sélectionner manquent.](#dans-une-liste-les-cases-à-cocher-pour-sélectionner-manquent)
   - [Un document n’a pas pu être envoyé par e-mail.](#un-document-na-pas-pu-être-envoyé-par-e-mail)
   - [La QR-facture manque ou l’app bancaire ne la lit pas.](#la-qr-facture-manque-ou-lapp-bancaire-ne-la-lit-pas)
   - [Le fichier Word n’a pas le même aspect que le PDF.](#le-fichier-word-na-pas-le-même-aspect-que-le-pdf)
   - [À la place du fichier, une page « Travail » avec barre de progression s’affiche.](#à-la-place-du-fichier-une-page--travail--avec-barre-de-progression-saffiche)
   - [L’import d’un gros fichier prend du temps.](#limport-dun-gros-fichier-prend-du-temps)
   - [Comment les factures reçoivent-elles un numéro continu ?](#comment-les-factures-reçoivent-elles-un-numéro-continu-)
   - [Comment reprendre des numéros de facture existants dans une plage de numéros ?](#comment-reprendre-des-numéros-de-facture-existants-dans-une-plage-de-numéros-)
   - [Comment une facture reçoit-elle son numéro seulement à l’envoi ?](#comment-une-facture-reçoit-elle-son-numéro-seulement-à-lenvoi-)
   - [Comment les factures et les notes de crédit reçoivent-elles leurs propres numéros ?](#comment-les-factures-et-les-notes-de-crédit-reçoivent-elles-leurs-propres-numéros-)
   - [Comment modifier de nombreux enregistrements en une fois ?](#comment-modifier-de-nombreux-enregistrements-en-une-fois-)
   - [La modification groupée indique « Rien n’a été modifié ».](#la-modification-groupée-indique--rien-na-été-modifié-)
   - [Lors de la modification groupée, des enregistrements sont restés inchangés.](#lors-de-la-modification-groupée-des-enregistrements-sont-restés-inchangés)
   - [Comment annuler une modification groupée ?](#comment-annuler-une-modification-groupée-)
   - [Comment exporter seulement certains enregistrements ?](#comment-exporter-seulement-certains-enregistrements-)
   - [Quels enregistrements une modification groupée a-t-elle modifiés ?](#quels-enregistrements-une-modification-groupée-a-t-elle-modifiés-)
   - [Quand et par qui un enregistrement a-t-il été créé ?](#quand-et-par-qui-un-enregistrement-a-t-il-été-créé-)
   - [Comment annuler une seule modification ?](#comment-annuler-une-seule-modification-)
   - [Comment trouver les enregistrements dont un champ est vide ?](#comment-trouver-les-enregistrements-dont-un-champ-est-vide-)

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

### Pourquoi ai-je été déconnecté ?

Après un certain temps sans utilisation, l’application vous déconnecte (par défaut : 4 heures ; l’administration peut
modifier ou désactiver la limite). Une fenêtre ouverte seule ne compte pas comme utilisation, contrairement aux clics,
à la saisie au clavier, au défilement et aux changements de page. Cinq minutes avant, un avertissement apparaît avec
**Continuer à travailler**. Avec « Se souvenir de moi », la connexion survit à la fermeture du navigateur, mais au
plus 12 heures à partir de la connexion. Voir [Guide utilisateur, chapitre 2](USER_GUIDE.fr.md#2-se-connecter-et-se-déconnecter).

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

Les liaisons existent entre tables de la même app ; les tables d’une autre app n’apparaissent que si cette app les a
**partagées comme interface** – alors avec le nom de l’app entre parenthèses, et seuls les champs partagés sont proposés
comme champs affichés (guide administrateur, chapitre 15). Si la table manque, demandez à l’administration de la
partager – ou créez la nouvelle table dans cette app.

### Une app a disparu du sélecteur.

L’administration l’a désactivée – p. ex. pour des travaux ou avant de la retirer. Ses données sont conservées ; dès
qu’elle est réactivée, elle réapparaît comme avant. Renseignez-vous auprès de l’administration.

### Je ne peux pas retirer un document d’un champ de fichiers.

Les documents que SAFT a créés à partir d’un modèle et déposés avec l’enregistrement (p. ex. une facture) sont protégés
en écriture : seules les personnes autorisées à supprimer les enregistrements de la table peuvent les retirer. Ainsi, on
sait toujours quel document a été envoyé. Créez simplement un document corrigé – il apparaît comme nouvelle version à
côté (manuel de l’utilisateur, section 4.10). Si un document doit vraiment disparaître, demandez à une personne ayant
le droit de suppression.

### Dans une liste, les cases à cocher pour sélectionner manquent.

Les cases à cocher pour sélectionner plusieurs enregistrements n’apparaissent que s’il existe une action groupée que
vous pouvez utiliser : **« Modifier … »** (droit *Modifier*, et l’administration autorise la modification groupée pour la
table ; manuel de l’utilisateur, section 4.11), **« Supprimer la sélection »** (droit *Supprimer*), **« Exporter la sélection »** (droit *Exporter* et export activé pour la table ; section 4.7) ou **« Créer des
documents »** (modèle de document actif et droit *Documents: générer* ; section 4.10). Les tables liées n’ont pas de
modification groupée, mais bien « Exporter la sélection ».

### Un document n’a pas pu être envoyé par e-mail.

- **Pas d’interrupteur « Envoyer par e-mail » :** le modèle ne prévoit pas d’e-mail, ou votre rôle n’a pas le droit
  *Documents : envoyer*.
- **« À » est vide ou « pas d’adresse e-mail valable » :** l’enregistrement ou le client lié n’a pas d’adresse – ou vous
  ne pouvez pas lire la table liée. Saisissez l’adresse à la main.
- **« Le serveur de messagerie refuse l’adresse d’expéditeur … »** ou un autre message du serveur : c’est un paramètre
  de l’administration (envoi d’e-mails dans les paramètres globaux). Le document a tout de même été créé et – si prévu –
  déposé.

### La QR-facture manque ou l’app bancaire ne la lit pas.

- **« La QR-facture n’est pas valable » ou « il manque dans le papier à lettres » :** il manque des données du
  bénéficiaire (NPA, localité, IBAN) dans le papier à lettres ou l’IBAN est erroné – l’administration corrige le papier à
  lettres (manuel d’administration, chapitre 16, « QR-facture »).
- **« Le montant de la QR-facture n’est pas un nombre » :** l’enregistrement n’a pas de montant ou ce n’est pas un nombre.
- **L’app bancaire ne reconnaît pas le code :** afficher le PDF en taille réelle à l’écran ou l’imprimer (sans réduction,
  « ajuster à la page » désactivé). Le code doit mesurer 46 × 46 mm.

### Le fichier Word n’a pas le même aspect que le PDF.

La version Word est destinée à être retravaillée, elle ne remplace pas le PDF : un papier à lettres en arrière-plan PDF,
un logo SVG et la QR-facture n’existent que dans le PDF ; Word affiche à la place le logo (PNG/JPEG) et l’expéditeur en
texte dans l’en-tête et utilise la police Arial. Pour l’envoi et le dépôt, SAFT utilise toujours le PDF.

### À la place du fichier, une page « Travail » avec barre de progression s’affiche.

L’export, l’import, « Créer des documents » pour de nombreux enregistrements et les états des données s’exécutent comme
**travail** par étapes. Les petites quantités sont terminées avant que vous ne le remarquiez, et le fichier arrive comme
d’habitude. Si cela dure plus longtemps, SAFT affiche la page du travail : la laisser ouverte est le plus rapide ; mais
vous pouvez aussi la fermer – le travail se poursuit en arrière-plan, la cloche annonce la fin, et le fichier reste
téléchargeable 24 heures sous **Mes travaux** (menu utilisateur).

### L’import d’un gros fichier prend du temps.

Chaque enregistrement est créé comme à la main – avec valeurs par défaut, champs calculés, sommes dans les
enregistrements parents et règles des plugins. Cela coûte quelques millisecondes par enregistrement ; avec des dizaines
de milliers de lignes, cela fait des minutes. La progression montre d’abord **vérification**, puis **importation**. Vous
pouvez quitter la page ; en cas d’erreur ou d’annulation, rien n’est créé ou tout ce qui a été créé est retiré.

### Comment les factures reçoivent-elles un numéro continu ?

Avec un champ de type **Plage de numéros**, p. ex. avec le modèle `R-{YYYY}-{0000}` et un redémarrage annuel. SAFT
attribue le numéro à la création, jamais deux fois et pas de nouveau après une suppression ; ensuite, il ne peut plus
être modifié. C’est pourquoi le formulaire affiche seulement « attribué à l’enregistrement ». Configuration dans le
concepteur de tables, voir [Manuel d’administration, Plages de numéros](ADMIN_GUIDE.fr.md#plages-de-numéros).

### Comment reprendre des numéros de facture existants dans une plage de numéros ?

Dans le concepteur de tables, faites passer le champ existant (texte, nombre entier ou choix) au type **Plage de
numéros**. Auparavant, SAFT indique combien de valeurs sont conformes au modèle et où les numéros se poursuivent ; les
valeurs non conformes restent inchangées. Les valeurs vides peuvent être remplies tout de suite ou plus tard avec
**Numéroter maintenant**, et avec **Définir le compteur** l’année suivante commence p. ex. à 1000. Voir
[Manuel d’administration, Convertir des champs existants](ADMIN_GUIDE.fr.md#convertir-des-champs-existants).

### Comment une facture reçoit-elle son numéro seulement à l’envoi ?

Saisissez une condition sous **Attribuer si** pour la plage de numéros, p. ex. `status <> 'brouillon'`. Le numéro reste
alors vide pour les brouillons et est attribué au premier enregistrement où la condition est vraie ; ensuite il est fixe.
Les brouillons abandonnés ne laissent ainsi pas de trous. Voir
[Manuel d’administration, Attribuer seulement si une condition est remplie](ADMIN_GUIDE.fr.md#attribuer-seulement-si-une-condition-est-remplie).

### Comment les factures et les notes de crédit reçoivent-elles leurs propres numéros ?

Sous **Groupe** dans la plage de numéros, choisissez le champ de sélection du type de justificatif et placez `{GROUP}`
dans le modèle, p. ex. `{GROUP}-{YYYY}-{000}`. Saisissez un code pour chaque choix, p. ex. `R` pour facture et `G` pour
note de crédit ; chaque type compte alors pour lui-même (`R-2026-001`, `G-2026-001`). Voir
[Manuel d’administration, Un compteur par groupe](ADMIN_GUIDE.fr.md#un-compteur-par-groupe).

### Comment modifier de nombreux enregistrements en une fois ?

Filtrez la liste, cochez les cases (ou « Sélectionner tout (N) ») et cliquez sur **« Modifier … »**. Cochez les champs,
choisissez le mode (*définir*, *vider*, *calculer*, *remplacer*,
*formule*) et contrôlez l’aperçu ; **« Vérifier seulement »** ne modifie encore rien. Exemple de
l’école de musique : filtrer semestre et enseignant, sélectionner tous les cours, définir *Enseignant* sur le nouveau.
De même : *calculer* le tarif des cours d’une branche « modifier de % » 5, arrondi au multiple 0,05 ; *remplacer*
« str. » par « strasse » dans les adresses.
Voir [Manuel d’utilisation 4.11](USER_GUIDE.fr.md#411-modifier-plusieurs-enregistrements-modification-groupée).

### La modification groupée indique « Rien n’a été modifié ».

Au moins un enregistrement sélectionné ne passe pas les contrôles – les mêmes que lors d’une modification manuelle (champ
obligatoire, règle de validation, règle de la table, plugin). Le message nomme les enregistrements avec la raison. C’est
tout ou rien : désélectionnez ces enregistrements ou corrigez-les un par un et relancez la modification groupée. S’il est
écrit « modifié entre-temps par quelqu’un d’autre », quelqu’un a enregistré pendant la modification groupée ; elle a été
entièrement annulée et peut simplement être répétée. Avec les calculs et les formules, deux raisons s’ajoutent :
**« Division par 0 »** (la formule divise par une valeur 0 pour cet enregistrement ; `if(champ = 0, …, …)` l’évite) et
**« Le nombre est trop grand pour le champ »** (le résultat a plus de chiffres avant la virgule que le champ ne le
permet). L’aperçu affiche déjà les deux en rouge. Si le message nomme un conflit avec un enregistrement introuvable (p. ex. « enseigne déjà le mercredi à 23:00 … »), il existe entre deux enregistrements sélectionnés : le contrôle voit déjà les autres enregistrements sélectionnés avec leurs nouvelles valeurs.

### Lors de la modification groupée, des enregistrements sont restés inchangés.

C’est voulu lorsque rien ne changerait pour eux : ils avaient déjà la nouvelle valeur (« déjà ainsi »), ou lors d’un
calcul la valeur précédente était vide, lors d’un remplacement le texte recherché n’apparaissait pas, ou la formule ne
donnait rien (« sans valeur ou sans occurrence »). L’aperçu et le message comptent les deux. Pour remplir des valeurs
vides, filtrez sur « vide » et choisissez *définir*. Si *remplacer* trouve moins que prévu, vérifiez les cases
« Respecter la casse » et « Mot entier uniquement » : « Str. » comme mot entier ne correspond pas dans « Bahnhofstr. »,
car une lettre le précède.

### Comment annuler une modification groupée ?

Par le lien **« Annuler »** dans le message de réussite ou plus tard par **« Dernières modifications groupées »**
au-dessus de la table. La confirmation indique au préalable combien d’enregistrements reviennent et des exemples avec
*maintenant → après*. Les enregistrements dont un champ modifié a changé de valeur depuis restent et le message les
nomme. Si le bouton est désactivé (« Aucun enregistrement ne serait annulé »), tous les enregistrements ont été modifiés
depuis, souvent par une modification groupée plus récente : annulez d’abord celle-ci. Les numéros attribués et les
e-mails envoyés restent. Seules les personnes autorisées à voir le journal des modifications annulent les modifications
groupées d’autres personnes. Les actions groupées des plugins (p. ex. « Créer les leçons ») ne figurent pas dans la
liste et ne peuvent pas y être annulées. Voir
[Manuel d’utilisation 4.11](USER_GUIDE.fr.md#411-modifier-plusieurs-enregistrements-modification-groupée).

### Comment exporter seulement certains enregistrements ?

Cochez les enregistrements en début de ligne et choisissez **« Exporter la sélection »** (Excel ou CSV) dans la barre
au-dessus du tableau. Le fichier ne contient que les enregistrements sélectionnés, dans l’ordre de la liste et avec les
colonnes visibles. Pour sélectionner sur toutes les pages, utilisez la case de l’en-tête et **« Sélectionner tout (N) »** ;
vous pouvez ensuite en décocher certains. « Exporter » en haut exporte en revanche tous les résultats du filtre. Voir
[Manuel d’utilisation 4.7](USER_GUIDE.fr.md#47-exporter-des-données).

### Quels enregistrements une modification groupée a-t-elle modifiés ?

Ouvrez **« Dernières modifications groupées »** au-dessus de la table et cliquez sur **« Afficher les enregistrements »**
à côté de la modification groupée (le lien figure aussi dans la confirmation « Annuler »). La liste montre alors
exactement ces enregistrements, avec une étiquette « Modification groupée du … » au-dessus du tableau. Votre vue
enregistrée est conservée : le ✕ de l’étiquette ou « Réinitialiser la vue » rétablit vos filtres précédents. Voir
[Manuel d’utilisation 4.11](USER_GUIDE.fr.md#411-modifier-plusieurs-enregistrements-modification-groupée).

### Quand et par qui un enregistrement a-t-il été créé ?

Dans le menu des colonnes (icône des colonnes à côté du filtre), affichez les colonnes **Créé le**, **Créé par**,
**Modifié le** et **Modifié par** ; elles se trouvent à la fin de la liste. Vous pouvez trier selon les dates, choisir
une période dans le filtre et filtrer par personne. Qui a modifié quoi exactement figure dans l’**historique** d’un
enregistrement. Voir [Manuel d’utilisation 4.1](USER_GUIDE.fr.md#41-la-vue-tableau).

### Comment annuler une seule modification ?

Ouvrez l’**historique** de l’enregistrement (icône horloge dans la ligne, sur smartphone sous « Détails ») et cliquez
sur la flèche de retour à côté de l’entrée « modifié ». La confirmation montre pour chaque champ *maintenant → revient
à* ; décochez les champs qui doivent rester et choisissez **Annuler la modification**. Les champs modifiés depuis ne
sont pas cochés – cochez-les si vous voulez quand même les annuler. Si la flèche manque, l’entrée n’est pas une
modification (p. ex. « créé »), tous les champs ont déjà l’ancienne valeur ou vous n’avez pas le droit de modifier.
Les modifications groupées entières s’annulent via « Dernières modifications groupées ». Voir
[Manuel d’utilisation 4.1](USER_GUIDE.fr.md#41-la-vue-tableau).

### Comment trouver les enregistrements dont un champ est vide ?

Ouvrez le filtre de la table. Pour les champs texte, nombre et date, choisissez **vides uniquement** (ou **remplis
uniquement**) à côté de la saisie ; pour les listes de sélection, les liaisons et oui/non, l’entrée **« (vide) »** dans
la liste. Une étiquette telle que « Numéro de facture : vide » apparaît au-dessus du tableau. Vous continuez avec la
liste filtrée : exporter, ou tout sélectionner et modifier ensemble. Les champs obligatoires n’ont pas ce choix, car
ils ne sont jamais vides. Voir [Manuel d’utilisation 4.1](USER_GUIDE.fr.md#41-la-vue-tableau).
