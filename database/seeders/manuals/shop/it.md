# Negozio

## Panoramica

L’app **Shop** (Negozio) mostra, con un piccolo negozio online, come i dati sono collegati in SAFT: i clienti ordinano
articoli, ogni ordine è composto da righe d’ordine. Tutti gli importi sono in euro.

| Tabella | Contenuto |
|---|---|
| Articoli | codice articolo, denominazione, categoria, prezzo, giacenza, attivo sì/no |
| Clienti | numero cliente, azienda, referente, e-mail, località, paese, cliente dal |
| Ordini | numero d’ordine, cliente, data dell’ordine, stato, data di consegna, totale e IVA |
| Righe d’ordine | ordine, posizione, articolo, quantità, prezzo unitario, importo |

## Operazioni tipiche

### Registrare un nuovo cliente

Aprite **Clienti** e cliccate su **«Nuovo record»**. Numero cliente e azienda sono obbligatori; il numero cliente può
comparire una sola volta. Al salvataggio viene controllato il formato dell’indirizzo e-mail.

### Registrare un ordine

1. Selezionate il cliente in **Clienti** – sotto compaiono i suoi ordini.
2. Create lì l’ordine con **«Nuovo record»**. Il cliente è già inserito; come stato scegliete prima *Nuovo*.
3. Selezionate il nuovo ordine e inserite sotto le **righe d’ordine**: bastano articolo e quantità. Il **prezzo
   unitario** viene ripreso dall’articolo e si può modificare se necessario (ad es. uno sconto).

Al salvataggio SAFT calcola da sé l’**importo** di ogni riga (quantità × prezzo unitario) nonché il **totale** e
l’**IVA 8,1 %** dell’ordine.

### Spedire un ordine

Impostate lo **stato** su *Spedito* e inserite la **data di consegna**. La data di consegna non può precedere la data
dell’ordine – altrimenti SAFT rifiuta il salvataggio con un avviso al campo. Gli ordini annullati ricevono lo stato
*Annullato*; restano disponibili per i report.

### Gestire gli articoli

Modificate prezzi e giacenza direttamente nell’articolo. Una modifica di prezzo vale solo per le nuove righe d’ordine;
quelle esistenti mantengono il loro prezzo unitario. Gli articoli non più in vendita impostateli su *non attivo* invece
di eliminarli – un articolo presente in righe d’ordine non si può comunque eliminare.

## Report

In **Report**, **«Umsatz je Monat und Kategorie»** (fatturato per mese e categoria) mostra gli importi delle righe
d’ordine per mese e categoria d’articolo. Potete creare report propri, ad es. fatturato per cliente o per paese; la
tabella di base per il fatturato è **Righe d’ordine**.

## Consigli

- Un clic su una riga la seleziona e mostra sotto i dati collegati: per un cliente gli ordini, per un ordine le righe,
  per un articolo gli ordini in cui compare.
- **Esporta** salva l’elenco attuale come file Excel o CSV, con i vostri filtri e colonne.
- L’**assistente di guida** (pulsante **?** in alto a destra) risponde alle domande sull’uso – anche su questo manuale.
