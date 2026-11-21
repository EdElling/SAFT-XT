# Scuola di musica: pianificazione – manuale d’uso

## Panoramica

Il plugin *Scuola di musica: pianificazione* aggiunge all’app **Scuola di musica**:

- l’**orario** per docente, aula o allievo,
- un **controllo dei conflitti** al salvataggio di un corso,
- il **riporto del semestre**: riprendere corsi e iscrizioni nel semestre successivo,
- la **generazione delle lezioni**: creare in una volta le lezioni settimanali di un semestre, senza le vacanze,
- la **registrazione delle presenze**: tutti gli iscritti di una lezione su una sola pagina,
- un **avviso per la lista d’attesa** non appena in un corso si libera un posto.

Generare le lezioni e registrare le presenze richiedono le tabelle *Lezioni* e *Presenze*; la tabella **Vacanze** esiste
dalla versione 1.1 dell’app.

La durata di un corso deriva dal suo **tipo di corso** (campo *Durata (minuti)*); se manca, valgono 45 minuti.

## Orario

In **Scuola di musica › Insegnamento › Orario** scegliete il **semestre** (predefinito: quello in corso), la **vista** –
*docente*, *aula* o *allievo* – e poi la persona o l’aula.

- Ogni corso appare come **blocco colorato** nel giorno della settimana, dall’inizio alla fine; il colore indica la
  materia (legenda sotto la griglia).
- Passando con il mouse su un blocco vedete ora, materia, occupazione (p. es. «3 posti su 8 occupati»), il numero in
  lista d’attesa e i nomi dei partecipanti. Un **clic** apre il corso nella tabella *Corsi*, con
  iscrizioni e lezioni sotto.
- La vista *allievo* mostra i corsi con un’iscrizione attiva.
- Le **sovrapposizioni** – due corsi alla stessa ora – sono affiancate e **bordate di rosso**; sopra la griglia ne è
  indicato il numero.
- I corsi senza giorno od ora sono elencati sotto la griglia.
- **Stampa** produce titolo, griglia e legenda in A4 orizzontale.

## Controllo dei conflitti

Al salvataggio di un corso SAFT verifica, nello **stesso semestre** e nello **stesso giorno**, se l’orario si sovrappone
a un altro corso che

- ha lo stesso **docente** oppure
- si svolge nella stessa **aula**.

In tal caso il corso **non viene salvato** e il messaggio indica l’altro corso, p. es. «Raum 1 è già occupata da «Klavier
Mia» il lunedì dalle 16:00 alle 16:45.» Corsi consecutivi (16:00–16:45 e 16:45–17:15) sono ammessi. Il controllo avviene
alla creazione e quando cambiano giorno, ora, tipo di corso, docente, aula o semestre – anche in caso di importazione e
di riporto del semestre.

Se un corso ammette **più partecipanti** dei **posti** della sua aula, viene comunque salvato; ricevete un avviso nella
campanella.

## Riportare un semestre

In **Scuola di musica › Dati di base › Semestri** riprendete i corsi di un semestre in un altro:

- nella riga di un semestre con l’icona **Riportare il semestre** (quel semestre è l’origine) oppure
- sopra la tabella con **Riportare il semestre** (origine: il semestre in corso).

Nella finestra di dialogo scegliete la **destinazione** (predefinita: il semestre successivo) e:

| Opzione | Predefinito | Effetto |
|---|---|---|
| Riprendere giorno, ora e aula | sì | altrimenti restano vuoti e si pianificano nel nuovo semestre |
| Riprendere le iscrizioni attive | sì | in **lista d’attesa**, con la data di oggi e lo sconto precedente |
| Tariffa dal tipo di corso | sì | altrimenti vale la tariffa del corso precedente |

L’**anteprima** indica quanti corsi e iscrizioni verranno ripresi. I corsi con lo stesso nome già presenti nella
destinazione vengono **saltati** e indicati per nome – un secondo riporto non crea quindi doppioni.

Il riporto avviene **tutto o niente**: se il controllo rifiuta un corso (p. es. perché nella destinazione un’aula è già
occupata), non viene ripreso nulla e il messaggio ne indica il motivo.

