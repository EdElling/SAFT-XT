<?php

declare(strict_types=1);

return [
    'model' => 'Table link',
    'plural' => 'Table links',
    'description' => 'Link tables without foreign keys – also SAFT tables with connected tables, or tables of different connections. Like a relation field: many rows of the source point to one row of the target. Usable in reports and as details in the master-detail view. Nothing is created in the source database.',
    'label' => 'Label',
    'label_help' => 'Shown in the field tree of the report editor and as the heading of the details, e.g. “Customer (ERP)”.',
    'source' => 'Source',
    'target' => 'Target',
    'table' => 'Table',
    'source_field' => 'Source field',
    'source_field_help' => 'Integer, text or selection field holding the value looked up in the target.',
    'target_field' => 'Unique target field',
    'target_field_help' => 'SAFT table: id or a field marked “unique”; connected table: its key. Only fields matching the source field (integer or text).',
    'coverage_label' => 'Matches',
    'coverage' => ':matched of :total rows with a value find a target (:percent %).',
    'coverage_no_snapshot' => 'No statement: a connected table has no data snapshot yet (or the field is missing in it).',
    'external' => 'connected, :connection',
    'designer' => 'SAFT table',
    'errors' => [
        'source_field' => 'The field does not belong to the source table or cannot be used as a link (integer, text, selection only).',
        'target_unique' => 'This field of “:table” is not unique. The target can only be id, a field marked “unique” or the key of a connected table.',
        'kind_mismatch' => 'Source and target field do not match: integer only with integer, text only with text.',
        'same_field' => 'Source and target are the same field.',
        'duplicate' => 'There is already a link from this field to this table.',
    ],
];
