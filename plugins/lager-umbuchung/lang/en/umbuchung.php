<?php

declare(strict_types=1);

return [
    'plugin' => 'Stock transfer',
    'description' => 'Example plugin: transfer goods between locations; stock movements update the stock; alert when stock falls below the minimum (warehouse demo).',
    'permission' => 'Perform transfers',
    'title' => 'Transfer',
    'help' => 'Transfers goods from one storage location to another. Stock levels at both locations are updated, and two stock movements of the kind “Transfer” (−/+) are created.',
    'fields' => [
        'article' => 'Item',
        'from' => 'From location',
        'to' => 'To location',
        'quantity' => 'Quantity',
        'note' => 'Note',
    ],
    'submit' => 'Transfer',
    'available' => 'Available at the source: :count',
    'location_with_stock' => ':location (stock: :count)',
    'no_stock_here' => 'No stock at this location',
    'no_stock' => 'This item has no stock at any location.',
    'unavailable_title' => 'Warehouse demo incomplete',
    'unavailable' => 'This page needs tables and fields of the warehouse demo. Missing:',
    'done' => ':count × :article transferred from :from to :to.',
    'minimum' => [
        'title' => 'Minimum stock undercut',
        'body' => 'Total stock of “:article”: :count (minimum stock :minimum).',
    ],
    'errors' => [
        'same_location' => 'Source and target location must be different.',
        'quantity' => 'The quantity must be at least 1.',
        'not_enough' => 'Not enough stock at the source (available: :available).',
        'invalid' => 'The transfer violates table rules:',
        'negative' => 'Not enough stock: “:article” at :location has :available; the movement would make it negative.',
        'stock_locked' => 'Stock levels are kept through stock movements. Please record corrections as a stock movement of the kind “Stocktaking”.',
        'rights' => 'You lack data permissions for: :tables',
    ],
    'widget' => [
        'label' => 'Warehouse: minimum stock',
        'below' => 'Items below minimum stock',
        'none' => 'All items above minimum stock',
        'item' => ':article (:count/:minimum)',
        'more' => ':names and :count more',
        'total' => 'Total stock (pieces)',
        'transfer' => 'Go to transfer',
    ],
];
