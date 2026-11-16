<?php

declare(strict_types=1);

return [
    'title' => 'Descrivere la tabella (assistente IA)',
    'privacy' => 'La descrizione e la struttura delle tabelle del designer (nomi, etichette, tipi di campo) vengono trasmesse a :provider – nessun record.',
    'placeholder' => 'ad es. «Membri di un’associazione con data di entrata, categoria di quota (attivo, passivo, junior) ed e-mail»',
    'placeholder_followup' => 'Descrivere una modifica, ad es. «aggiungere un campo Osservazioni» o «categoria obbligatoria»',
    'applied_form' => 'Proposta applicata – verificare nomi, campi e tipi. La tabella viene creata solo con «Crea».',
    'edit_action' => 'Adattare con l’IA',
    'edit_heading' => 'Adattare la tabella con l’assistente IA',
    'edit_prompt' => 'Che cosa deve cambiare?',
    'edit_placeholder' => 'ad es. «Aggiungere data di entrata e di uscita», «e-mail univoca e obbligatoria» o «collegare alla tabella Associazioni»',
    'edit_help' => 'Sono consentiti nuovi campi, etichette, obbligatorio/univoco/ricercabile/ordinabile, altre opzioni e i campi visualizzati dei collegamenti. Cambiare tipo, rinominare ed eliminare restano manuali.',
    'preview_heading' => 'Modifiche proposte',
    'preview_intro' => 'L’assistente propone:',
    'apply' => 'Applicare',
    'applied_all' => 'Tutte le modifiche sono state applicate.',
    'applied_some' => 'Non tutto è stato applicato:',
    'summary' => [
        'add' => 'Nuovo: :field (:type)',
        'change' => 'Modificare: :field – :what',
        'on' => ':what attivo',
        'off' => ':what disattivo',
        'label' => 'etichetta «:label»',
        'choices' => ':count opzioni aggiuntive',
        'display' => 'visualizzazione: :fields',
    ],
    'flags' => [
        'required' => 'obbligatorio',
        'unique' => 'univoco',
        'searchable' => 'ricercabile',
        'sortable' => 'ordinabile',
    ],
    'applied' => [
        'added' => 'Campo :field aggiunto.',
        'changed' => 'Campo :field modificato.',
    ],
    'errors' => [
        'invalid' => 'Nessuna proposta valida: :errors. Descrivere in modo più preciso o completare a mano.',
        'name_taken' => 'Il nome «:name» è già in uso (:owner).',
        'menu_group' => 'Il gruppo di menu :id non esiste.',
        'no_fields' => 'La tabella richiede almeno un campo.',
        'duplicate_field' => 'Il campo «:name» compare due volte.',
        'field' => 'Campo «:name»: :error',
        'no_changes' => 'Nessuna modifica proposta.',
        'field_exists' => 'Il campo «:name» esiste già.',
        'unknown_field' => 'Il campo «:name» non esiste.',
    ],
];
