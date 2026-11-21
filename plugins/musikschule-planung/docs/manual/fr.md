# École de musique : planification – manuel d’utilisation

## Vue d’ensemble

Le plugin *École de musique : planification* ajoute à l’app **École de musique** :

- l’**horaire** par enseignant, salle ou élève,
- un **contrôle des conflits** à l’enregistrement d’un cours,
- la **reconduction de semestre** : reprendre cours et inscriptions au semestre suivant,
- la **création des leçons** : créer d’un coup les leçons hebdomadaires d’un semestre, sans les vacances,
- la **saisie des présences** : tous les inscrits d’une leçon sur une seule page,
- un **avis pour la liste d’attente** dès qu’une place se libère dans un cours.

La création des leçons et la saisie des présences nécessitent les tables *Leçons* et *Présences* ; la table
**Vacances** existe à partir de la version 1.1 de l’app.

La durée d’un cours vient de son **type de cours** (champ *Durée (minutes)*) ; à défaut, 45 minutes s’appliquent.

## Horaire

Sous **École de musique › Enseignement › Horaire**, choisissez le **semestre** (par défaut : le semestre en cours), la
**vue** – *enseignant*, *salle* ou *élève* – puis la personne ou la salle.

- Chaque cours apparaît comme un **bloc coloré** le jour concerné, du début à la fin ; la couleur correspond à la
  branche (légende sous la grille).
- Au survol d’un bloc, vous voyez l’heure, la branche, l’occupation (p. ex. « 3 places sur 8 occupées »), le nombre
  en liste d’attente et les noms des participants. Un **clic** ouvre le cours dans la table *Cours*,
  avec inscriptions et leçons en dessous.
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

## Créer les leçons

Au lieu de saisir chaque leçon séparément, SAFT les crée **chaque semaine** pour une période – le jour du cours, avec
son enseignant et la durée de son type de cours. La boîte de dialogue **Créer les leçons** se trouve à trois endroits :

- dans la ligne d’un **cours** (ce cours seulement),
- dans la ligne d’un **semestre** (tous ses cours),
- au-dessus de la table **Cours** (tous les cours du semestre choisi, par défaut : le semestre en cours).

Dans la boîte de dialogue, choisissez **Du** et **Au** – par défaut à partir d’aujourd’hui si le semestre est en cours,
sinon dès le début du semestre, jusqu’à sa fin – et s’il faut **sauter vacances et jours fériés**. L’**aperçu** indique à
l’avance ce qui se passera, p. ex. « 8 leçons dans 1 cours. 2 dates tombent pendant les vacances. 3 dates ont déjà une
leçon. »

- Les leçons ne sont créées qu’à l’intérieur du semestre du cours, même si la période le dépasse.
- Les dates qui ont déjà une leçon sont sautées – vous pouvez donc **répéter** l’opération sans doublons, p. ex. après
  avoir ajouté un cours.
- Les cours sans jour sont sautés et nommés.
- C’est **tout ou rien** : si une leçon échoue, aucune n’est créée.

Saisissez les **vacances** sous **École de musique › Données de base › Vacances** avec désignation, *Du* et *Au* ; un
seul jour férié a *Du* = *Au*.

## Saisir les présences

Sous **École de musique › Enseignement › Saisir les présences** – ou avec l’icône presse-papiers dans la ligne d’une
**leçon** – vous saisissez les présences d’une leçon sur une seule page.

- En haut, choisissez la **leçon** ; la liste propose les leçons de son semestre, p. ex. « Mo 17.08.2026 · Klavier Mia
  · Mia Huber ». Sans présélection, c’est la prochaine leçon du jour, sinon la dernière passée.
- En dessous figurent tous les **inscrits actifs** du cours avec trois boutons *présent* (par défaut), *excusé* et
  *non excusé* ainsi qu’une **remarque**. **Tous présents** remet toutes les lignes sur *présent*.
- Les présences déjà saisies sont chargées – aussi pour les élèves qui ne sont plus inscrits activement (avec mention).
- **Enregistrer** crée les présences manquantes et modifie celles qui ont changé ; rien n’est créé en double. Le message
  indique combien sont nouvelles et combien ont été modifiées.

## Avis pour la liste d’attente

Lorsqu’une **place se libère** dans un cours et que des inscriptions sont en **liste d’attente**, vous recevez un avis
dans la cloche, p. ex. « Klavier Mia : 1 place libre » – avec les personnes en attente dans l’ordre de leur date
d’inscription et le bouton **Voir les inscriptions**, qui ouvre le cours. Une place se libère lorsque

- une inscription active passe à *désinscrit* ou *liste d’attente*,
- une inscription active est supprimée ou
- le **nombre maximal** de participants du cours augmente.

Personne n’avance automatiquement : convenez avec les familles de qui obtient la place, puis passez son statut à
*actif*.

## Droits

| Droit | Effet |
|---|---|
| École de musique : planification : Voir l’horaire | page *Horaire* (avec le droit de voir les cours) |
| École de musique : planification : Reconduire un semestre | boutons *Reconduire le semestre* (avec le droit de créer cours et inscriptions) |
| École de musique : planification : Créer des leçons | boutons *Créer les leçons* (avec le droit de créer des leçons) |
| École de musique : planification : Saisir les présences | page *Saisir les présences* (avec le droit de voir les présences ; pour enregistrer, de les créer et modifier) |

Le contrôle des conflits et l’avis pour la liste d’attente s’appliquent à toute personne qui modifie des cours et des
inscriptions – ils ne nécessitent aucun droit particulier.
