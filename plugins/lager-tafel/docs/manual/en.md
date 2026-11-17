# Stock board – user guide

## Overview

The plugin *Stock board* adds two views and some rules to the warehouse demo:

- **Stock matrix** – all items with their stock per location at a glance.
- **Stock board** – transfer an item's stock to another location by dragging.
- A tile **Warehouse: stock by location** on the dashboard.
- Rules for the **item number** and two kinds of **scheduled tasks** (stocktaking reminder, report minimum stock).

Bookings go through the plugin *Stock transfer*; its guide describes stock levels, stock movements and stocktaking. If
it is switched off, the stock matrix and the stock board show a hint.

## Stock matrix

**Warehouse (demo) › Warehouse operations › Stock matrix** shows one row per item and one column per location with the stock; locations
without stock show 0 in grey. **Total** sums all locations and is red when the item is below its **minimum stock**.

- **Search** by item number or name with the search field.
- **Sort** by number, item or minimum stock with the column headers (the location columns and *Total* cannot be
  sorted).
- **Filter “Below minimum stock only”** shows only items whose total stock is below the minimum stock.
- The button **Distribute on stock board** in a row opens the stock board for this item.

## Stock board

**Warehouse (demo) › Warehouse operations › Stock board** shows one card per location with the stock of an item.

1. Choose the **Item** (or come from the stock matrix or the items with **Distribute on stock board**).
2. **Drag** the stock of a card **onto another card** with the mouse.
3. In the dialog check the **Quantity** – the whole stock at the source is prefilled – and click **Transfer**. More than
   is available is not possible.

The booking works as in the *Transfer* form: two stock movements of the kind *Transfer*, all or nothing. Cancelling the
dialog changes nothing. Dragging only works with the mouse; with the keyboard, transfer via **Warehouse (demo) › Warehouse operations ›
Transfer**.

## Items: buttons and rules

In **Warehouse (demo) › Master data › Items** there are two additional buttons: **Stock matrix** above the table and **Distribute on
stock board** in every row.

For the **item number** these rules also apply:

| Rule | Effect |
|---|---|
| **Uniform spelling** | spaces are removed, letters become capitals: “ a-1001 ” becomes “A-1001” |
| **Locked after movements** | once an item has stock movements, its number can no longer be changed (another spelling of the same number is allowed). Other fields stay editable |
| **Format A-1234** | one letter, hyphen, four digits – only if the administration has selected “Item number (warehouse demo)” for the field under **Further checks** |

The rules apply on entry, on import and through the API. Unique, required and at most 20 characters are set by the table
designer.

## Tile and scheduled tasks

**Tile “Warehouse: stock by location”:** bar chart with the sum of all stock per location on the dashboard.

The administration sets up two kinds of scheduled tasks under **Administration › Operations › Scheduled tasks**:

| Task | What it does |
|---|---|
| **Warehouse: stocktaking reminder** | lists in the run log the stock levels with goods that have not been counted for the set number of days (*Not counted for*, default 90) – a reason for stocktaking |
| **Warehouse: report minimum stock** | if items are below minimum stock, everyone with the permission *View stock matrix* receives a notification in the bell, with the button **Open stock matrix** |

## Permissions

| For | Permission |
|---|---|
| Stock matrix, tile, *Stock matrix* button, *minimum stock* notification | *Stock board: View stock matrix* |
| Stock board, *Distribute on stock board* button | *Stock board: Transfer on the stock board* |
| Reading | data permissions *view* on items, locations and stock levels |
| Transferring on the board | the same data permissions as for the *Transfer* form (see the guide *Stock transfer*) |
