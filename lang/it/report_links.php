<?php

declare(strict_types=1);

return [
    'model' => 'Collegamento tra tabelle',
    'plural' => 'Collegamenti tra tabelle',
    'description' => 'Collegano tabelle senza chiave esterna – anche tabelle SAFT con tabelle collegate o tabelle di connessioni diverse. Come un campo di collegamento: molte righe dell’origine puntano a una riga della destinazione. Utilizzabili nei report e come dettagli nella vista master-dettaglio. Nel database di origine non viene creato nulla.',
    'label' => 'Etichetta',
    'label_help' => 'Appare nell’albero dei campi dell’editor dei report e come titolo dei dettagli, p. es. «Cliente (ERP)».',
    'source' => 'Origine',
    'target' => 'Destinazione',
    'table' => 'Tabella',
    'source_field' => 'Campo di origine',
    'source_field_help' => 'Campo intero, testo o selezione con il valore cercato nella destinazione.',
    'target_field' => 'Campo univoco della destinazione',
    'target_field_help' => 'Tabella SAFT: id o un campo «univoco»; tabella collegata: la sua chiave. Solo campi compatibili con il campo di origine (intero o testo).',
    'coverage_label' => 'Corrispondenze',
    'coverage' => ':matched di :total righe con valore trovano una destinazione (:percent %).',
    'coverage_no_snapshot' => 'Nessuna indicazione: una tabella collegata non ha ancora un’istantanea (o il campo manca).',
    'external' => 'collegata, :connection',
    'designer' => 'tabella SAFT',
    'errors' => [
        'source_field' => 'Il campo non appartiene alla tabella di origine o non è utilizzabile come collegamento (solo intero, testo, selezione).',
        'target_unique' => 'Questo campo di «:table» non è univoco. La destinazione può essere solo id, un campo «univoco» o la chiave di una tabella collegata.',
        'kind_mismatch' => 'Campo di origine e di destinazione non corrispondono: intero solo con intero, testo solo con testo.',
        'same_field' => 'Origine e destinazione sono lo stesso campo.',
        'duplicate' => 'Esiste già un collegamento da questo campo a questa tabella.',
    ],
];