**Dopo il riporto:** confermate le iscrizioni in **Insegnamento › Iscrizioni** cambiando lo stato da *lista d’attesa*
ad *attivo*; chi non continua diventa *Disiscritto*.

## Generare le lezioni

Invece di registrare ogni lezione singolarmente, SAFT le crea **settimanalmente** per un periodo – nel giorno del corso,
con il suo docente e la durata del tipo di corso. La finestra **Generare le lezioni** si trova in tre punti:

- nella riga di un **corso** (solo questo corso),
- nella riga di un **semestre** (tutti i suoi corsi),
- sopra la tabella **Corsi** (tutti i corsi del semestre scelto, predefinito quello in corso).

Nella finestra scegliete **Dal** e **Al** – predefinito da oggi se il semestre è in corso, altrimenti dall’inizio del
semestre, fino alla sua fine – e se **saltare vacanze e festivi**. L’**anteprima** indica in anticipo cosa succederà,
p. es. «8 lezioni in 1 corso. 2 date cadono nelle vacanze. 3 date hanno già una lezione.»

- Le lezioni vengono create solo all’interno del semestre del corso, anche se il periodo va oltre.
- Le date che hanno già una lezione vengono saltate – potete quindi **ripetere** l’operazione senza doppioni, p. es.
  dopo aver aggiunto un nuovo corso.
- I corsi senza giorno vengono saltati e indicati.
- Vale **tutto o niente**: se una lezione non riesce, non ne viene creata nessuna.

Registrate le **vacanze** in **Scuola di musica › Dati di base › Vacanze** con denominazione, *Dal* e *Al*; un solo
giorno festivo ha *Dal* = *Al*.

## Registrare le presenze

In **Scuola di musica › Insegnamento › Registrare le presenze** – oppure con l’icona appunti nella riga di una
**lezione** – registrate le presenze di una lezione su una sola pagina.

- In alto scegliete la **lezione**; l’elenco propone le lezioni del suo semestre, p. es. «Mo 17.08.2026 · Klavier Mia ·
  Mia Huber». Senza preselezione è scelta la prossima lezione di oggi, altrimenti l’ultima passata.
- Sotto compaiono tutti gli **iscritti attivi** del corso con tre pulsanti *presente* (predefinito), *giustificato* e
  *ingiustificato* e un’**osservazione**. **Tutti presenti** riporta tutte le righe su *presente*.
- Le presenze già registrate vengono caricate – anche per allievi non più iscritti attivamente (con nota).
- **Salva** crea le presenze mancanti e modifica quelle cambiate; nulla viene creato due volte. Il messaggio indica
  quante sono nuove e quante modificate.

## Avviso per la lista d’attesa

Quando in un corso **si libera un posto** e ci sono iscrizioni in **lista d’attesa**, ricevete un avviso nella
campanella, p. es. «Klavier Mia: 1 posto libero» – con le persone in attesa nell’ordine della data d’iscrizione e il
pulsante **Vedere le iscrizioni**, che apre il corso. Un posto si libera quando

- un’iscrizione attiva diventa *Disiscritto* o *lista d’attesa*,
- un’iscrizione attiva viene eliminata oppure
- il **numero massimo** di partecipanti del corso aumenta.

Nessuno avanza automaticamente: concordate con le famiglie chi ottiene il posto, poi impostate lo stato su *attivo*.

## Diritti

| Diritto | Effetto |
|---|---|
| Scuola di musica: pianificazione: Vedere l’orario | pagina *Orario* (con il diritto di vedere i corsi) |
| Scuola di musica: pianificazione: Riportare il semestre | pulsanti *Riportare il semestre* (con il diritto di creare corsi e iscrizioni) |
| Scuola di musica: pianificazione: Generare lezioni | pulsanti *Generare le lezioni* (con il diritto di creare lezioni) |
| Scuola di musica: pianificazione: Registrare le presenze | pagina *Registrare le presenze* (con il diritto di vedere le presenze; per salvare, di crearle e modificarle) |

Il controllo dei conflitti e l’avviso per la lista d’attesa valgono per chiunque modifichi corsi e iscrizioni – non
richiedono un diritto proprio.
