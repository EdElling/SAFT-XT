# Trasferimento di magazzino – guida utente

## Panoramica

Nella demo magazzino, il plugin *Trasferimento di magazzino* aggiorna le **giacenze** di ogni ubicazione in base ai
**movimenti di magazzino**. Registri solo movimenti – entrata, uscita, trasferimento, inventario –, la giacenza la calcola
SAFT. Si aggiungono la maschera **Trasferimento**, il pulsante **Trasferisci** nelle giacenze, una notifica quando un
articolo scende sotto la scorta minima e il riquadro **Magazzino: scorta minima** sulla dashboard.

Le tabelle si trovano nel menu sotto **Magazzino (demo)**: *Articoli*, *Ubicazioni*, *Giacenze* e *Movimenti di
magazzino*.

## Registrare movimenti di magazzino

In **Magazzino (demo) › Movimenti di magazzino**, **Nuovo** crea un movimento: *Articolo*, *Ubicazione*, *Tipo*,
*Quantità*, *Data e ora* (precompilata con adesso) e, se serve, una *Nota*.

| Tipo | Effetto sulla giacenza dell’ubicazione |
|---|---|
| **Entrata** | aumenta la giacenza della quantità |
| **Uscita** | diminuisce la giacenza della quantità |
| **Trasferimento** | creato dalla maschera *Trasferimento* (vedi sotto): un’uscita nell’ubicazione di origine e un’entrata in quella di destinazione |
| **Inventario** | corregge la giacenza della differenza inserita e registra la data dell’*Ultimo conteggio* sulla giacenza |

Per **Entrata** e **Uscita** inserisci la quantità senza segno; SAFT lo imposta da sé (uscita sempre negativa). Se per
l’articolo non esiste ancora una giacenza nell’ubicazione, la prima entrata la crea.

**Modificare o eliminare movimenti:** se modifichi un movimento (ad es. la quantità o l’ubicazione), SAFT annulla la
vecchia registrazione e registra la nuova. Un movimento eliminato viene scaricato; se lo ripristini dal cestino, viene
registrato di nuovo.

## Consultare e correggere le giacenze

**Magazzino (demo) › Giacenze** mostra per articolo e ubicazione la *Quantità* e la data dell’*Ultimo conteggio*.

Le giacenze **non si modificano direttamente**: SAFT rifiuta creazione, modifica ed eliminazione in questa tabella – anche
nell’importazione – indicando che le giacenze sono gestite tramite i movimenti di magazzino. Così ogni giacenza resta
tracciabile.

**Correggere una giacenza (inventario):** registra un movimento di tipo **Inventario** con la **differenza** tra quantità
contata e quantità registrata – negativa se ce n’è meno. Esempio: registrati 10, contati 8 → inventario con quantità −2.
La giacenza diventa 8 e l’*Ultimo conteggio* riporta la data del movimento.

**Nessuna giacenza negativa:** se un movimento porterebbe la giacenza di un’ubicazione sotto 0, non viene salvato. Il
messaggio indica articolo, ubicazione e giacenza disponibile.

## Trasferire merce

Trasferire significa spostare merce da un’ubicazione a un’altra senza cambiare la giacenza totale.

1. Aprire **Magazzino (demo) › Trasferimento** – oppure in **Giacenze** cliccare su **Trasferisci** nella riga
   dell’ubicazione di origine; articolo e origine sono allora già scelti. Senza giacenza nell’ubicazione il pulsante è
   disattivato.
2. Scegliere l’**Articolo**. **Dall’ubicazione** elenca solo le ubicazioni con giacenza di questo articolo, con la
   quantità.
3. Indicare **All’ubicazione** e **Quantità** (al massimo la giacenza all’origine) e, se serve, una **Nota**.
4. **Trasferisci**. SAFT registra due movimenti di tipo *Trasferimento* – meno all’origine, più alla destinazione – in un
   solo passo: o riescono entrambi o nessuno.

## Scorta minima

Ogni articolo ha una *Scorta minima*. Se un movimento porta la **giacenza totale** di un articolo (su tutte le
ubicazioni) sotto questo valore, la persona che ha registrato il movimento riceve la notifica **Scorta minima non
raggiunta** nella campanella. I trasferimenti non generano notifiche perché la giacenza totale non cambia; nemmeno gli
aumenti.

Il riquadro **Magazzino: scorta minima** sulla dashboard indica quanti articoli sono sotto la scorta minima, con i primi
nomi, e la giacenza totale; un clic porta al trasferimento.

## Diritti

| Per | Diritto |
|---|---|
| Maschera *Trasferimento*, pulsante *Trasferisci*, riquadro | *Trasferimento di magazzino: Eseguire trasferimenti* |
| Trasferire | inoltre diritti sui dati: *visualizzare* su Articoli e Ubicazioni, *visualizzare*, *creare* e *modificare* su Giacenze, *creare* su Movimenti di magazzino |
| Registrare movimenti | *creare* (o *modificare*, *eliminare*) su Movimenti di magazzino |

Chi registra soltanto movimenti di magazzino non ha bisogno di diritti sulle giacenze – le gestisce SAFT. Se mancano
diritti per trasferire, la maschera *Trasferimento* indica le tabelle interessate.
