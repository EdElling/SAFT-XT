# École de musique : planification – manuel d’utilisation

## Vue d’ensemble

Le plugin *École de musique : planification* ajoute trois éléments à l’app **École de musique** :

- l’**horaire** par enseignant, salle ou élève,
- un **contrôle des conflits** à l’enregistrement d’un cours,
- la **reconduction de semestre** : reprendre cours et inscriptions au semestre suivant.

La durée d’un cours vient de son **type de cours** (champ *Durée (minutes)*) ; à défaut, 45 minutes s’appliquent.

## Horaire

Sous **École de musique › Enseignement › Horaire**, choisissez le **semestre** (par défaut : le semestre en cours), la
**vue** – *enseignant*, *salle* ou *élève* – puis la personne ou la salle.

- Chaque cours apparaît comme un **bloc coloré** le jour concerné, du début à la fin ; la couleur correspond à la
  branche (légende sous la grille).
- Au survol d’un bloc, vous voyez l’heure, la branche, l’occupation (p. ex. « 3 places sur 8 occupées ») et les noms
  des participants. Un **clic** ouvre le cours dans la table *Cours*, avec inscriptions et leçons en dessous.
- La vue *élève* montre les cours avec une inscription active.
- Les **chevauchements** – deux cours à la même heure – sont affichés côte à côte et **encadrés en rouge** ; leur
  nombre est indiqué au-dessus de la grille.
- Les cours sans jour ni heure sont listés sous la grille.
- **Imprimer** produit titre, grille et légende en A4 paysage.

## Contrôle des conflits

À l’enregistrement d’un cours, SAFT vérifie dans le **même semestre** et le **même jour** si l’horaire chevauche un autre
cours qui

- a le même **enseignant** ou
- a lieu dans la même **salle**.

Le cas échéant, le cours **n’est pas enregistré** et le message nomme l’autre cours, p. ex. « Raum 1 est déjà occupée par
« Klavier Mia » le lundi de 16:00 à 16:45. » Des cours qui se suivent (16:00–16:45 et 16:45–17:15) sont permis. Le
contrôle a lieu à la création et lorsque jour, heure, type de cours, enseignant, salle ou semestre changent – aussi lors
d’un import et de la reconduction.

Si un cours admet **plus de participants** que sa salle n’a de **places**, il est tout de même enregistré ; vous recevez
un avis dans la cloche.

## Reconduire un semestre

Sous **École de musique › Données de base › Semestres**, reprenez les cours d’un semestre dans un autre :

- dans la ligne d’un semestre avec l’icône **Reconduire le semestre** (ce semestre est la source) ou
- au-dessus de la table avec **Reconduire le semestre** (source : le semestre en cours).

Dans la boîte de dialogue, choisissez la **cible** (par défaut : le semestre suivant) et :

| Option | Par défaut | Effet |
|---|---|---|
| Reprendre jour, heure et salle | oui | sinon ils restent vides et sont planifiés au nouveau semestre |
| Reprendre les inscriptions actives | oui | en **liste d’attente**, datées d’aujourd’hui, avec le rabais précédent |
| Tarif selon le type de cours | oui | sinon le tarif du cours précédent s’applique |

L’**aperçu** indique combien de cours et d’inscriptions seront repris. Les cours de même nom déjà présents dans la cible
sont **ignorés** et nommés – une deuxième exécution ne crée donc rien en double.

La reconduction se fait **entièrement ou pas du tout** : si le contrôle refuse un cours (p. ex. salle déjà occupée dans
la cible), rien n’est repris et le message en donne la raison.

**Après la reconduction :** confirmez les inscriptions sous **Enseignement › Inscriptions** en passant le statut de
*liste d’attente* à *actif* ; les élèves qui ne continuent pas sont *désinscrits*.

## Droits

| Droit | Effet |
|---|---|
| École de musique : planification : Voir l’horaire | page *Horaire* (avec le droit de voir les cours) |
| École de musique : planification : Reconduire un semestre | boutons *Reconduire le semestre* (avec le droit de créer cours et inscriptions) |

Le contrôle des conflits s’applique à toute personne qui modifie des cours – il ne nécessite aucun droit particulier.
