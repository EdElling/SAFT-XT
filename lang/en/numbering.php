<?php

declare(strict_types=1);

// Number sequences (B-135): field type "Number sequence" in the table designer.
return [
    'fields' => [
        'pattern' => 'Pattern',
        'reset' => 'Restart',
        'date_field' => 'Reference date',
        'fiscal_start' => 'Financial year starts in',
        'start' => 'Start value',
    ],
    'form' => [
        'section' => 'Number sequence',
        'pattern_help' => 'Fixed text and placeholders, e.g. R-{YYYY}-{0000}. The running number has as many digits as zeros.',
        'insert' => 'Insert placeholder',
        'insert_placeholder' => 'Choose a placeholder …',
        'date_created' => 'Creation date',
        'date_field_help' => 'Determines year and month of the number and the restart. Required date fields can be chosen.',
        'start_help' => 'First number per period.',
        'next' => 'Next number: :number',
        'assigned_on_save' => 'assigned when saving',
    ],
    'resets' => [
        'never' => 'never',
        'yearly' => 'yearly',
        'monthly' => 'monthly',
    ],
    'months' => [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ],
    'blocks' => [
        'year4' => 'year, four digits',
        'year2' => 'year, two digits',
        'month' => 'month',
        'counter' => 'running number, zero-padded',
        'counter_plain' => 'running number, not padded',
    ],
    'errors' => [
        'reset' => 'Unknown restart ":reset" (never, yearly or monthly).',
        'fiscal_start' => 'The financial year starts in a month from 1 to 12.',
        'start' => 'The start value is at least 1.',
        'counter' => 'The pattern ":pattern" needs exactly one placeholder for the running number, e.g. {0000} or {#}.',
        'needs_yearly' => 'With a yearly restart the pattern ":pattern" needs a year ({YYYY} or {YY}); otherwise numbers would repeat.',
        'needs_monthly' => 'With a monthly restart the pattern ":pattern" needs year and month ({YYYY} or {YY} and {MM}); otherwise numbers would repeat.',
        'too_long' => 'Numbers following the pattern ":pattern" would be longer than :max characters.',
        'characters' => 'Apart from placeholders, the pattern ":pattern" may only contain letters, digits, spaces and - / . _',
        'placeholder' => 'Unknown placeholder :placeholder. Available are {YYYY}, {YY}, {MM}, {0000} and {#}.',
        'date_field' => 'The reference date ":field" is not a date or date and time field of this table.',
        'date_field_required' => 'The reference date ":field" must be a required field so that every number has a date.',
        'date_field_in_use' => 'The field ":name" is the reference date of the number sequence ":field" and therefore stays an active, required date field.',
        'date_missing' => 'The reference date ":date" for the number ":field" is missing.',
        'given' => 'SAFT assigns the number ":field" when saving; it cannot be specified.',
        'immutable' => 'The number ":field" has been assigned and cannot be changed.',
        'type_change' => 'The type of ":name" can only be changed from or to "Number sequence" while no record has a value in this field (including the recycle bin).',
    ],
];
