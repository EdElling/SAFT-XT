# Shop

## Overview

The **Shop** app uses a small online shop to show how data relate in SAFT: customers order articles, and each order
consists of order lines. All amounts are in euros.

| Table | Content |
|---|---|
| Articles | item number, name, category, price, stock, active yes/no |
| Customers | customer number, company, contact person, email, city, country, customer since |
| Orders | order number, customer, order date, status, delivery date, total and VAT |
| Order lines | order, position, item, quantity, unit price, amount |

## Typical tasks

### Add a new customer

Open **Customers** and click **“New record”**. Customer number and company are required; the customer number may only
occur once. The email address is checked for its format when saving.

### Take an order

1. Select the customer under **Customers** – their orders appear below.
2. Create the order there with **“New record”**. The customer is already filled in; choose *New* as the status.
3. Select the new order and enter the **order lines** below it: item and quantity are enough. The **unit price** is
   copied from the article and can be changed if needed (e.g. a discount).

SAFT calculates the **amount** of each line (quantity × unit price) as well as the order's **total** and **VAT 8.1 %**
itself when saving.

### Ship an order

Set the **status** to *Shipped* and enter the **delivery date**. The delivery date must not be before the order date –
otherwise SAFT refuses to save and shows a note at the field. Cancelled orders get the status *Cancelled*; they remain
available for reports.

### Maintain articles

Change prices and stock directly on the article. A price change only affects new order lines; existing ones keep
their unit price. Set articles you no longer sell to *not active* instead of deleting them – articles with order lines
cannot be deleted anyway.

## Reports

Under **Reports**, **“Umsatz je Monat und Kategorie”** (revenue per month and category) shows the amounts of the order
lines per month and article category. You can create your own reports, e.g. revenue per customer or per country; the
base table for revenue is **Order lines**.

## Tips

- Clicking a row selects it and shows the related data below: for a customer the orders, for an order the lines, for an
  article the orders it appears in.
- **Export** saves the current list as an Excel or CSV file, with your filters and columns.
- The **help assistant** (button **?** at the top right) answers questions about using SAFT – including this manual.
