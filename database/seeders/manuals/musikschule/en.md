# Music school

## Overview

The **Music school** app runs a small music school: students with their contact persons, teachers, courses per
semester, lessons with attendance, rental instruments, semester invoices with payments, and concerts and recitals.
All amounts are in Swiss francs (CHF).

It is an example of a medium-sized application: 21 tables, designed with the **AI app designer** and then refined by
hand. The demo is installed from an app definition – exactly as via **Apps › Install app**.

| Menu | Tables |
|---|---|
| Teaching | Courses, Enrollments, Lessons, Attendance, Events, Performances |
| People | Students, Contact persons, Student contacts, Teachers, Teacher subjects |
| Finance & instruments | Invoices, Invoice items, Payments, Rental instruments, Rental contracts |
| Master data | Subjects, Course types (with semester fee), Semesters, Rooms, Holidays |

The sample data shows a completed semester (**HS 2026**) with lessons, invoices and payments, and one that has just
begun (**FS 2027**) with its first courses and enrollments.

## Typical tasks

### Create a course and enrol students

1. Under **Teaching › Courses**, create a course with subject, course type, teacher, room, semester, weekday and time.
   The **fee** is taken from the course type and can be adjusted on the course.
2. Under **Enrollments**, add the participants – status *active*, *waiting list* or *withdrawn*, with a discount in
   percent if needed (e.g. for siblings).
3. On the course, **enrollments**, **free places** and **occupancy** show how full it is; only active enrollments count.

### Lessons and attendance

For each lesson, record date, duration and topic, and below it the **attendance** (present, excused, unexcused). The
**hourly rate** comes from the teacher; SAFT calculates **hours** and **fee**. On the student, the **attendance rate**
shows how regularly they come.

**Master data › Holidays** lists school holidays and public holidays without lessons (a single holiday: *From* =
*To*). With the plugin **Music school: planning** you generate a semester's lessons in one go – skipping these days –
and record the attendance of a lesson on one page.

### Semester invoice

1. Under **Finance & instruments › Invoices**, create an invoice to the contact person (for minors) or to the adult
   student, with invoice date, semester and due date, first with status *Draft*. A draft has no number yet and counts
   neither as open nor as revenue. SAFT assigns the **invoice number** on the first save with status *Sent* or
   *Cancelled* (number sequence `R-2026-001`, restarting each year by invoice date); it cannot be changed afterwards,
   even if the status goes back. Discarded drafts therefore leave no gaps.
2. Below it, add an **invoice item** of type *course* for each course – SAFT takes the **unit price** from the course
   fee and deducts any discount. Rental instruments are a line of type *rental* with the number of months.
3. Record incoming **payments**; **paid** and **open** show the status. A cancelled invoice has nothing open.

Copying an invoice (e.g. for the next semester) copies its items, not its payments. Set the status to *Draft* in the
copy dialog – otherwise the copy gets a number right away.

Set the invoice to *Sent* before creating the document: only then does it have a number and an open amount for the
QR-bill. Create the invoice as PDF on the letterhead in the invoice's row with **“Create document”** (template
“Semesterrechnung”) – addressed to the contact person, otherwise to the student.
**“Store in field ‘Documents’”** is preselected: the PDF is added write-protected to the invoice's **Documents** field
(each creation as a new version). Storing needs the permission to edit invoices; the role *Musikschule* has it. **“Send by email”** sends the invoice to
the email address of the contact person, otherwise of the student – for this the role also needs *Documents: send*
(deliberately not preset).
At the bottom of the semester invoice is the **QR bill** (otherwise on a page of its own) with the open amount and the
invoice number as reference, payable by the contact person or the student. The creditor is the sender of the letterhead (demo: SIX sample
IBAN if no IBAN has been entered yet).

### Renting instruments

Record each instrument under **Rental instruments** with inventory number, condition and rental price, and the rental
under **Rental contracts** with start, end, deposit and contract (PDF).

### Concerts and recitals

Create an event under **Events** and add the **performances** below it with piece, composer, instrument and order –
this gives the programme.

## Reports

- **Occupancy per course** – enrollments, free places and occupancy.
- **Income per subject and semester** – invoiced amounts of the course items.
- **Open invoices per contact person** – who still owes how much.
- **Attendance rate per student** – attendance records, present, rate.
- **Teaching hours and fee per teacher** – the basis for payroll.

## Tips

- On the contact person, **open amount** shows the total of their open invoices.
- Siblings share their contact persons; the **invoice recipient** tick on the student contacts shows who gets the
  invoice.
- The **Music school** role has all permissions for this app – suitable for the school management or the office.
- The **help assistant** (the **?** button at the top right) answers questions about using SAFT – including this
  manual.
