# Negozio di chitarre

## Panoramica

L’app **Negozio di chitarre** è una semplice contabilità di entrate e uscite con IVA per un piccolo commercio di pezzi
unici: strumenti, accessori, servizi e altro. Tutti gli importi sono in franchi svizzeri (CHF).

| Tabella | Contenuto |
|---|---|
| Clienti | Numero cliente, nome, cognome o ditta, indirizzo, paese, e-mail, newsletter sì/no, telefono |
| Fornitori | Nome, paese, località, e-mail, telefono, sito web, nostro numero cliente presso il fornitore |
| Articoli | Numero d’inventario, denominazione, categoria, stato, descrizione, fino a 10 immagini; calcolati: costi, ricavo, risultato |
| Giustificativi | Ricevute d’acquisto e fatture: numero, data, tipo (entrata/uscita), cliente o fornitore, file (PDF o immagine); calcolati: totale, pagato, aperto |
| Registrazioni | Righe di un giustificativo: articolo, genere, testo, netto, aliquota IVA; calcolati: IVA, lordo, entrata, uscita, saldo |
| Pagamenti | Pagamenti di un giustificativo con data, importo, modalità di pagamento e scontrino |

## Attività tipiche

### Acquistare merce all’estero

1. In **Contabilità › Giustificativi** creare un giustificativo di tipo *Uscita*, scegliere il **fornitore** e
   allegarne la fattura in PDF. I nuovi fornitori si registrano in **Dati di base › Fornitori**.
2. Sotto, registrare una **registrazione** per articolo con il genere *Valore merce* (aliquota IVA 0 %, acquisto
   all’estero).
3. All’arrivo della merce, creare un secondo giustificativo per la dogana: per articolo una registrazione *Dogana* e
   una *IVA all’importazione* (importo secondo la quietanza doganale, aliquota 0 %).
4. Registrare il pagamento in **Pagamenti** – sotto il giustificativo o nella tabella stessa.

### Vendere

Creare un giustificativo di tipo *Entrata* con il **cliente** (nuovi clienti in **Dati di base › Clienti**) e una
registrazione del genere **Vendita**, il prezzo netto e l’aliquota
IVA (di solito 8,1 %). SAFT calcola IVA e lordo. Impostare lo stato dell’articolo su *Venduto*.

### Pagamenti parziali e rateali

Registrare per un giustificativo quanti pagamenti servono, ognuno con il proprio scontrino. Sul giustificativo,
**pagato** e **aperto** mostrano cosa manca ancora.

### Copiare articoli

Per registrare uno strumento simile si usa **Copia** nella riga dell’articolo: tutti i dati e le immagini vengono
ripresi, il numero d’inventario resta vuoto. Copiando un giustificativo si copiano anche le registrazioni, non i
pagamenti.

## Importante: entrata o uscita

Se una registrazione è un’entrata o un’uscita dipende dal suo **genere**: *Vendita* è un’entrata, tutti gli altri
generi (valore merce, dogana, IVA all’importazione, servizio, altro) sono uscite. Il tipo del giustificativo serve alla
panoramica.

## Report

- **Entrate e uscite per anno** – con il saldo (utile o perdita) per anno della data del giustificativo.
- **Saldo complessivo** – un indicatore su tutte le registrazioni; positivo = utile, negativo = perdita.
- **Tutte le registrazioni** – le più recenti prima, con giustificativo, articolo, genere e importi.
- **Entrate e uscite per articolo** – costi, ricavo e risultato per articolo; le registrazioni senza articolo (ad es.
  trasporto) compaiono in una riga a parte, senza articolo.

## Suggerimenti

- Su un articolo, **Costi**, **Ricavo** e **Risultato** mostrano subito quanto un pezzo è costato e ha reso; le sue
  registrazioni sono elencate sotto.
- In **Clienti** la spunta **Newsletter** indica chi desidera ricevere e-mail; filtrando su di essa ed esportando si
  ottiene l’elenco degli indirizzi.
- Un cliente o fornitore mostra sotto i suoi giustificativi.
- Il ruolo **Gitarrenshop** ha tutti i diritti su questa app – adatto a chi gestisce il negozio.
- L’**assistente di aiuto** (pulsante **?** in alto a destra) risponde alle domande sull’uso – anche su questo manuale.
