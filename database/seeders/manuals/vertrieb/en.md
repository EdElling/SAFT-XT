# Sales

## Overview

The **Sales** app shows how two apps work together: sales keeps customers, enquiries and orders, but the **items** come
from the **Warehouse** app. The warehouse shares its items through an **interface** (item number, name, unit, sales
price); sales can read and select them but not change them. All amounts are in euros.

| Table | Content |
|---|---|
| Customers | Customer number, name, country, city, email, phone, customer since |
| Events | Trade fairs, in-house shows, webinars and customer days with start, end, location, online yes/no |
| Enquiries | Date, customer, subject, status, event (where the enquiry came from), order, total |
| Enquiry items | Enquiry, line, item from the warehouse, quantity, price, amount |
| Orders | Order number, customer, order date, status, amount |

## Typical tasks

### Recording an enquiry

1. Under **Customers and events › Customers**, select the customer – their enquiries appear below.
2. Create the enquiry there with **"New record"**: date, subject and status *Open*. If the enquiry came from a trade fair
   or a webinar, select the **event**.
3. Select the enquiry and record the **enquiry items** below it: item and quantity are enough. The items come from the
   warehouse; the **price** is taken from the item's sales price and can be changed for a discount.

SAFT calculates the **amount** of each line (quantity × price) and the **total** of the enquiry itself when saving.

### From quote to order

Set the status to *In progress* and, once the quote has been sent, to *Quoted*. When the customer accepts, create the
order under **Sales › Orders**, enter the **order** in the enquiry and set the status to *Won*. Declined enquiries get
the status *Lost*; they remain available for reports.

### Planning events

Record trade fairs and webinars under **Customers and events › Events**. The end must not be before the start. Below an
event you see all enquiries that came from it.

## Reports

- **Enquiries by status** – number and volume of open, quoted, won and lost enquiries.
- **Requested items** – which warehouse items are requested most often, with quantity and amount.
- **Won by customer** – the volume of won enquiries per customer.

## Tips

- Clicking a row shows the related data below it: enquiries and orders for a customer, the lines for an enquiry, the
  enquiries for an event.
- You change items in the **Warehouse** app. New or changed items are available in sales immediately.
- The **data model** shows how the tables are related – including the link to the warehouse items.
- The **help assistant** (**?** button at the top right) answers questions about using SAFT – including this manual.
