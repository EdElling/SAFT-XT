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
    'tasks' => [
        'unavailable' => 'Warehouse demo incomplete: :missing',
        'inventory' => [
            'label' => 'Warehouse: stocktaking reminder',
            'days' => 'Not counted for (days)',
            'days_help' => 'Stock with goods whose last count is older or missing is reported.',
            'describe' => 'Stock not counted for :days days',
            'no_field' => 'The table stock levels has no field “letzte_zaehlung”.',
            'item' => ':article (:location)',
            'none' => 'All stock was counted in the last :days days.',
            'found' => '{1} 1 stock level not counted for over :days days: :list|[2,*] :count stock levels not counted for over :days days: :list',
            'more' => ':list and :count more',
        ],
        'minimum' => [
            'label' => 'Warehouse: report minimum stock',
            'describe' => 'Notification to people with the permission “View stock matrix”',
            'no_field' => 'The table items has no field “mindestbestand”.',
            'none' => 'All items are above minimum stock; nobody notified.',
            'title' => '{1} 1 item below minimum stock|[2,*] :count items below minimum stock',
            'more' => ':list and :count more',
            'open' => 'Open stock matrix',
            'done' => ':count items below minimum stock, :people people notified.',
        ],
    ],
    'widget' => [
        'label' => 'Warehouse: stock by location',
        'description' => 'Sum of all items',
        'quantity' => 'Stock',
    ],
];
