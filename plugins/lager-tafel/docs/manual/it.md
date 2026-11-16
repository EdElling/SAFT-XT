# Tabellone delle giacenze – guida utente

## Panoramica

Il plugin *Tabellone delle giacenze* aggiunge alla demo magazzino due viste e alcune regole:

- **Matrice delle giacenze** – tutti gli articoli con la giacenza per ubicazione a colpo d’occhio.
- **Tabellone delle giacenze** – trasferire la giacenza di un articolo in un’altra ubicazione trascinandola.
- Un riquadro **Magazzino: giacenza per ubicazione** sulla dashboard.
- Regole per il **codice articolo** e due tipi di **attività pianificate** (promemoria inventario, segnalare la scorta
  minima).

Le registrazioni passano dal plugin *Trasferimento di magazzino*; la sua guida descrive giacenze, movimenti di magazzino
e inventario. Se è disattivato, matrice e tabellone mostrano un avviso.

## Matrice delle giacenze

**Magazzino (demo) › Matrice delle giacenze** mostra una riga per articolo e una colonna per ubicazione con la giacenza;
le ubicazioni senza giacenza mostrano 0 in grigio. **Totale** somma tutte le ubicazioni ed è rosso quando l’articolo è
sotto la sua **scorta minima**.

- **Cercare** per codice articolo o denominazione con il campo di ricerca.
- **Ordinare** per codice, articolo o scorta minima con le intestazioni (le colonne delle ubicazioni e *Totale* non si
  ordinano).
- **Filtro «Solo sotto la scorta minima»** mostra solo gli articoli con giacenza totale sotto la scorta minima.
- Il pulsante **Distribuire sul tabellone** in una riga apre il tabellone per questo articolo.

## Tabellone delle giacenze

**Magazzino (demo) › Tabellone delle giacenze** mostra per un articolo una scheda per ubicazione con la giacenza.

1. Scegliere l’**Articolo** (oppure arrivare dalla matrice o dagli articoli con **Distribuire sul tabellone**).
2. **Trascinare** con il mouse la giacenza di una scheda **su un’altra scheda**.
3. Nella finestra di dialogo verificare la **Quantità** – è precompilata tutta la giacenza dell’origine – e cliccare
   **Trasferire**. Più di quanto disponibile non è possibile.

La registrazione avviene come nella maschera *Trasferimento*: due movimenti di tipo *Trasferimento*, tutto o niente.
Annullando la finestra non cambia nulla. Il trascinamento funziona solo con il mouse; con la tastiera trasferisci tramite
**Magazzino (demo) › Trasferimento**.

## Articoli: pulsanti e regole

In **Magazzino (demo) › Articoli** ci sono due pulsanti in più: **Matrice delle giacenze** sopra la tabella e
**Distribuire sul tabellone** in ogni riga.

Per il **codice articolo** valgono inoltre:

| Regola | Effetto |
|---|---|
| **Scrittura uniforme** | gli spazi vengono tolti, le lettere diventano maiuscole: «a-1001» diventa «A-1001» |
| **Bloccato dopo i movimenti** | se un articolo ha già movimenti di magazzino, il suo codice non si può più cambiare (un’altra scrittura dello stesso codice è ammessa). Gli altri campi restano modificabili |
| **Formato A-1234** | una lettera, un trattino, quattro cifre – solo se l’amministrazione ha scelto «Codice articolo (demo magazzino)» per il campo sotto **Ulteriori verifiche** |

Le regole valgono nell’inserimento, nell’importazione e tramite l’interfaccia. Univoco, obbligatorio e al massimo
20 caratteri sono definiti nel Designer di tabelle.

## Riquadro e attività pianificate

**Riquadro «Magazzino: giacenza per ubicazione»:** grafico a barre con la somma di tutte le giacenze per ubicazione
sulla dashboard.

L’amministrazione configura due tipi di attività pianificate in **Amministrazione › Attività pianificate**:

| Attività | Che cosa fa |
|---|---|
| **Magazzino: promemoria inventario** | elenca nel registro le giacenze con merce non contate da almeno il numero di giorni impostato (*Non contato da*, predefinito 90) – motivo per un inventario |
| **Magazzino: segnalare la scorta minima** | se ci sono articoli sotto la scorta minima, tutte le persone con il diritto *Visualizzare la matrice delle giacenze* ricevono una notifica nella campanella, con il pulsante **Aprire la matrice delle giacenze** |

## Diritti

| Per | Diritto |
|---|---|
| Matrice, riquadro, pulsante *Matrice delle giacenze*, notifica *scorta minima* | *Tabellone delle giacenze: Visualizzare la matrice delle giacenze* |
| Tabellone, pulsante *Distribuire sul tabellone* | *Tabellone delle giacenze: Trasferire sul tabellone delle giacenze* |
| Lettura | diritti sui dati *visualizzare* su Articoli, Ubicazioni e Giacenze |
| Trasferire sul tabellone | gli stessi diritti sui dati della maschera *Trasferimento* (vedi la guida *Trasferimento di magazzino*) |
