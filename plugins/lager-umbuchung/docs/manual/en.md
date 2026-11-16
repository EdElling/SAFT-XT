# Stock transfer – user guide

## Overview

In the warehouse demo, the plugin *Stock transfer* keeps the **stock levels** at every location up to date from the
**stock movements**. You only record movements – receipt, issue, transfer, stocktaking –, SAFT calculates the stock
itself. In addition there is the **Transfer** form, the **Transfer** button in the stock levels, a notification when an
item falls below its minimum stock, and the tile **Warehouse: minimum stock** on the dashboard.

You find the tables in the menu under **Warehouse (demo)**: *Items*, *Storage locations*, *Stock levels* and *Stock
movements*.

## Recording stock movements

Under **Warehouse (demo) › Stock movements**, **New** creates a movement: *Item*, *Location*, *Kind*, *Quantity*,
*Time* (prefilled with now) and, if needed, a *Note*.

| Kind | Effect on the stock at the location |
|---|---|
| **Receipt** | increases the stock by the quantity |
| **Issue** | decreases the stock by the quantity |
| **Transfer** | created by the *Transfer* form (see below): an issue at the source and a receipt at the target location |
| **Stocktaking** | corrects the stock by the difference entered and sets the date of the *Last count* on the stock level |

For **Receipt** and **Issue** enter the quantity without a sign; SAFT sets it (issue always negative). If there is no
stock level for the item at the location yet, the first receipt creates it.

**Changing or deleting movements:** if you change a movement (e.g. the quantity or the location), SAFT reverses the old
booking and books the new one. A deleted movement is booked out; if you restore it from the recycle bin, it is booked
again.

## Viewing and correcting stock levels

**Warehouse (demo) › Stock levels** shows the *Quantity* and the date of the *Last count* per item and location.

You **do not change stock levels directly**: SAFT rejects creating, editing and deleting in this table – also on import
– with the note that stock levels are kept through stock movements. This keeps every stock level traceable.

**Correcting a stock level (stocktaking):** record a stock movement of the kind **Stocktaking** with the **difference**
between the counted and the booked quantity – negative if there is less. Example: 10 are booked, 8 counted →
stocktaking with quantity −2. The stock level is then 8, and the *Last count* carries the date of the movement.

**No negative stock:** if a movement would bring the stock at a location below 0, it is not saved. The message names
the item, the location and the available stock.

## Transferring goods

Transferring means moving goods from one location to another without changing the total stock.

1. Open **Warehouse (demo) › Transfer** – or in **Stock levels** click **Transfer** in the row of the source location;
   item and source location are then already selected. Without stock at the location the button is greyed out.
2. Choose the **Item**. **From location** lists only locations with stock of this item, with the quantity.
3. Enter **To location** and **Quantity** (at most the stock at the source), and a **Note** if needed.
4. **Transfer**. SAFT records two stock movements of the kind *Transfer* – minus at the source, plus at the target – in
   one step: either both succeed or neither does.

## Minimum stock

Every item has a *Minimum stock*. If a movement brings the **total stock** of an item (across all locations) below this
value, the person who recorded the movement receives the notification **Minimum stock undercut** in the bell. Transfers
do not trigger a notification because the total stock does not change; increases do not either.

The tile **Warehouse: minimum stock** on the dashboard shows how many items are below minimum stock, with the first
names, and the total stock; a click leads to the transfer.

## Permissions

| For | Permission |
|---|---|
| *Transfer* form, *Transfer* button, tile | *Stock transfer: Perform transfers* |
| Transferring | in addition data permissions: *view* on items and locations, *view*, *create* and *edit* on stock levels, *create* on stock movements |
| Recording movements | *create* (or *edit*, *delete*) on stock movements |

Whoever only records stock movements needs no permissions on stock levels – SAFT keeps them itself. If permissions for
transferring are missing, the *Transfer* form names the tables concerned.
