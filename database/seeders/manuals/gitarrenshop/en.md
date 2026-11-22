# Guitar shop

## Overview

The **Guitar shop** app is a simple income and expense accounting with VAT for a small trade in single items:
instruments, accessories, services and other. All amounts are in Swiss francs (CHF).

| Table | Content |
|---|---|
| Customers | Customer number, first name, last name or company, address, country, email, newsletter yes/no, phone |
| Suppliers | Name, country, city, email, phone, website, our customer number with the supplier |
| Items | Inventory number, name, category, status, description, up to 10 images; computed: costs, revenue, result |
| Receipts | Purchase receipts and invoices: number, date, type (income/expense), customer or supplier, receipt files (PDF or image); computed: total, paid, open |
| Bookings | Lines of a receipt: item, kind, text, net, VAT rate; computed: VAT, gross, income, expense, balance |
| Payments | Payments for a receipt with date, amount, payment method and cash receipt |

## Typical tasks

### Buying goods abroad

1. Under **Accounting › Receipts** create a receipt of type *Expense*, select the **supplier** and attach its invoice as
   a PDF. New suppliers are recorded under **Master data › Suppliers**.
2. Below it, record one **booking** per item with the kind *Goods value* (VAT rate 0 %, bought abroad).
3. When the goods arrive, create a second receipt for customs: per item one booking *Customs* and one booking
   *Import VAT* (the amount from the customs receipt, rate 0 %).
4. Record the payment under **Payments** – below the receipt or in the table itself.

### Selling

Create a receipt of type *Income* with the **customer** (new customers under **Master data › Customers**) and a
booking of the kind **Sale**, the net price and the VAT rate (usually 8.1 %).
SAFT calculates VAT and gross. Set the item's status to *Sold*.

Create the customer's **invoice** in the receipt's row with **“Create document”** (template “Rechnung”): SAFT downloads
it as PDF and stores it write-protected in the receipt files. With **“Send by email”** (permission *Documents: send*)
it goes straight to the customer's email address.

At the bottom of the invoice is the **QR bill** with the open amount and the receipt number as reference; if it no
longer fits on the page, it goes on a page of its own. The creditor is the sender of the letterhead – the demo enters the
SIX sample IBAN there if no IBAN is set yet. For real invoices, enter your own IBAN (or QR-IBAN) in the letterhead.

In the dialog you can also choose **Word** as **Format** – a .docx file for editing, without QR bill; it is only
downloaded.

### Part and instalment payments

Record any number of payments for a receipt, each with its own cash receipt. On the receipt, **paid** and **open** show
what is still outstanding.

### Copying items

The quickest way to record a similar instrument is **Copy** in the item's row: all details and images are taken over,
the inventory number stays empty. Copying a receipt takes its bookings along, but not the payments.

## Important: income or expense

Whether a booking is income or an expense depends on its **kind**: *Sale* is income, all other kinds (goods value,
customs, import VAT, service, other) are expenses. The receipt type is for overview.

## Reports

- **Income and expenses by year** – with the balance (profit or loss) per year of the receipt date.
- **Overall balance** – a key figure across all bookings; positive = profit, negative = loss.
- **All bookings** – newest first, with receipt, item, kind and amounts.
- **Income and expenses by item** – costs, revenue and result per item; bookings without an item (e.g. freight) appear
  in a separate row without an item.

## Tips

- On an item, **Costs**, **Revenue** and **Result** show at once what a piece cost and brought in; its bookings are
  listed below.
- Under **Customers**, the tick **Newsletter** shows who wants to receive emails; filter by it and use **Export** to
  get the address list.
- A customer or supplier lists its receipts below.
- The role **Gitarrenshop** has all permissions in this app – suitable for the person running the shop.
- The **help assistant** (**?** button at the top right) answers questions about using SAFT – including this manual.
