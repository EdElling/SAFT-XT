# Scuola di musica: pianificazione – manuale d’uso

## Panoramica

Il plugin *Scuola di musica: pianificazione* aggiunge tre elementi all’app **Scuola di musica**:

- l’**orario** per docente, aula o allievo,
- un **controllo dei conflitti** al salvataggio di un corso,
- il **riporto del semestre**: riprendere corsi e iscrizioni nel semestre successivo.

La durata di un corso deriva dal suo **tipo di corso** (campo *Durata (minuti)*); se manca, valgono 45 minuti.

## Orario

In **Scuola di musica › Insegnamento › Orario** scegliete il **semestre** (predefinito: quello in corso), la **vista** –
*docente*, *aula* o *allievo* – e poi la persona o l’aula.

- Ogni corso appare come **blocco colorato** nel giorno della settimana, dall’inizio alla fine; il colore indica la
  materia (legenda sotto la griglia).
- Passando con il mouse su un blocco vedete ora, materia, occupazione (p. es. «3 posti su 8 occupati») e i nomi dei
  partecipanti. Un **clic** apre il corso nella tabella *Corsi*, con iscrizioni e lezioni sotto.
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

## Diritti

| Diritto | Effetto |
|---|---|
| Scuola di musica: pianificazione: Vedere l’orario | pagina *Orario* (con il diritto di vedere i corsi) |
| Scuola di musica: pianificazione: Riportare il semestre | pulsanti *Riportare il semestre* (con il diritto di creare corsi e iscrizioni) |

Il controllo dei conflitti vale per chiunque modifichi corsi – non richiede un diritto proprio.
