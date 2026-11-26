# Scuola di musica

## Panoramica

L'app **Scuola di musica** gestisce una piccola scuola di musica: allievi e le loro persone di contatto, docenti, corsi
per semestre, lezioni con presenze, strumenti a noleggio, fatture semestrali con pagamenti, concerti e saggi. Tutti
gli importi sono in franchi svizzeri (CHF).

È un esempio di applicazione di medie dimensioni: 21 tabelle, progettate con il **designer di app IA** e poi rifinite
a mano. La demo viene installata da una definizione di app – esattamente come tramite **App › Installa app**.

| Menu | Tabelle |
|---|---|
| Insegnamento | Corsi, Iscrizioni, Lezioni, Presenze, Eventi, Esibizioni |
| Persone | Allievi, Persone di contatto, Contatti allievi, Docenti, Materie dei docenti |
| Finanze & strumenti | Fatture, Posizioni fattura, Pagamenti, Strumenti a noleggio, Contratti di noleggio |
| Dati di base | Materie, Tipi di corso (con tariffa semestrale), Semestri, Aule, Vacanze |

I dati di esempio mostrano un semestre concluso (**HS 2026**) con lezioni, fatture e pagamenti e uno appena iniziato
(**FS 2027**) con i primi corsi e iscrizioni.

## Attività tipiche

### Creare un corso e iscrivere gli allievi

1. In **Insegnamento › Corsi** creare un corso con materia, tipo di corso, docente, aula, semestre, giorno e ora. La
   **tariffa** viene ripresa dal tipo di corso e può essere modificata.
2. In **Iscrizioni** registrare i partecipanti – stato *attivo*, *lista d'attesa* o *ritirato*, se necessario con uno
   sconto in percentuale (p. es. per fratelli e sorelle).
3. Nel corso, **iscrizioni**, **posti liberi** e **occupazione** mostrano quanto è pieno; contano solo le iscrizioni
   attive.

### Lezioni e presenze

Per ogni lezione registrare data, durata e tema e, sotto, le **presenze** (presente, giustificato, ingiustificato). La
**tariffa oraria** viene dal docente; SAFT calcola **ore** e **onorario**. Nell'allievo, il **tasso di presenza**
mostra la sua regolarità.

**Dati di base › Vacanze** elenca vacanze scolastiche e giorni festivi senza lezioni (un solo giorno festivo: *Dal* =
*Al*). Con il plugin **Scuola di musica: pianificazione** generate in una volta le lezioni di un semestre – senza questi
giorni – e registrate le presenze di una lezione su una sola pagina.

### Fattura semestrale

1. In **Finanze & strumenti › Fatture** creare una fattura alla persona di contatto (per i minorenni) o all'allievo
   adulto, con data fattura, semestre e scadenza. SAFT assegna il **numero fattura** al salvataggio (intervallo di
   numerazione `R-2026-001`, che riparte ogni anno secondo la data fattura); in seguito non è più modificabile.
2. Sotto, una **posizione fattura** di tipo *corso* per ogni corso – SAFT riprende il **prezzo unitario** dalla tariffa
   del corso e deduce lo sconto. Gli strumenti a noleggio sono una posizione di tipo *noleggio* con il numero di mesi.
3. Registrare i **pagamenti**; **pagato** e **aperto** mostrano lo stato. Una fattura annullata non ha più nulla di
   aperto.

Copiando una fattura (p. es. per il semestre successivo) vengono riprese le posizioni, non i pagamenti.

Create la fattura come PDF sulla carta intestata nella riga della fattura con **«Crea documento»** (modello
«Semesterrechnung») – indirizzata alla persona di contatto, altrimenti all’allievo.
**«Depositare nel campo ‹Documenti›»** è preselezionato: il PDF viene aggiunto, protetto da scrittura, al campo
**Documenti** della fattura (ogni creazione come nuova versione). Depositare richiede il diritto di modificare le
fatture; il ruolo *Musikschule* lo ha. **«Inviare per e-mail»** invia la fattura all’indirizzo e-mail della persona di
contatto, altrimenti dell’allievo – per questo il ruolo necessita in più del diritto *Documenti: inviare*
(volutamente non predefinito).
In fondo alla fattura semestrale c’è la **fattura QR** (altrimenti su una pagina propria) con l’importo aperto e il
numero di fattura come riferimento, pagabile dalla persona di contatto o dall’allievo. Il beneficiario è il mittente della carta intestata
(demo: IBAN di esempio di SIX se non è ancora indicato alcun IBAN).

### Noleggiare strumenti

Registrare ogni strumento in **Strumenti a noleggio** con numero d'inventario, stato e prezzo di noleggio, e il
noleggio in **Contratti di noleggio** con inizio, fine, cauzione e contratto (PDF).

### Concerti e saggi

Creare un evento in **Eventi** e, sotto, le **esibizioni** con brano, compositore, strumento e ordine – ne risulta il
programma.

## Report

- **Occupazione per corso** – iscrizioni, posti liberi e occupazione.
- **Entrate per materia e semestre** – importi fatturati delle posizioni dei corsi.
- **Fatture aperte per persona di contatto** – chi deve ancora quanto.
- **Tasso di presenza per allievo** – presenze, di cui presente, tasso.
- **Ore di insegnamento e onorario per docente** – base per i salari.

## Consigli

- Nella persona di contatto, **importo aperto** mostra il totale delle sue fatture aperte.
- Fratelli e sorelle condividono le persone di contatto; la casella **destinatario della fattura** nei contatti degli
  allievi indica chi riceve la fattura.
- Il ruolo **Musikschule** ha tutti i diritti su questa app – adatto alla direzione o alla segreteria.
- L'**assistente di aiuto** (pulsante **?** in alto a destra) risponde alle domande sull'uso – anche su questo manuale.
