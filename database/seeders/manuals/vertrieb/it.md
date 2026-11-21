# Vendite

## Panoramica

L’app **Vendite** mostra come due app lavorano insieme: le vendite gestiscono clienti, richieste e ordini, ma gli
**articoli** provengono dall’app **Magazzino**. Il magazzino condivide i suoi articoli tramite un’**interfaccia**
(codice articolo, denominazione, unità, prezzo di vendita); le vendite possono leggerli e sceglierli, ma non
modificarli. Tutti gli importi sono in euro.

| Tabella | Contenuto |
|---|---|
| Clienti | Numero cliente, nome, paese, località, e-mail, telefono, cliente dal |
| Eventi | Fiere, fiere aziendali, webinar e giornate clienti con inizio, fine, luogo, online sì/no |
| Richieste | Data, cliente, oggetto, stato, evento (da dove arriva la richiesta), ordine, totale |
| Righe della richiesta | Richiesta, riga, articolo del magazzino, quantità, prezzo, importo |
| Ordini | Numero d’ordine, cliente, data dell’ordine, stato, importo |

## Attività tipiche

### Registrare una richiesta

1. In **Clienti ed eventi › Clienti** seleziona il cliente – sotto compaiono le sue richieste.
2. Crea lì la richiesta con **«Nuovo record»**: data, oggetto e stato *Aperta*. Se la richiesta arriva da una fiera o
   da un webinar, scegli l’**evento**.
3. Seleziona la richiesta e registra sotto le **righe della richiesta**: bastano articolo e quantità. Gli articoli
   provengono dal magazzino; il **prezzo** viene ripreso dal prezzo di vendita dell’articolo e si può modificare per
   uno sconto.

SAFT calcola da sé, al salvataggio, l’**importo** di ogni riga (quantità × prezzo) e il **totale** della richiesta.

### Dall’offerta all’ordine

Imposta lo stato su *In corso* e, una volta inviata l’offerta, su *Offerta inviata*. Se il cliente accetta, crea l’ordine
in **Vendita › Ordini**, indica l’**ordine** nella richiesta e imposta lo stato su *Vinta*. Le richieste rifiutate
ricevono lo stato *Persa*; restano disponibili per i report.

### Pianificare eventi

Registra fiere e webinar in **Clienti ed eventi › Eventi**. La fine non può precedere l’inizio. Sotto un evento vedi
tutte le richieste nate da esso.

## Report

- **Richieste per stato** – numero e volume delle richieste aperte, con offerta, vinte e perse.
- **Articoli richiesti** – gli articoli del magazzino richiesti più spesso, con quantità e importo.
- **Vinte per cliente** – il volume delle richieste vinte per cliente.

## Suggerimenti

- Un clic su una riga mostra sotto i dati collegati: richieste e ordini di un cliente, righe di una richiesta,
  richieste di un evento.
- Gli articoli si modificano nell’app **Magazzino**. Gli articoli nuovi o modificati sono subito disponibili nelle
  vendite.
- Il **modello dei dati** mostra come sono collegate le tabelle – anche il collegamento agli articoli del magazzino.
- L’**assistente di aiuto** (pulsante **?** in alto a destra) risponde alle domande sull’uso – anche su questo manuale.
