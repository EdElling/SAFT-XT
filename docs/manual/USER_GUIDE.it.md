# SAFT XT – Guida utente

[Deutsch](USER_GUIDE.de.md) · [English](USER_GUIDE.md) · [Français](USER_GUIDE.fr.md) · **Italiano**

**Stato:** versione 0.1 · **Destinatari:** utenti

**Altre guide:** [Guida all’amministrazione](ADMIN_GUIDE.it.md) · [Domande frequenti](FAQ.it.md) · [Amministrazione di sistema](SYSADMIN_GUIDE.md)

> **Sulle illustrazioni:** le immagini sono schermate dell’applicazione con i dati dimostrativi.
> I numeri rossi nelle immagini rimandano alle spiegazioni nel testo.

---

## Indice

- [1. Concetti di base](#1-concetti-di-base)
- [2. Accedere e uscire](#2-accedere-e-uscire)
- [3. Interfaccia e dashboard](#3-interfaccia-e-dashboard)
- [4. Lavorare con i dati](#4-lavorare-con-i-dati)
   - [4.1 La vista tabella](#41-la-vista-tabella)
   - [4.2 Visualizzare, creare, modificare ed eliminare record](#42-visualizzare-creare-modificare-ed-eliminare-record)
   - [4.3 Tabelle collegate: la vista master-detail](#43-tabelle-collegate-la-vista-master-detail)
   - [4.4 Come la vista master-detail decide cosa mostrare](#44-come-la-vista-master-detail-decide-cosa-mostrare)
   - [4.5 Dare un’occhiata a un record collegato (anteprima)](#45-dare-unocchiata-a-un-record-collegato-anteprima)
   - [4.6 Regole di controllo all’inserimento](#46-regole-di-controllo-allinserimento)
   - [4.7 Esportare i dati](#47-esportare-i-dati)
   - [4.8 Importare i dati](#48-importare-i-dati)
   - [4.9 Report](#49-report)
   - [4.10 Creare documenti](#410-creare-documenti)
   - [4.11 Modificare più record (modifica multipla)](#411-modificare-più-record-modifica-multipla)
- [Allegato A – Tipi di campo](#allegato-a--tipi-di-campo)
- [Allegato B – Glossario](#allegato-b--glossario)

---
## 1. Concetti di base

| Concetto | Significato |
|---|---|
| **Tabella** | Un insieme di dati dello stesso tipo, ad es. *Clienti* o *Articoli*. Le tabelle vengono create dagli amministratori nel **Designer di tabelle**. |
| **Record** | Una riga di una tabella, ad es. un singolo cliente. |
| **Campo / colonna** | Una proprietà di un record, ad es. *Ditta* o *Località*. |
| **Collegamento** | Un campo che punta a un record di un’altra tabella, ad es. il campo *Cliente* in un ordine. |
| **Dettagli** | I record che *appartengono* a un record tramite un collegamento, ad es. tutti gli ordini di un cliente («1:n»). |
| **Ruolo** | Un gruppo di diritti assegnato agli utenti, ad es. *Vendite*. |
| **Diritto (permission)** | Il permesso per un’azione precisa, ad es. «Dati: Clienti: modificare». |

---

## 2. Accedere e uscire

![Pagina di accesso](images/it/01-login.png)

A seconda delle impostazioni dell’amministrazione, nella pagina di accesso trovate:

1. **Pulsanti di accesso per il single sign-on**, ad es. «Accedi con l’account aziendale». Venite reindirizzati al
   vostro account aziendale (Microsoft o un altro portale di accesso) e tornate poi già connessi.
2. **E-mail e password**: l’accesso classico con un account di questa applicazione. Maiuscole e minuscole
   dell’indirizzo e-mail non contano.
3. **«Hai smarrito la password?»**: visibile solo se l’amministrazione ha abilitato questa funzione. Per motivi di
   sicurezza la risposta è sempre la stessa, anche se per l’indirizzo non esiste alcun account. La conferma quindi non
   significa che sia stata inviata un’e-mail.

Le varianti offerte dipendono dalla **Modalità di accesso** (vedere [Guida all’amministrazione, capitolo 5](ADMIN_GUIDE.it.md#5-autenticazione)):
solo password, solo single sign-on oppure entrambi.

**Lingua:** con l’icona della lingua potete scegliere la lingua già nella pagina di accesso. Senza una scelta, la
pagina segue la lingua del browser. Dopo l’accesso vale la lingua salvata nel vostro account (vedere
[3](#3-interfaccia-e-dashboard)); dopo l’uscita il browser ricorda l’ultima scelta.

**Uscire:** dal menu utente in alto a destra. A seconda delle impostazioni, uscite contemporaneamente anche
dall’account aziendale.

> **Buono a sapersi:** se l’accesso tramite single sign-on vi viene negato («Per il vostro account non è configurato
> alcun accesso» o simile), rivolgetevi all’amministrazione: il vostro account non è ancora abilitato o non è
> assegnato a nessun gruppo autorizzato.

---

## 3. Interfaccia e dashboard

![Dashboard](images/it/02-dashboard.png)

- **Lingua:** con l’icona della lingua in alto a destra, accanto alla ricerca, scegliete **Deutsch**, **English**,
  **Français** o **Italiano**, già nella pagina di accesso. La scelta vale subito e viene salvata con il vostro
  account, quindi anche su altri dispositivi. Tutti i testi dell’interfaccia, i messaggi, le date e i numeri seguono la
  lingua. Le voci di menu, i nomi delle tabelle e dei campi e la visualizzazione dei valori di selezione possono essere
  tradotti dall’amministrazione per ogni lingua (vedere [Guida all’amministrazione, capitolo 3](ADMIN_GUIDE.it.md#3-menu)
  e [Guida all’amministrazione, capitolo 4](ADMIN_GUIDE.it.md#4-designer-di-tabelle)); se manca una traduzione, compare quella
  tedesca. I record e i nomi dei ruoli restano come sono stati inseriti.
- **Le mie impostazioni:** nel menu utente (1, le vostre iniziali in alto a destra) trovate **Le mie impostazioni**.
  Lì attivate o disattivate il **Contrasto elevato** (2) per il vostro account; la scelta vale poi su tutti i
  dispositivi. **Automatico** (predefinito) usa il contrasto elevato quando nel sistema operativo è impostato «Aumenta
  contrasto». Contrasto elevato significa testo più scuro (più chiaro in modalità scura, almeno 7:1 secondo WCAG AAA),
  bordi marcati, un focus da tastiera ben visibile, link sottolineati e una riga selezionata incorniciata. Chiaro,
  scuro o sistema si scelgono nello stesso menu; la scelta viene salvata con il vostro account e vale su tutti i
  dispositivi – chi accede poi nello stesso browser ottiene la propria impostazione. Sempre lì: il **formato dei
  numeri** – **Automatico** (predefinito: formato svizzero se la lingua preferita del browser è impostata sulla
  Svizzera, ad es. «italiano (Svizzera)»), **Come la lingua** o **Svizzera** (1'234.50, in francese 1 234,50). Vale per
  elenchi, dettagli, report ed esportazione CSV; le formule scrivono sempre i decimali con il punto.

  ![Le mie impostazioni](images/it/17-my-settings.png)

- **Cambia password:** sempre nel menu utente. Inserite la password attuale e due volte quella nuova; restate
  connessi. La voce manca se il vostro account è collegato tramite single sign-on (la password è allora gestita
  dalla vostra azienda) o se l’accesso con password è disattivato.
  La nuova password richiede almeno 12 caratteri; SAFT non accetta una password già comparsa in una fuga di dati
  nota. Consiglio: più parole che solo voi mettereste insieme sono lunghe e facili da ricordare.
- **Autenticazione a due fattori:** in *Le mie impostazioni* sotto **Autenticazione a due fattori › Configurazione**.
  Vi serve un’app di autenticazione sul telefono (ad es. Microsoft Authenticator, Google Authenticator o 2FAS).
  Scansionate il codice QR e confermate con il codice a 6 cifre dell’app e la vostra password. SAFT mostra poi una sola
  volta **8 codici di recupero** – stampateli o conservateli in un luogo sicuro. Da quel momento SAFT chiede dopo la
  password il codice attuale dell’app. Senza telefono accedete con **«Utilizza un codice di recupero»**; ogni codice
  vale una sola volta. Se l’autenticazione a due fattori è obbligatoria per il vostro account, SAFT vi porta
  direttamente alla configurazione dopo l’accesso. Non vale per l’accesso tramite single sign-on – lì è responsabile la
  vostra azienda. Telefono e codici persi: l’amministrazione reimposta il secondo fattore.
- **I miei lavori:** SAFT esegue i lavori lunghi – esportazione, importazione, documenti per molti record, stati dei
  dati, progettista di app IA – a passi. Le piccole quantità sono pronte prima che ve ne accorgiate: il file arriva come
  sempre in download. Se dura di più, SAFT vi porta alla pagina del lavoro. Nel menu utente, **I miei lavori** mostra
  ciò che avete avviato, con stato e avanzamento. Aprendo un lavoro vedete una barra di avanzamento; finché la pagina è
  aperta, SAFT procede a piena velocità. Potete anche chiudere la pagina: il lavoro continua in background e la
  campanella vi avvisa quando è terminato o non è riuscito. **Annulla** lo termina dopo il passo in corso. Se un lavoro
  produce un file, scaricatelo con **Scarica il risultato** – resta disponibile 24 ore, anche se l’avete già scaricato
  direttamente.

- **A sinistra** si trova il **menu**. Mostra solo le aree per le quali avete diritti. Sotto **Dati** trovate le
  tabelle, sotto **Amministrazione** le funzioni di gestione. Le voci con una freccia
  **›** a destra sono sottomenu (ad es. *Amministrazione › Utenti e accesso*): un clic li apre e li chiude come i gruppi principali; aperta, la freccia punta verso il basso. Il browser ricorda lo
  stato; il sottomenu con la pagina attuale è aperto. Se una tale voce ha una propria pagina, un clic la apre. Con la freccia **‹** accanto al nome dell’applicazione
  nascondete del tutto il menu per avere più spazio per le tabelle; il pulsante **☰** nello stesso punto lo fa
  ricomparire. Il browser ricorda la scelta. Il menu è largo quanto la sua voce più lunga.
- **Documentazione** (nel menu, subito sotto la dashboard) mostra questa guida e le domande frequenti
  nell’applicazione, con indice e un campo di ricerca che cerca in tutte le guide durante la digitazione. Chi ha un
  diritto nell’area Amministrazione vede anche la guida all’amministrazione. Se dei plugin forniscono propri manuali (ad
  es. *Trasferimento di magazzino*), compaiono come schede aggiuntive quando puoi usare quelle funzioni. **Informazioni su SAFT XT** mostra il logo SAFT e indica la
  versione con il suo stato (ad es. *pre-release*), rimanda alla pagina *Releases* su GitHub per gli aggiornamenti e
  mostra le condizioni di licenza (con un link al sito del PolyForm Project) e i componenti di terze parti con le relative licenze.
- **Assistente di aiuto:** se è configurato e hai il diritto, il pulsante **?** in alto a destra apre una finestra in
  cui fai domande sull’uso, ad es. «Come esporto questa tabella?». La risposta si basa sui manuali che puoi vedere e
  rimanda alle sezioni corrispondenti; l’assistente sa in quale pagina ti trovi. All’apertura nel campo c’è già «Descrivi la schermata attuale» – basta
  inviare, oppure scriverci sopra. Le stesse conversazioni si trovano in
  **Documentazione** nella scheda **Assistente IA**, dove riapri, prosegui o elimini le conversazioni precedenti. Le
  risposte dell’IA possono contenere errori – fanno fede i manuali.
- La **dashboard** è la pagina iniziale. I riquadri (widget) sono disposti su due colonne che crescono verso il basso
  in modo indipendente; i riquadri larghi seguono sotto, a tutta larghezza. Quali riquadri vedete lo stabilisce
  l’amministrazione tramite il vostro ruolo; possono aggiungersi riquadri dei plugin, p. es. *Magazzino: scorta minima* – portano sotto l’etichetta «Plugin: …», come le pagine dei plugin sotto il titolo; i riquadri di un plugin compaiono solo nell’app a cui appartiene. Potete **disporli** voi stessi: **«Personalizza dashboard»** (in alto a
  destra) mostra su ogni riquadro una maniglia e **«Nascondi»**. Con la maniglia trascinate un riquadro all’interno di
  una colonna, nell’altra colonna o – solo i report – nell’area larga in basso, dove occupa tutta la larghezza. I
  riquadri nascosti compaiono in alto in un elenco, da cui si possono rendere di nuovo visibili. La disposizione viene
  salvata subito e vale anche su altri dispositivi; **«Ripristina predefinito»** la reimposta, **«Fatto»** conclude la
  personalizzazione.

| Widget | Contenuto | Per chi |
|---|---|---|
| Il mio ultimo accesso | Momento, metodo e IP del vostro accesso **precedente**, oltre ai tentativi di accesso falliti da allora | tutti |
| I miei accessi | I vostri ultimi accessi e uscite | tutti |
| Le mie ultime modifiche | Ciò che avete modificato di recente | tutti |
| Modifiche al mio account | Ciò che altri hanno modificato nel vostro account, ad es. i vostri ruoli | tutti |
| Report | un riquadro per ogni report fissato (vedere [4.9](#49-report)); grafici e indicatori inizialmente a mezza larghezza, tabelle a tutta larghezza | tutti con il diritto «Report: visualizzare» |
| Attività di accesso | Accessi degli ultimi 7 giorni, tentativi falliti, utenti connessi | admin |
| Panoramica utenti | utenti attivi, inattivi, senza ruolo, con SSO | admin |
| Stato dei provider di identità | stato delle connessioni single sign-on, certificati in scadenza | admin |
| Attività pianificate | semaforo (tutto in ordine, attività fallite, lo scheduler non è in esecuzione, lavori bloccati o falliti) e le ultime esecuzioni | admin |
| Ultime modifiche (intero sistema) | le voci più recenti del registro modifiche | admin |

> **Consiglio di sicurezza:** se «Il mio ultimo accesso» mostra un momento in cui non avete effettuato l’accesso, o
> tentativi falliti, informate l’amministrazione.

---

### Cambiare app

Se lavorate con più **app** (applicazioni specialistiche, ad es. «Negozio» e «Magazzino»), a sinistra della ricerca
compare un **selettore** con il nome dell’app attuale. Elenca le app a cui avete accesso; un clic cambia app e ne apre
la dashboard. La scelta resta anche dopo la disconnessione.

L’app attuale determina ciò che vedete: i menu di quell’app (più dashboard, documentazione, report e – secondo i
diritti – l’amministrazione), la disposizione della dashboard, i report e, sotto **Documentazione**, il manuale
dell’app se esiste. Se aprite una tabella o un report di un’altra app tramite un link o un segnalibro, SAFT vi passa da
sé. Con accesso a una sola app il selettore non compare. Un’app disattivata dall’amministrazione non viene proposta.

## 4. Lavorare con i dati

### 4.1 La vista tabella

Nel menu aprite una tabella sotto **Dati**, ad es. **Clienti**.

![Vista tabella](images/it/03-data-table.png)

1. **Ricerca**: cerca nei campi configurati come «ricercabili».
2. **Filtri**: per ogni colonna esiste un filtro adatto:
   - testo: «contiene …»
   - numero e data: «da … a …»
   - sì/no, elenchi di selezione e collegamenti: scelta da un elenco
   - «Record eliminati»: solo per gli utenti con diritto di eliminazione
3. **Ordinare**: un clic sull’intestazione di una colonna ordina in modo crescente o decrescente. Ogni colonna è
   ordinabile. Gli elenchi di selezione vengono ordinati secondo il valore salvato, non secondo l’etichetta mostrata.
4. **Modifica, Elimina, …**: alla fine della riga (vedere [4.2](#42-visualizzare-creare-modificare-ed-eliminare-record));
   sullo smartphone **Dettagli**.

In basso scegliete quanti record mostrare per pagina (10, 25, 50 o 100) e passate da una pagina all’altra con i
numeri di pagina e le frecce (sugli schermi stretti con «Precedente» e «Successivo»). Date e numeri seguono la lingua
scelta: in italiano ad es. `01/02/2026 14:30`, in tedesco `01.02.2026 14:30` e `1.234,50`, in inglese
`01/02/2026 14:30` e `1,234.50`. Con il formato dei numeri **Svizzera** (vedere [capitolo 3](#3-interfaccia-e-dashboard))
i numeri compaiono come `1'234.50`.

**Larghezza:** una tabella è larga quanto le sue colonne. Con poche colonne resta spazio libero a destra, e
i pulsanti della riga si trovano subito accanto all’ultima colonna. Se le colonne non entrano nella finestra, la tabella è larga
quanto la finestra e si può scorrere orizzontalmente. Le anteprime brevi e le tabelle di dettaglio della vista
master-detail si comportano allo stesso modo.

> **Quali colonne vedo?** La preimpostazione è stabilita dalla voce di menu con cui avete aperto la tabella. Possono
> quindi esistere ad es. «Ordini» e «Ordini (breve)» con colonne diverse. I **campi obbligatori** vengono sempre
> mostrati.
>
> Con l’**icona delle colonne** a destra del filtro mostrate altri campi o nascondete quelli previsti e confermate con
> «Applica colonne». SAFT ricorda la vostra scelta **in modo permanente e personale** – per tabella e voce di menu,
> anche dopo l’uscita e su un altro computer. L’**ordine** si cambia nello stesso menu trascinando la maniglia di una colonna; anch’esso viene ricordato.
> «Reimposta» ripristina la preimpostazione. Icona delle colonne e ordine esistono anche nelle tabelle di dettaglio della
> vista master-detail, l’icona delle colonne anche negli elenchi dell’amministrazione.

SAFT memorizza anche **filtri, ricerca, ordinamento e righe per pagina** delle viste dati **in modo permanente e
personale** – per tabella e voce di menu, anche dopo la disconnessione. Riaprendo una tabella la ritrovate come l’avete
lasciata. Se la vista differisce dal predefinito, sopra la tabella compare il pulsante **Reimposta vista**: riporta
filtri, ricerca, ordinamento, righe per pagina e scelta delle colonne ai valori predefiniti. Negli elenchi
dell’amministrazione filtri, ricerca e ordinamento valgono solo fino alla disconnessione. I **numeri** sono allineati a
destra in tutte le tabelle.

### 4.2 Visualizzare, creare, modificare ed eliminare record

Le tabelle servono alla **lettura**. Un clic su una riga seleziona il record (vedere
[4.3](#43-tabelle-collegate-la-vista-master-detail)). La modifica avviene con i pulsanti alla fine della riga – a seconda della larghezza dello schermo:

**Sul desktop** (da circa la larghezza di un tablet) ci sono icone; il tooltip indica la funzione:

| Icona | Funzione |
|---|---|
| **Visualizza** (lente) | Il modulo con tutti i campi, bloccato – anche i campi calcolati. |
| **Modifica** (matita) | Apre il modulo di inserimento in una finestra; **Salva** o **Annulla** riporta direttamente alla tabella. |
| **Copia** (due fogli) | Apre «Nuovo record» con tutti i valori dell’originale; i campi univoci (ad es. codice articolo) restano vuoti, quelli calcolati vengono ricalcolati. File e immagini vengono creati come copie proprie al salvataggio. Se ne fanno parte altri record (ad es. le righe di un ordine), compaiono in alto sotto **Copiare insieme** con una spunta preselezionata – toglierla per escluderli. Richiede il diritto di creare. |
| **Cronologia** (orologio) | Chi ha modificato cosa e quando. |
| **Elimina** (cestino) | Elimina dopo una domanda di sicurezza. |
| **Ripristina** | Per i record eliminati al posto di «Modifica» ed «Elimina». |
| Icone dei plugin | Funzioni dei plugin; il tooltip nomina anche il plugin. |

Senza diritto di modifica mancano «Modifica» ed «Elimina».

**Sullo smartphone** aprite il record con **«Dettagli»** in una finestra che scorre da destra:

![Finestra Dettagli](images/it/05-details-popup.png)

La finestra mostra tutti i campi del record in un elenco – per ogni campo il nome e accanto il valore (lo stesso vale
per l’anteprima dei record collegati). «Ripristina» compare solo per i record eliminati. In basso trovate (1):

| Pulsante | Funzione |
|---|---|
| **Modifica** | Apre il modulo di inserimento. Le modifiche vengono applicate subito dopo il salvataggio. Non disponibile per i record eliminati. |
| **Copia** | Crea un nuovo record con i valori di questo record (come nella tabella). |
| **Cronologia** | Mostra chi ha modificato cosa e quando (ad es. «status: Nuovo → Spedito»). |
| **Elimina** | Contrassegna il record come eliminato. Scompare dall’elenco, ma resta ripristinabile. |
| **Ripristina** | Visibile solo per i record eliminati. |
| **Chiudi** | Chiude la finestra senza modifiche. |

**Nuovo record:** pulsante **«Nuovo record»** in alto a destra nell’elenco.

**Allegare file:** un campo di tipo *File* accetta immagini, PDF o altri documenti. Trascinate i file nel campo o
cliccate su **«sfoglia»**; con più file l’ordine si cambia trascinando, la ✕ rimuove un file. Nulla viene salvato
prima di **Crea** o **Salva**. Quali tipi e quali dimensioni sono ammessi lo stabilisce l’amministrazione per ogni
campo; gli altri file vengono rifiutati già al caricamento. Nell’elenco le immagini appaiono come piccola anteprima e
gli altri file con una graffetta (al massimo tre, poi «+2» ecc.); nei dettagli compaiono tutti. Un clic su un’immagine o un PDF – o su
«+2» – mostra il file in una **finestra di anteprima**: le frecce (anche i tasti freccia) scorrono tutti i file del
campo; in alto si trovano **Apri in una nuova scheda**, **Scarica** e **Chiudi** (anche Esc). Gli altri file vengono
scaricati direttamente. Vede i file solo chi può leggere la tabella. Il filtro
**«con file» / «senza file»** trova i record con o senza allegati.

I pulsanti visibili dipendono dai vostri diritti: con il solo diritto di lettura mancano «Nuovo record», «Modifica» ed
«Elimina».

> **Eliminazione impossibile?** Un record non può essere eliminato finché altri record vi fanno riferimento – ad es.
> un ordine per il quale esistono ancora righe d’ordine. Il messaggio indica il motivo, ad es. «1 × Righe d’ordine
> (tramite «Ordine»)». Eliminate prima i record collegati.

**Vedere i record eliminati:** con il diritto di eliminazione, il filtro «Record eliminati» mostra anche le voci
eliminate, che potete recuperare con **Ripristina** nella riga (smartphone: «Dettagli → Ripristina»). Se nel frattempo un altro record ha lo stesso valore in
un campo univoco, compare il messaggio «Conflitto di dati» e il record resta eliminato.

> **Modificato o eliminato nel frattempo?** Se qualcuno modifica il record mentre lo state modificando, al salvataggio
> SAFT segnala «Modificato nel frattempo» con nome e ora, e la finestra resta aperta con i vostri dati. **Ricarica** la
> chiude e mostra lo stato attuale; **Salva comunque** sovrascrive la modifica altrui con i vostri dati (annotato nella
> cronologia come sovrascrittura voluta). Se il record è stato eliminato, il salvataggio viene rifiutato. Lo stesso vale
> per i moduli dell’amministrazione (ruoli, utenti, menu, tabelle …).

### 4.3 Tabelle collegate: la vista master-detail

Le tabelle sono spesso collegate tra loro: un **cliente** ha **ordini**, un ordine ha **righe d’ordine**. La vista
master-detail mostra queste relazioni **su un’unica pagina, una sotto l’altra**:

![Cascata master-detail](images/it/04-cascade.png)

Funziona così:

1. **Cliccare su una riga**: nell’elenco (qui *Clienti*) cliccate su un record. Viene **evidenziato a colori**.
2. **Anteprima breve**: sotto compaiono i dati del cliente selezionato – i campi le cui colonne sono visibili nell’elenco (anche quelle
   mostrate da voi) e i campi obbligatori –, per ogni campo il nome e accanto il
   valore, in fino a tre blocchi affiancati (numeri allineati a destra, testi lunghi a capo) …
3. **… e i suoi dettagli**: qui la tabella dei suoi **ordini**. Se lì cliccate su un ordine, viene evidenziato anche
   quello …
4. **… e sotto compaiono i suoi dettagli**: qui le **righe d’ordine**.
5. I **valori collegati** (qui i nomi degli articoli) sono cliccabili e mostrano il record collegato in un’anteprima
   (vedere [4.5](#45-dare-unocchiata-a-un-record-collegato-anteprima)).

Altre regole:

- **Annullare la selezione:** cliccate di nuovo sulla riga evidenziata.
- **Scegliere un’altra riga:** tutto ciò che sta sotto viene ricostruito.
- **Eliminare il record selezionato:** la selezione viene annullata, l’anteprima breve e i dettagli sottostanti
  scompaiono subito.
- **Fino a quattro livelli** vengono mostrati uno sotto l’altro (elenco + tre livelli di dettaglio).
- **Più tabelle di dettaglio sullo stesso livello** (ad es. un cliente ha *Ordini* e *Fatture*) compaiono come
  **schede**. L’etichetta indica anche il collegamento, ad es. «Ordini (tramite «Cliente»)». Così anche due
  collegamenti dalla stessa tabella restano distinguibili. Il livello successivo compare sotto la scheda in cui avete
  fatto la selezione. Un’altra scheda carica la sua tabella solo quando la aprite; compare brevemente un indicatore di
  caricamento. Così il clic su un record resta rapido, anche se ha molte tabelle di dettaglio.
- **Nuovo record in una tabella di dettaglio:** il collegamento viene impostato automaticamente – una nuova riga
  d’ordine appartiene sempre all’ordine selezionato. Sotto un record eliminato non si possono creare nuovi record; il
  pulsante lì manca.
- **La modifica** avviene anche qui con i pulsanti della riga interessata (smartphone: **«Dettagli»**).
- **Segnalibri e link:** la vostra selezione compare nella barra degli indirizzi del browser. Ricaricare la pagina o
  un link inoltrato mostrano esattamente la stessa vista. Anche chi lascia la pagina e torna con «Indietro» ritrova la
  propria selezione. «Indietro» non ripercorre però i singoli clic sulle righe: il tasto porta alla pagina
  precedente.

### 4.4 Come la vista master-detail decide cosa mostrare

![Logica della cascata](images/it/10-cascade-logic.svg)

- **Quali dettagli** compaiono sotto una tabella è una **proprietà della tabella** e viene stabilito nel Designer di
  tabelle (vedere [Guida all’amministrazione, capitolo 4](ADMIN_GUIDE.it.md#4-designer-di-tabelle)) – tramite campi di
  collegamento o [collegamenti tra tabelle](ADMIN_GUIDE.it.md#11-collegamenti-tra-tabelle), anche verso tabelle di altri database.
  Per questo sotto un ordine vedete sempre le stesse righe d’ordine – **indipendentemente** dal fatto che siate
  arrivati da «Dati → Ordini» o da «Dati → Clienti» e poi da un ordine.
- **I diritti valgono per tabella:** una tabella di dettaglio compare solo se avete il diritto di **visualizzare**
  quella tabella. Senza diritto di lettura sulle righe d’ordine vedete l’ordine, ma nessuna riga sotto.
- **I record eliminati** compaiono nella cascata solo per gli utenti con diritto di eliminazione.
- **Sicurezza:** la selezione nella barra degli indirizzi viene verificata a ogni richiamo. Se una parte non
  corrisponde (ad es. un ordine che non appartiene al cliente selezionato), la selezione viene interrotta in quel
  punto – non vengono mai mostrati dati estranei.

### 4.5 Dare un’occhiata a un record collegato (anteprima)

I valori collegati – ad es. l’**articolo** in una riga d’ordine o il **cliente** in un ordine – sono cliccabili nelle
tabelle. Un clic apre a destra una finestra con tutti i dati del record collegato, **senza lasciare la pagina
attuale**:

![Anteprima](images/it/06-preview.png)

- **Chiudi** riporta alla vista invariata.
- **«Vai a «Articoli»»** (1) apre la tabella *Articoli* con questo articolo selezionato – compresi i suoi dettagli
  sottostanti.
- Senza diritto di lettura sulla tabella collegata, il valore non è cliccabile.
- Se il record collegato è eliminato, la finestra lo indica chiaramente.

### 4.6 Regole di controllo all’inserimento

Al salvataggio l’applicazione controlla i dati inseriti in base alle impostazioni del Designer di tabelle. Gli errori
vengono mostrati presso il campo interessato:

| Controllo | Esempio di messaggio |
|---|---|
| Campo obbligatorio | «Il campo Titolo è obbligatorio.» |
| Lunghezza / intervallo di valori | numero massimo di caratteri, minimo, massimo |
| E-mail / URL | il formato viene controllato |
| Univoco | «… è già in uso» (i record eliminati non contano) |
| Elenco di selezione | solo i valori predefiniti |
| Collegamento | solo record esistenti e non eliminati |
| File | solo i tipi di file ammessi, al massimo la dimensione e il numero impostati |

### 4.7 Esportare i dati

Con **«Esporta»** in alto in un elenco salvate la vista attuale in un file sul vostro computer: **Excel (.xlsx)** o
**CSV (.csv)**. Il pulsante compare se l’esportazione è consentita per la tabella (vedere
[Guida all’amministrazione, capitolo 4](ADMIN_GUIDE.it.md#4-designer-di-tabelle)) e il vostro ruolo ha il diritto **Esporta**
(vedere [Guida all’amministrazione, capitolo 2](ADMIN_GUIDE.it.md#2-ruoli-e-diritti)).

Il file contiene ciò che l’elenco mostra in quel momento:

- **Record:** tutti i risultati su tutte le pagine, con filtro, ricerca e ordinamento attuali. In una tabella di
  dettaglio sono solo i record della voce selezionata.
- **Colonne:** le colonne attualmente visibili, quindi anche la vostra scelta personale di colonne.
- **Valori:** come mostrati, ad es. l’etichetta di un elenco di selezione e «Sì» o «No». I testi lunghi compaiono
  invece per intero nel file.

| Formato | Particolarità |
|---|---|
| **Excel** | Numeri e date sono valori veri e propri con cui potete calcolare. Una cella Excel contiene al massimo 32’767 caratteri; i testi più lunghi vengono troncati. I numeri con più di 15 cifre vengono salvati come testo, così nessuna cifra va persa. |
| **CSV** | Nella notazione della lingua scelta: in italiano e francese separati da punto e virgola (`1234,50`, `01/02/2026`), in tedesco da punto e virgola (`1234,50`, `01.02.2026`), in inglese da virgola (`1234.50`, `01/02/2026`). Così Excel nella stessa lingua apre il file direttamente in colonne. I testi che iniziano con `=`, `+`, `-` o `@` ricevono un `'` iniziale come protezione contro formule inserite di nascosto. |

L’esportazione viene eseguita come **lavoro** (vedere [I miei lavori](#3-interfaccia-e-dashboard)): gli elenchi
piccoli arrivano subito in download, come sempre. Se dura più di qualche secondo, SAFT vi porta alla pagina del lavoro
con la barra di avanzamento; alla fine il file è pronto lì sotto **Scarica il risultato** e resta disponibile 24 ore –
anche sotto **I miei lavori**, se nel frattempo avete lasciato la pagina. Come riferimento, SAFT elabora qualche
centinaio di righe al secondo. Per ogni esportazione sono possibili di norma al massimo 500’000 record
(l’amministrazione può modificare o togliere il limite); con più risultati compare un avviso e non viene creato alcun
file – restringete allora la selezione con filtro o ricerca.

**Solo i record selezionati:** selezionare le caselle all’inizio delle righe (anche su tutte le pagine con
**«Seleziona tutti N»**; poi se ne possono deselezionare singoli) e scegliere **«Esporta selezione»** nella
barra sopra la tabella. Il file contiene allora solo i record selezionati mostrati dal filtro, nell’ordine
dell’elenco e con le stesse colonne e gli stessi formati indicati sopra; vale lo stesso limite. Funziona anche nelle
tabelle di dettaglio e per le tabelle collegate. Le caselle compaiono non appena si ha il diritto di esportare, anche
senza il diritto *Modificare*.

> **Protezione dei dati:** ogni esportazione viene registrata nel registro modifiche. Il file esportato però non è
> più soggetto ad alcun controllo dei diritti. Trattatelo quindi con cura.

### 4.8 Importare i dati

Con **«Importa»** in alto in un elenco create nuovi record da un file – ad esempio: scaricare un modello vuoto,
compilarlo al di fuori di SAFT e reimportarlo. Il pulsante compare se l’importazione è consentita per la tabella (vedere
[Guida all’amministrazione, capitolo 4](ADMIN_GUIDE.it.md#4-designer-di-tabelle)) e il vostro ruolo ha i diritti
**Creare** e **Importare**.

- **Scarica modello:** un file Excel vuoto con tutti i campi compilabili; i campi obbligatori sono contrassegnati con
  `*`. Anche un’esportazione della tabella è adatta come modello.
- **Importa file …:** Excel (.xlsx) o CSV, al massimo 20 MB e 100’000 righe. La prima riga contiene le intestazioni
  delle colonne – l’etichetta del campo in una delle quattro lingue o il suo nome tecnico. Le colonne senza campo
  corrispondente, ad es. campi calcolati di un’esportazione, vengono ignorate e indicate.
- **Controllo:** SAFT controlla il file come nell’inserimento manuale: campi obbligatori, lunghezza, intervallo di
  valori, e-mail/URL, valori univoci (anche all’interno del file) e regole della tabella. Fino a 2’000 righe avviene
  subito nella finestra: se qualcosa non va, compare un elenco con riga, colonna e motivo e **non** viene creato nulla;
  altrimenti il secondo passaggio indica il numero di nuovi record. I file più grandi vengono controllati
  completamente solo durante l’importazione; il secondo passaggio lo dice. Solo **Importa** crea i record.
- **Come lavoro:** l’importazione viene eseguita come [lavoro](#3-interfaccia-e-dashboard) in due fasi – prima
  **verifica**, poi **importazione** –, l’avanzamento le nomina entrambe. I file piccoli sono pronti subito e il
  messaggio indica il numero di record. Per quelli grandi SAFT vi porta alla pagina del lavoro; contate qualche minuto
  per diecimila righe, perché ogni record viene creato come a mano. Se il controllo trova errori, il lavoro termina
  come fallito con l’elenco degli errori e non viene creato nulla. Anche **Annulla** o un errore durante
  l’importazione rimuovono tutti i record creati fino a quel momento – vale sempre tutto o niente.
- **I valori** possono essere scritti come li fornisce l’esportazione: elenchi di selezione con etichetta o valore
  salvato, sì/no (anche Ja/Nein, Yes/No, Oui/Non, 1/0), collegamenti con il nome visualizzato (ad es. «ACME AG») o il
  numero, date come 31.12.2026, 31/12/2026 o 2026-12-31, numeri come 1234,50, 1234.50 o 1'234.50.
- **I campi file** non sono nel modello e restano vuoti all’importazione, anche se obbligatori; la finestra lo
  segnala.
- **Solo nuovi record:** i record esistenti non vengono mai modificati. Un’esportazione reimportata senza modifiche
  segnala quindi «già in uso» per i campi univoci.
- I valori predefiniti (numeri successivi, ripresa) e i campi calcolati vengono impostati come nell’inserimento. Nel
  registro modifiche figura una voce «importato» con file e numero.

### 4.9 Report

**Report** nel menu principale riassume i dati delle tabelle del Designer di tabelle e delle tabelle collegate
(tramite la loro [istantanea](ADMIN_GUIDE.it.md#stati-dei-dati-per-i-report)) – come elenco, tabella, tabella
incrociata, grafico a barre, a linee o a torta, oppure come singolo indicatore. Servono i diritti **Report:
visualizzare** (consultare) o **creare** (crearne di propri).

**L’editor** (creazione o modifica) funziona come una tabella pivot:

| Area | Significato |
|---|---|
| A sinistra: tabella di base e campi | Scegliere la tabella «più fine», ad es. le righe invece degli ordini. Sotto, i suoi campi e quelli delle tabelle **superiori**, raggruppati per collegamento (ad es. «Ordine › Cliente», fino a tre livelli); con campo di ricerca. |
| A destra: Colonne dell’elenco | Senza valori le **colonne di un elenco** (un record per riga); con valori i campi in base ai quali si raggruppa. I campi data si possono riassumere per giorno, settimana, mese, trimestre o anno. |
| A destra: Colonne della tabella pivot | facoltativo: un campo i cui valori diventano colonne (**tabella incrociata**). |
| A destra: Valori | Calcoli: somma, media, minimo, massimo (numeri), numero, numero distinti; «Numero di record» senza campo; **＋ Valore calcolato** con una formula propria e **＋ Indicatore dai valori** (vedere sotto). |
| A destra: Filtri | Condizione e valore in una finestra di dialogo, secondo il tipo di campo; per le date anche «quest’anno», «mese scorso», «ultimi N giorni». |
| Al centro: Anteprima | ricalcola dopo ogni modifica (al massimo 100 righe). Sopra, la visualizzazione (tabella, tabella incrociata, barre, linee, torta, indicatore); quelle non adatte sono bloccate, il tooltip ne indica il motivo. Un clic sull’intestazione di una colonna ordina. |

I campi si **trascinano** dall’elenco in un’area o si assegnano con **＋** (anche da tastiera); si possono spostare
all’interno di un’area e tra le aree, ✎ modifica l’etichetta o il filtro, ✕ rimuove. Sopra l’anteprima compare una
**frase che descrive il report**, ad es. «Somma Importo per mese (data d’ordine), suddivisa per categoria; solo stato
diverso da «annullato»» – compare anche nella pagina dei risultati e nell’elenco. I record eliminati non vengono mai
contati.

**Valori e colonne calcolati:** dove manca un campo salvato, una **formula** calcola per ogni record.

- **＋ Valore calcolato** (sotto «Valori») riassume una formula – come somma, media, minimo o massimo, ad es.
  *volume d’acquisto* = somma di `[Quantità] × [Articolo › Prezzo d’acquisto]`.
- **＋ Colonna calcolata** (sotto «Colonne dell’elenco», solo negli elenchi senza valori) mostra il risultato per ogni
  record, ad es. *margine* = `[Prezzo di vendita] − [Prezzo d’acquisto]`. La colonna si può ordinare ed esportare.
- **Nella finestra di dialogo** i campi numerici raggiungibili compaiono come pulsanti – anche i campi delle tabelle
  superiori come «Articolo › Prezzo d’acquisto»; un clic inserisce `[etichetta]` alla posizione del cursore. Ci sono
  inoltre pulsanti per `+ − × ÷ ( ) ;` e le funzioni `round(x; decimali)`, `abs(x)`, `min(a; b)`, `max(a; b)` e
  `coalesce(x; sostituto)`. Invece di `×` e `÷` si possono digitare `*` e `/`.
- **Controllo durante l’inserimento:** a destra sopra il campo della formula compare «La formula è valida.» oppure
  l’errore, ad es. ««Nome» non è un numero». Scegliete inoltre l’etichetta e i **decimali** (predefinito 2).
- **Valori vuoti:** se un campo è vuoto, il risultato è vuoto – tranne con `coalesce([campo]; 0)`. Anche una
  divisione per 0 dà un risultato vuoto. Il calcolo è esatto, nel database.
- Nella struttura le formule sono contrassegnate da **ƒ**; ✎ riapre la finestra di dialogo. Se aggiungete valori a un
  elenco con una colonna calcolata, la colonna viene segnata in rosso: le colonne calcolate esistono solo negli
  elenchi.
- **Consiglio:** se il valore esiste già come campo salvato (ad es. calcolato), quel campo è di solito la scelta
  migliore, ad esempio *Valore d’acquisto* dei movimenti di magazzino: conserva il prezzo al momento del movimento.

La demo Magazzino mostra tutte le possibilità in **Artikel-Kalkulation** (colonne calcolate),
**Bewegungskennzahlen je Artikelgruppe** (valori calcolati) e nell’indicatore **Lagerwert gesamt**.

**Indicatore dai valori:** un valore calcolato calcola per record, *prima* del riepilogo. Per i rapporti tra valori
riassunti – ad es. *budget per pubblicazione* = budget del progetto ÷ numero di pubblicazioni del progetto – esiste
**＋ Indicatore dai valori** (sotto «Valori», appena c’è un valore). Calcola per riga del risultato *dopo* il
riepilogo:

- I pulsanti della finestra di dialogo inseriscono gli altri **valori** (ad es. «Numero di record») e i **campi numerici
  raggruppati** sotto «Colonne dell’elenco» (ad es. «Progetto › Budget (CHF)»), che hanno esattamente un valore per
  riga. Non sono possibili i campi data con un livello e i testi, né un altro indicatore dai valori.
- Operatori, funzioni, controllo durante l’inserimento, etichetta e decimali come per un valore calcolato; una divisione
  per 0 dà un risultato vuoto. Nella struttura l’indicatore è contrassegnato da **Σƒ**; si può ordinare ed esportare e
  funziona anche in tabelle incrociate e grafici.
- **Attenzione ai campi delle tabelle superiori:** con la tabella di base *Pubblicazioni* il budget del progetto compare
  in ogni riga di pubblicazione. Raggruppate in base a esso (sotto «Colonne dell’elenco») oppure riassumetelo con la
  **media** o il **massimo** – con la **somma** un progetto con cinque pubblicazioni conterebbe il suo budget cinque volte.

Esempio nella demo Ricerca: **Budget je Publikation** (tabella di base Pubblicazioni, colonne Progetto e Progetto ›
Budget (CHF), valore Numero di record, indicatore `[Budget (CHF)] ÷ [Publikationen]`).

**Assistente IA:** se è configurato e ne avete il diritto, sopra l’editor compare il campo **Descrivere il report
(assistente IA)**. Descrivete con parole vostre cosa volete vedere, ad es. «Volume d’acquisto per fornitore 2026 a
barre»; la proposta riempie l’editor e si può verificare e modificare come di consueto. Si salva solo con **Salva**.
Con un ulteriore inserimento adattate la proposta («solo entrate», «a linee»); **Ricominciare** avvia una nuova
conversazione. L’assistente imposta il nome del report finché non lo inserite o modificate voi; **Ricominciare** cancella
un nome impostato così. L’assistente crea anche formule e indicatori dai valori. Vengono trasmessi la vostra descrizione e i nomi delle tabelle e dei campi dell’app attuale che potete analizzare, più i campi condivisi di altre app – **mai
record**. Il numero di richieste al giorno è limitato; l’assistente indica quante
ne restano oggi.

**Condividere:** un report è privato o **condiviso**. Chi lo vede e lo esegue lo decidono sempre i **diritti sui dati
della persona che lo apre**: le serve il diritto di lettura su ogni tabella coinvolta, anche sulle tabelle che
compaiono solo nel filtro. I report condivisi su tabelle che non potete leggere non compaiono nel vostro elenco; un
richiamo diretto indica la tabella mancante. Solo le persone con il diritto **Report: modificare** possono modificare
i report altrui; **Duplica** crea una vostra copia.

**Nel menu:** i report condivisi si possono inserire in un gruppo di menu come voce di tipo **Report** (vedere
[Guida all’amministrazione, capitolo 3](ADMIN_GUIDE.it.md#3-menu)).

**Esportare:** nella pagina dei risultati, sotto **Esporta**, come Excel o CSV – così come la pagina mostra il report
(elenco, tabella raggruppata o tabella incrociata), senza il limite dell’anteprima. In Excel numeri e date restano
numeri e date; il CSV usa i separatori e il segno decimale della lingua. Ogni esportazione figura nel registro
modifiche.

**Sulla mia dashboard:** fissa il report come **riquadro proprio** sulla vostra dashboard personale (grafici e
indicatori completi, tabelle con le prime dieci righe). Grafici e indicatori compaiono inizialmente in una delle due
colonne, le tabelle a tutta larghezza; con «Personalizza dashboard» trascinate un riquadro nell’area larga o indietro
(vedere [capitolo 3](#3-interfaccia-e-dashboard)). Lo stesso pulsante lo rimuove. Anche lì valgono i vostri diritti
sui dati.

**Elenco dei report:** la colonna **Visualizzazione** mostra tabella, tabella incrociata, barre, linee, torta o
indicatore con un’icona. La ricerca trova nomi e descrizioni; i filtri restringono per **Visualizzazione**, **Tabella
di base**, **Creato da**, **Condiviso** e **Modificato** (dal–al), **Solo i miei** mostra i propri.

> **Buono a sapersi:** se il modello di dati cambia (campo disattivato o eliminato), invece di un risultato il report
> indica quale campo manca. Rinominare non crea problemi – i report fanno riferimento al campo stesso, non al suo nome.

**Le tabelle collegate** (Connettore di tabelle) non vengono interrogate in tempo reale, ma tramite la loro
**istantanea** – una copia nel database di SAFT (vedere [Guida all’amministrazione, capitolo 10](ADMIN_GUIDE.it.md#stati-dei-dati-per-i-report)).

La scelta della tabella di base ha in alto un **campo di ricerca** (etichetta, nome tecnico, connessione) e mostra per
ogni tabella un’icona: database = tabella SAFT, nuvola grigia = collegata senza istantanea, nuvola verde = collegata
con istantanea (con data). Se scegliete una tabella collegata, SAFT crea subito le istantanee mancanti – per essa e per
le tabelle collegate raggiungibili tramite collegamenti che potete leggere. Basta il **diritto di lettura** sulla
tabella. Se un’istantanea ha più di un giorno (`REPORTS_SNAPSHOT_STALE_HOURS`, predefinito 24), una finestra di
dialogo chiede: **Crea nuova istantanea** oppure **Usa quella esistente**.

Una volta scelta una tabella di base, restano selezionabili solo le tabelle **collegate a essa tramite collegamenti**
(in entrambe le direzioni, su un numero qualsiasi di livelli); le altre compaiono in grigio sotto «Non collegata a …».
Per una tabella non collegata scegliete prima **Annulla selezione** (prima voce dell’elenco). Se il report ha già dei
campi, SAFT chiede conferma prima del cambio o dell’annullamento, perché righe, colonne, valori e filtri vengono
svuotati.

Il risultato indica sotto la tabella l’istantanea usata, ad es. «Istantanea: Persone (Ricerca) del 04/12/2026 09:30».
Vale sempre l’**istantanea completa più recente**; se viene eliminata, il report calcola automaticamente con quella
precedente. I collegamenti tra tabelle collegate della stessa connessione si usano come di consueto. **Tra fonti
diverse** – tabella SAFT con tabella collegata, oppure tabelle di connessioni diverse – fanno da ponte i
[collegamenti tra tabelle](ADMIN_GUIDE.it.md#11-collegamenti-tra-tabelle); i loro campi compaiono nell’albero dei campi sotto
l’etichetta del collegamento, ad es. «Contatti › Persona (Ricerca) › Cognome».

### 4.10 Creare documenti

Se per una tabella esiste un **modello di documento** (p. es. «Fattura» per i giustificativi), nella riga di un record
appare l’icona **«Crea documento»** (freccia in giù); sullo smartphone si trova nella finestra dei dettagli. Appare solo
se il vostro ruolo ha il diritto *Documenti: generare*.

1. Cliccare sull’icona. Se ci sono più modelli, scegliere il **modello**.
2. **«Depositare nel campo …»** (preselezionato) deposita il PDF anche presso il record, p. es. nei file del
   giustificativo. L’interruttore appare solo se il modello ha un campo di deposito e potete modificare il record.
3. **Crea** scarica il PDF.

Il PDF sta sulla carta intestata della vostra organizzazione, con campo indirizzo per buste a finestra e numeri di
pagina. Numeri, date e valori di selezione appaiono nella lingua del modello o del record.

I **documenti depositati** sono protetti da scrittura: una nuova creazione deposita una **nuova versione** accanto
(`Rechnung-V-2025-001 (2).pdf`); nulla viene mai sovrascritto. Può rimuovere un tale documento dal campo file solo chi
può cancellare i record della tabella; altrimenti appare subito un messaggio e il file resta. Quando si **copia** un
record, i documenti creati non vengono ripresi. Ogni documento creato figura nella **cronologia** del record
(«documento generato», con modello, nome del file e valore di controllo SHA-256) – anche se è stato solo scaricato.

Se il campo file è pieno (numero massimo di file), il documento viene solo scaricato; un messaggio lo segnala.

**Più record alla volta** (p. es. tutte le fatture di un semestre):

1. Nell’elenco scegliere i record con le **caselle di spunta** all’inizio della riga. La casella nell’intestazione
   della tabella seleziona l’intera pagina; SAFT propone poi di selezionare **tutti** quelli che il filtro mostra –
   anche nelle pagine successive.
2. Cliccare su **«Crea documenti»** nell’intestazione della tabella, scegliere il modello e l’**output**: **un PDF con
   tutti i documenti** (per stampare; ognuno inizia su una nuova prima pagina della carta intestata) o **ZIP con un
   file per documento**.
3. **«Depositare nel campo … di ogni record»** (preselezionato) deposita ogni documento presso il suo record, come
   sopra.
4. **Crea** scarica il file (p. es. `Rechnung-2026-11-22.pdf`). Con molti record ciò avviene come
   [lavoro](#3-interfaccia-e-dashboard): SAFT vi porta alla sua pagina, l’avanzamento conta i documenti e alla fine il
   file è pronto lì per 24 ore sotto **Scarica il risultato**. **Annulla** termina il passaggio; i documenti già
   depositati restano presso i loro record.

Il messaggio successivo indica quanti documenti sono stati creati e depositati e, per record, cosa non si è potuto
depositare (p. es. campo pieno) – questi documenti sono comunque nel file. Un limite esiste solo se l’amministrazione ne
fissa uno; con moltissimi documenti lo ZIP è l’uscita migliore, perché un unico PDF unisce tutte le pagine in una volta.
L’azione di gruppo ignora i record eliminati.

**Inviare per e-mail:** Se il modello prevede un’e-mail e il vostro ruolo ha il diritto *Documenti: inviare*, il
dialogo mostra l’interruttore **«Inviare per e-mail»**. **A**, **oggetto** e **testo** sono precompilati dal modello
(p. es. l’indirizzo del cliente) e si possono modificare prima dell’invio; separate più indirizzi con virgole. **Copia
a me** vi invia una copia nascosta. Il PDF viene allegato; i documenti inviati vengono depositati presso il record se
possibile. La **cronologia** mostra «documento inviato» con destinatario e oggetto. Nell’azione di gruppo, **«Inviare
per e-mail per ogni record»** invia a ogni record la sua e-mail con oggetto e testo del modello – ciascuna appena i
documenti di un passo sono depositati; il messaggio successivo indica quante e-mail sono state inviate e quali record
non hanno un indirizzo valido.

**Fattura QR:** Se il modello aggiunge una fattura QR, la **sezione pagamento con ricevuta** compare in fondo
all’ultima pagina o su una pagina propria – con importo, riferimento e il vostro cliente come debitore. I clienti la
pagano con l’app bancaria (scansionando il codice QR) o allo sportello postale. Se mancano dati (p. es. un IBAN valido
nella carta intestata) o l’importo non è un numero, un messaggio indica il motivo e il documento non viene creato –
rivolgetevi all’amministrazione.

**In Word:** Se il modello ammette anche Word, scegliete nella finestra il **Formato** **PDF** o **Word**. Il file Word
(.docx) serve per la rielaborazione, p. es. per adattare una lettera: ha intestazione, campo indirizzo e tabelle come il
PDF, ma senza sfondo della carta intestata e senza fattura QR. Viene solo scaricato – depositato e inviato per e-mail è
sempre il PDF. Più record in Word arrivano in uno ZIP.

---

### 4.11 Modificare più record (modifica multipla)

Con la modifica multipla cambiate un campo per molti record in una volta, senza aprirli uno per uno – per esempio tutte
le iscrizioni da *lista d’attesa* ad *attivo*, sostituire un docente in tutti i corsi di un semestre o aumentare del 5 %
la tariffa di tutti i corsi di una materia.

1. **Selezionare:** restringete l’elenco con un filtro o una ricerca e spuntate le **caselle** all’inizio delle righe.
   La casella nell’intestazione seleziona le righe della pagina; l’elenco offre poi **«Seleziona tutti N»** su tutte le
   pagine. Vengono modificati solo i record che il filtro mostra.
2. **«Modifica …»** nella barra sopra la tabella apre la finestra. Spuntate i campi da modificare e scegliete per ognuno
   come: **impostare** (un valore per tutti, con il solito campo di inserimento), **svuotare**, **calcolare**,
   **sostituire** o **formula** (vedi sotto). Tutti gli altri campi restano invariati.
3. L’**anteprima** sotto mostra fino a dieci record che cambiano, con *prima → dopo*, conta quelli invariati e dà
   avvisi, p. es. sugli intervalli di numerazione. Se un calcolo non riesce, il motivo appare in rosso nell’esempio.
4. **«Solo verificare»** controlla tutti i record selezionati senza modificare nulla; la finestra resta aperta.
   **«Modifica N record»** controlla e modifica.

**Calcolare, sostituire, formula:** questi modi calcolano il nuovo valore di ogni record dal suo valore precedente.

- **calcolare** per i numeri: + − × ÷ o «variare di %» (anche negativo, −10 %). L’arrotondamento è commerciale ai
  decimali del campo, a scelta a meno decimali o a un **multiplo** (p. es. 0,05). Esempio: tariffa dei corsi di una
  materia più 5 %, arrotondata a 0,05 – 12.34 diventa 12.95. Per le date: ± giorni o ± mesi, p. es. scadenza delle
  fatture aperte + 30 giorni. A fine mese la data resta nel mese (31.01 + 1 mese = 28.02); un’ora resta invariata.
- **sostituire** per il testo: testo cercato → sostituzione nel valore, tutte le corrispondenze. A scelta
  **«Maiuscole/minuscole»**, **«Solo parola intera»** (né lettera né cifra prima e dopo) e **«Mantenere
  maiuscole/minuscole»** (la sostituzione si scrive in maiuscolo o minuscolo come la corrispondenza). Esempio: «str.» →
  «strasse» con «Mantenere maiuscole/minuscole» trasforma «Bahnhofstr. 3» in «Bahnhofstrasse 3» e «Str. des 1. Mai» in
  «Strasse des 1. Mai». È sempre un testo cercato per campo e per passaggio; non ci sono caratteri jolly né espressioni
  regolari.
- **formula** per numeri e sì/no: una formula come per i campi calcolati, calcolata per ogni record. Il nome del campo
  indica il suo valore precedente; altri campi, campi calcolati e somme sui record di dettaglio sono ammessi. Esempio:
  `round(tarif * 1.05 / 0.05, 0) * 0.05`. Sotto il campo si vede se la formula è corretta. Una divisione per 0 è un
  errore e impedisce la modifica multipla.

Se il valore precedente è vuoto, un testo non ha corrispondenze o una formula non dà risultato, il record resta
invariato; anteprima e messaggio lo contano come «senza valore o senza corrispondenza». Tutti i modi vedono i valori di
prima della modifica multipla, anche se si cambiano più campi insieme. Se un risultato non entra nel campo (troppe
cifre, testo troppo lungo), è un errore; nulla viene troncato.

SAFT controlla ogni record come nella modifica manuale – campi obbligatori, regole di validazione, regole della tabella e
plugin (p. es. un controllo dei conflitti). Vale **tutto o niente**: se un record non supera i controlli, nessuno viene
modificato, e il messaggio indica i record con il motivo. Deselezionateli o correggeteli e riprovate. Il controllo vede già gli altri record selezionati con i nuovi valori: un conflitto può esistere anche tra due record selezionati, p. es. due corsi dello stesso docente alla stessa nuova ora. I record che hanno
già il nuovo valore restano invariati. Con molti record la modifica multipla continua come **lavoro**; il progresso è
visibile in *I miei lavori*. Se qualcuno modifica uno dei record durante la modifica multipla, questa viene annullata
completamente.

Non vengono proposti i campi il cui valore è stabilito da SAFT o che non possono avere lo stesso valore per tutti:
intervalli di numerazione, campi calcolati, file e immagini. I campi univoci non si possono impostare (un valore per
tutti), ma svuotare, sostituire o calcolare; se un valore risultasse doppio, il controllo lo segnala. I campi
obbligatori non si possono svuotare. In una tabella di dettaglio (p. es. le iscrizioni di un corso) il collegamento alla tabella
principale è fisso. La **cronologia** di un record indica «(modifica multipla)» per queste modifiche. Se modificate un
campo che decide quando viene assegnato un numero, i record ricevono i loro numeri nell’ordine delle chiavi.

**Eliminare e ripristinare:** **«Elimina selezionati»** elimina i record selezionati; quelli a cui fanno riferimento altri
record restano e vengono contati nel messaggio. Con il filtro «Record eliminati», **«Ripristina selezionati»** recupera
i record eliminati.

**Annullare:** Il messaggio di riuscita di una modifica multipla offre il link **«Annulla»**. In seguito trovate le
stesse modifiche multiple in **«Ultime modifiche multiple»** sopra la tabella: le ultime 20 con quando, chi, cosa e
numero, anche «Elimina selezionati» e «Ripristina selezionati». Prima dell’annullamento un’anteprima mostra quanti
record tornano indietro e fino a dieci esempi con *ora → dopo*. I valori precedenti provengono dal registro delle
modifiche. Se un campo modificato ha nel frattempo un altro valore, l’intero record resta e il messaggio lo indica; le
modifiche ad altri campi non contano. Restano anche i record che non superano più un controllo, p. es. perché il valore
precedente di un campo univoco è ormai assegnato. Se avete modificato più volte gli stessi record, annullate prima
l’ultima modifica multipla. Anche un annullamento si può annullare. I numeri assegnati, gli effetti dei plugin e le
e-mail inviate restano. Annullate le vostre modifiche multiple con il diritto *Modificare* (o *Eliminare*), quelle di
altre persone solo con il diritto di vedere il registro delle modifiche. Quando il registro delle modifiche viene
ridotto, le modifiche multiple più vecchie scompaiono anche dall’elenco.

**Mostrare i record:** il link **«Mostra record»** in «Ultime modifiche multiple» e nella conferma «Annulla» apre
l’elenco con esattamente i record di quella modifica multipla; chi ha il diritto *Eliminare* vede anche quelli eliminati
nel frattempo. Un’etichetta sopra la tabella indica «Modifica multipla del …». Si può ordinare, filtrare ancora,
esportare la selezione o modificarla di nuovo. Questa vista non viene salvata: la ✕ sull’etichetta, **«Rimuovi tutti i
filtri»** o **«Reimposta vista»** riportano alla vista salvata con i filtri precedenti, così come la prossima apertura
della tabella dal menu.

Il pulsante «Modifica …» è visibile con il diritto *Modificare* se l’amministrazione consente la modifica multipla per
la tabella. Al massimo 5’000 record per modifica multipla, salvo diversa impostazione dell’amministrazione.

---

## Allegato A – Tipi di campo

| Tipo | Esempio | Particolarità |
|---|---|---|
| Testo (una riga) | Ditta, numero d’articolo | lunghezza massima impostabile; controllo facoltativo di e-mail o URL |
| Testo (più righe) | Osservazione | |
| Numero intero | Giacenza, anno | minimo/massimo; disattivare **«Con separatore delle migliaia»** per anni e numeri identificativi («2024» invece di «2’024») – vale per tabelle, dettagli, report ed esportazione. Disattivato automaticamente all’importazione e per le colonne MySQL di tipo `YEAR` con anni |
| Numero decimale | Prezzo | cifre totali e decimali; minimo/massimo |
| Sì/No | Attivo | |
| Data | Data d’ordine | |
| Data e ora | Momento | |
| Elenco di selezione | Stato, paese | valori fissi con etichetta visualizzata |
| Collegamento | Cliente di un ordine | tabella di destinazione e campo visualizzato; base per dettagli e anteprima |
| File | Contratto, foto del prodotto | immagini, PDF, documenti Office, testo/CSV o file qualsiasi; dimensione massima e numero per campo; anteprima nell’elenco e nei dettagli. Non nell’importazione; l’esportazione contiene solo i nomi dei file (vedi [4.2](#42-visualizzare-creare-modificare-ed-eliminare-record)) |
| Calcolato | Importo = quantità × prezzo unitario | risultato come numero intero o decimale, formula; calcolato al salvataggio, non inserito (vedere [Campi calcolati](ADMIN_GUIDE.it.md#campi-calcolati)) |
| Intervallo di numerazione | Numero di fattura R-2026-0042 | modello con anno, mese e numero progressivo, a richiesta con un contatore proprio per tipo di documento (p. es. V-2026-001 per le entrate, E-2026-001 per le uscite); assegnato alla creazione (o solo quando una condizione è vera, p. es. stato *Inviata*; fino ad allora il modulo mostra «assegnato se …»), univoco e poi non modificabile (vedere [Intervalli di numerazione](ADMIN_GUIDE.it.md#intervalli-di-numerazione)) |

---

## Allegato B – Glossario

| Concetto | Spiegazione |
|---|---|
| **App** | applicazione specialistica, ad es. «Negozio» o «Magazzino»: tabelle, menu, report e ruoli che vanno insieme; si cambia con il selettore in alto |
| **Single sign-on (SSO)** | Accesso tramite un account aziendale centrale invece di una password propria |
| **Provider di identità (IdP)** | Il servizio che esegue l’accesso con il single sign-on, ad es. Microsoft Entra ID |
| **Entra ID** | Il servizio di identità di Microsoft (in precedenza Azure Active Directory) |
| **SAML** | Uno standard diffuso per il single sign-on |
| **Master-detail** | Presentazione di un record (master) con i record a esso associati (dettagli) |
| **Eliminazione logica (soft delete)** | «Eliminare» come contrassegno; il record resta ripristinabile |
| **Audit / registro modifiche** | Registrazione completa di tutte le modifiche |
| **Lingua predefinita** | Tedesco; vale se né l’account né il browser stabiliscono una lingua offerta, e come sostituto per le traduzioni mancanti |
| **CSV** | File di testo con valori separati da un separatore; leggibile da Excel e da altri programmi |
