# SAFT XT – Domande frequenti

[Deutsch](FAQ.de.md) · [English](FAQ.md) · [Français](FAQ.fr.md) · **Italiano**

**Stato:** versione 0.1 · **Destinatari:** tutti

**Altre guide:** [Guida utente](USER_GUIDE.it.md) · [Guida all’amministrazione](ADMIN_GUIDE.it.md) · [Amministrazione di sistema](SYSADMIN_GUIDE.md)

---

## Indice

- [Domande frequenti](#domande-frequenti)
   - [Non vedo una tabella nel menu.](#non-vedo-una-tabella-nel-menu)
   - [Sotto un ordine non compaiono righe.](#sotto-un-ordine-non-compaiono-righe)
   - [Non riesco a eliminare un record.](#non-riesco-a-eliminare-un-record)
   - [Non riesco a cambiare il tipo di una colonna.](#non-riesco-a-cambiare-il-tipo-di-una-colonna)
   - [Ho usato «Hai smarrito la password?», ma non ho ricevuto alcuna e-mail.](#ho-usato-hai-smarrito-la-password-ma-non-ho-ricevuto-alcuna-e-mail)
   - [Ho perso il telefono con l’app di autenticazione.](#ho-perso-il-telefono-con-lapp-di-autenticazione)
   - [In quale lingua compare l’applicazione?](#in-quale-lingua-compare-lapplicazione)
   - [Ho scelto l’italiano, ma una voce di menu o una tabella resta in tedesco.](#ho-scelto-litaliano-ma-una-voce-di-menu-o-una-tabella-resta-in-tedesco)
   - [Non vedo il pulsante «Esporta».](#non-vedo-il-pulsante-esporta)
   - [In una tabella mancano «Nuovo record» e «Modifica».](#in-una-tabella-mancano-nuovo-record-e-modifica)
   - [Come posso inviare a qualcuno una vista precisa?](#come-posso-inviare-a-qualcuno-una-vista-precisa)
   - [Un file non si riesce a caricare.](#un-file-non-si-riesce-a-caricare)
   - [In un collegamento manca una tabella nella scelta.](#in-un-collegamento-manca-una-tabella-nella-scelta)
   - [Un’app è scomparsa dal selettore.](#unapp-è-scomparsa-dal-selettore)
   - [Non riesco a rimuovere un documento da un campo file.](#non-riesco-a-rimuovere-un-documento-da-un-campo-file)
   - [In un elenco mancano le caselle di spunta per selezionare.](#in-un-elenco-mancano-le-caselle-di-spunta-per-selezionare)
   - [Non è stato possibile inviare un documento per e-mail.](#non-è-stato-possibile-inviare-un-documento-per-e-mail)
   - [Manca la fattura QR o l’app bancaria non la legge.](#manca-la-fattura-qr-o-lapp-bancaria-non-la-legge)
   - [Il file Word appare diverso dal PDF.](#il-file-word-appare-diverso-dal-pdf)
   - [Al posto del file compare una pagina «Lavoro» con barra di avanzamento.](#al-posto-del-file-compare-una-pagina-lavoro-con-barra-di-avanzamento)
   - [L’importazione di un file grande richiede molto tempo.](#limportazione-di-un-file-grande-richiede-molto-tempo)
   - [Come ricevono le fatture un numero progressivo?](#come-ricevono-le-fatture-un-numero-progressivo)

---
## Domande frequenti

### Non vedo una tabella nel menu.

Non avete il diritto di lettura, non esiste una voce di menu oppure il vostro ruolo nasconde la voce. Rivolgetevi
all’amministrazione (Ruoli → Diritti sui dati o voci di menu, oppure Menu). Se la tabella appartiene a un’altra **app**, passate prima a
quell’app con il selettore in alto.

### Sotto un ordine non compaiono righe.

Per la tabella non sono impostati dettagli (Designer di tabelle → Dettagli) oppure vi manca il diritto di lettura sulle
righe.

### Non riesco a eliminare un record.

Altri record vi fanno ancora riferimento (vedere il messaggio). Eliminate prima quelli.

### Non riesco a cambiare il tipo di una colonna.

Il cambio modificherebbe dati esistenti. È possibile solo con la colonna vuota o per cambi di tipo senza perdita di
dati (vedere [Guida all’amministrazione, capitolo 4](ADMIN_GUIDE.it.md#4-designer-di-tabelle)).

### Ho usato «Hai smarrito la password?», ma non ho ricevuto alcuna e-mail.

La conferma compare sempre, anche se per quell’indirizzo non esiste alcun account. Controllate come è scritto
l’indirizzo e la cartella dello spam, e attendete un momento prima di riprovare, perché le richieste ripetute vengono
bloccate. Se non basta, rivolgetevi all’amministrazione.

### Ho perso il telefono con l’app di autenticazione.

Accedete con uno dei vostri **codici di recupero**: dopo la password cliccate su **«Utilizza un codice di
recupero»**. Ogni codice vale una sola volta. Configurate poi l’app sul nuovo telefono in *Le mie impostazioni*. Se non
avete più nemmeno i codici, l’amministrazione reimposta il secondo fattore; da quel momento basta la password finché
non avrete configurato di nuovo.

### In quale lingua compare l’applicazione?

Vale quest’ordine: la scelta salvata nell’account, la scelta fatta in questa sessione o su questo dispositivo, la
lingua del browser e infine la lingua predefinita dall’amministrazione.

### Ho scelto l’italiano, ma una voce di menu o una tabella resta in tedesco.

Per questa voce non è stata inserita una denominazione italiana; compare allora quella tedesca. L’amministrazione può
aggiungerla in Menu o nel Designer di tabelle. I dati, come i nomi delle aziende, non vengono mai tradotti.

### Non vedo il pulsante «Esporta».

L’esportazione non è consentita per questa tabella (Designer di tabelle → Consenti esportazione) oppure al vostro
ruolo manca il diritto (Ruoli → Diritti sui dati → Esporta).

### In una tabella mancano «Nuovo record» e «Modifica».

Si tratta di una tabella collegata di un database esterno (Connettore di tabelle) per la quale la scrittura non è
abilitata (Connettore di tabelle → Scrittura), oppure al vostro ruolo manca il diritto (Ruoli → Diritti sui dati).

### Come posso inviare a qualcuno una vista precisa?

Copiate l’indirizzo dal browser: contiene la tabella, la scelta delle colonne e i record selezionati. Il destinatario
vede solo ciò per cui ha i diritti.

### Un file non si riesce a caricare.

Il campo ammette solo determinati tipi di file, una dimensione e un numero massimi (Progettazione tabelle → campo →
File consentiti, Dimensione massima per file). Viene controllato il contenuto: un file rinominato con un’estensione errata
viene rifiutato. Se falliscono anche piccoli file ammessi, il limite del server è troppo basso (amministrazione di
sistema: `upload_max_filesize` e `post_max_size`).

### In un collegamento manca una tabella nella scelta.

I collegamenti esistono tra tabelle della stessa app; le tabelle di un’altra app compaiono solo se quell’app le ha
**condivise come interfaccia** – allora con il nome dell’app tra parentesi, e come campi visualizzati si offrono solo i
campi condivisi (guida per amministratori, capitolo 15). Se la tabella manca, chiedete all’amministrazione di
condividerla – oppure create la nuova tabella in quell’app.

### Un’app è scomparsa dal selettore.

L’amministrazione l’ha disattivata – ad es. per lavori o prima di rimuoverla. I suoi dati restano; non appena viene
riattivata, ricompare come prima. Chiedete all’amministrazione.

### Non riesco a rimuovere un documento da un campo file.

I documenti che SAFT ha creato da un modello e depositato presso il record (p. es. una fattura) sono protetti da
scrittura: può rimuoverli solo chi può cancellare i record della tabella. Così resta tracciabile quale documento è
stato inviato. Create semplicemente di nuovo un documento corretto – appare come nuova versione accanto (manuale
utente, sezione 4.10). Se un documento deve davvero sparire, chiedete a qualcuno con il diritto di cancellazione.

### In un elenco mancano le caselle di spunta per selezionare.

Le caselle di spunta per selezionare più record appaiono solo se esiste un’azione di gruppo che potete usare – oggi
**«Crea documenti»**: la tabella deve avere un modello di documento attivo e il vostro ruolo il diritto *Documenti:
generare* (manuale utente, sezione 4.10). I record eliminati nel cestino non si possono selezionare.

### Non è stato possibile inviare un documento per e-mail.

- **Nessun interruttore «Inviare per e-mail»:** il modello non prevede un’e-mail, o al vostro ruolo manca il diritto
  *Documenti: inviare*.
- **«A» è vuoto o «nessun indirizzo e-mail valido»:** il record o il cliente collegato non ha un indirizzo – oppure non
  potete leggere la tabella collegata. Inserite l’indirizzo a mano.
- **«Il server di posta rifiuta l’indirizzo mittente …»** o un altro messaggio del server: è un’impostazione
  dell’amministrazione (invio di e-mail nelle impostazioni globali). Il documento è stato comunque creato e – se
  previsto – depositato.

### Manca la fattura QR o l’app bancaria non la legge.

- **«La fattura QR non è valida» o «mancano nella carta intestata»:** nella carta intestata mancano dati del
  beneficiario (NPA, località, IBAN) o l’IBAN è errato – l’amministrazione corregge la carta intestata (manuale
  d’amministrazione, capitolo 16, «Fattura QR»).
- **«L’importo della fattura QR non è un numero»:** il record non ha un importo o non è un numero.
- **L’app bancaria non riconosce il codice:** mostrare il PDF a grandezza originale sullo schermo o stamparlo (senza
  riduzione, «adatta alla pagina» disattivato). Il codice deve misurare 46 × 46 mm.

### Il file Word appare diverso dal PDF.

La versione Word serve per la rielaborazione, non sostituisce il PDF: una carta intestata come sfondo PDF, un logo SVG
e la fattura QR esistono solo nel PDF; Word mostra invece il logo (PNG/JPEG) e il mittente come testo nell’intestazione
e usa il carattere Arial. Per l’invio e il deposito SAFT usa sempre il PDF.

### Al posto del file compare una pagina «Lavoro» con barra di avanzamento.

Esportazione, importazione, «Crea documenti» per molti record e stati dei dati vengono eseguiti come **lavoro** a
passi. Le piccole quantità sono pronte prima che ve ne accorgiate e il file arriva come sempre. Se dura di più, SAFT
mostra la pagina del lavoro: lasciarla aperta è il modo più rapido; potete però anche chiuderla – il lavoro continua in
background, la campanella segnala la fine e il file resta scaricabile per 24 ore sotto **I miei lavori** (menu
utente).

### L’importazione di un file grande richiede molto tempo.

Ogni record viene creato come a mano – con valori predefiniti, campi calcolati, somme nei record superiori e regole dei
plugin. Ciò costa qualche millisecondo per record; con decine di migliaia di righe diventano minuti. L’avanzamento
mostra prima **verifica**, poi **importazione**. Potete lasciare la pagina; in caso di errori o annullamento non viene
creato nulla oppure tutto ciò che è stato creato viene rimosso.

### Come ricevono le fatture un numero progressivo?

Con un campo di tipo **Intervallo di numerazione**, p. es. con il modello `R-{YYYY}-{0000}` e ripartenza annuale. SAFT
assegna il numero alla creazione, mai due volte e non di nuovo dopo l’eliminazione; in seguito non può essere
modificato. Per questo il modulo mostra solo «assegnato al salvataggio». Si configura nel designer di tabelle, vedere
[Manuale dell’amministratore, Intervalli di numerazione](ADMIN_GUIDE.it.md#intervalli-di-numerazione).
