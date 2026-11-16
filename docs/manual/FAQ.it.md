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
   - [In quale lingua compare l’applicazione?](#in-quale-lingua-compare-lapplicazione)
   - [Ho scelto l’italiano, ma una voce di menu o una tabella resta in tedesco.](#ho-scelto-litaliano-ma-una-voce-di-menu-o-una-tabella-resta-in-tedesco)
   - [Non vedo il pulsante «Esporta».](#non-vedo-il-pulsante-esporta)
   - [In una tabella mancano «Nuovo record» e «Modifica».](#in-una-tabella-mancano-nuovo-record-e-modifica)
   - [Come posso inviare a qualcuno una vista precisa?](#come-posso-inviare-a-qualcuno-una-vista-precisa)

---
## Domande frequenti

### Non vedo una tabella nel menu.

Non avete il diritto di lettura, non esiste una voce di menu oppure il vostro ruolo nasconde la voce. Rivolgetevi
all’amministrazione (Ruoli → Diritti sui dati o voci di menu, oppure Menu).

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
