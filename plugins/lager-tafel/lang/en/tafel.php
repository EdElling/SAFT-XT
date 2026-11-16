<?php

declare(strict_types=1);

return [
    'plugin' => 'Stock board',
    'description' => 'Example plugin for the how-to guides in the developer manual: stock matrix, stock board with drag and drop, dashboard tile, listeners and validation rule for items (warehouse demo, requires Stock transfer).',
    'permissions' => [
        'view' => 'View stock matrix',
        'transfer' => 'Transfer on the stock board',
    ],
    'unavailable_title' => 'Warehouse demo incomplete',
    'unavailable' => 'This page needs tables and fields of the warehouse demo. Missing:',
    'no_rights' => 'You are not allowed to view items, locations and stock.',
    'actions' => [
        'matrix' => 'Stock matrix',
        'board' => 'Distribute on stock board',
    ],
    'rules' => [
        'article_number' => 'Item number (warehouse demo)',
        'article_number_invalid' => 'Item number expected in the format A-1234 (one letter, hyphen, four digits).',
    ],
    'errors' => [
        'number_locked' => 'Item number :number is used in stock movements and can no longer be changed.',
    ],
    'matrix' => [
        'title' => 'Stock matrix',
        'number' => 'Number',
        'article' => 'Item',
        'total' => 'Total',
        'minimum' => 'Minimum stock',
        'below_minimum' => 'Below minimum stock only',
        'empty' => 'No items',
    ],
    'board' => [
        'title' => 'Stock board',
        'article' => 'Item',
        'help' => 'Drag the stock onto another location to transfer it.',
        'drag' => 'Drag onto another location',
        'empty' => 'No stock',
        'modal' => 'Transfer from :from to :to',
        'submit' => 'Transfer',
        'quantity' => 'Quantity',
        'rights' => 'You lack data permissions for: :tables',
        'invalid' => 'The transfer violates table rules:',
        'done' => 'Transferred :count from :from to :to.',
    ],
    'widget' => [
        'label' => 'Warehouse: stock by location',
        'description' => 'Sum of all items',
        'quantity' => 'Stock',
    ],
];
