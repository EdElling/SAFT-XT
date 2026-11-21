# Music school: planning – user guide

## Overview

The plugin *Music school: planning* adds three things to the **Music school** app:

- the **timetable** per teacher, room or student,
- a **conflict check** when a course is saved,
- the **semester rollover**: copy courses and enrollments to the next semester.

A course's duration comes from its **course type** (field *Duration (minutes)*); without one, 45 minutes apply.

## Timetable

Under **Music school › Teaching › Timetable**, choose the **semester** (default: the current one), the **view** –
*teacher*, *room* or *student* – and then the person or room.

- Each course appears as a **coloured block** on its weekday, from start to end; the colour stands for the subject
  (legend below the grid).
- Hover over a block to see time, subject, occupancy (e.g. “3 of 8 places taken”) and the participants' names. A
  **click** opens the course in the table *Courses*, with enrollments and lessons below it.
- The *student* view shows the courses with an active enrollment.
- **Overlaps** – two courses at the same time – are shown side by side and **outlined in red**; above the grid it says
  how many there are.
- Courses without weekday or time are listed below the grid.
- **Print** outputs heading, grid and legend on A4 landscape.

## Conflict check

When you save a course, SAFT checks in the **same semester** on the **same weekday** whether its time overlaps with
another course that

- has the same **teacher** or
- takes place in the same **room**.

If so, the course is **not saved** and the message names the other course, e.g. “Raum 1 is already booked for ‘Klavier
Mia’ on Monday 16:00–16:45.” Adjacent courses (16:00–16:45 and 16:45–17:15) are allowed. The check runs when a course is
created and when weekday, time, course type, teacher, room or semester change – also on import and during the semester
rollover.

If a course allows **more participants** than its room has **places**, it is still saved; you get a notice in the bell.

## Rolling over a semester

Under **Music school › Master data › Semesters**, copy the courses of one semester to another:

- in a semester's row with the icon **Roll over semester** (that semester is preset as the source) or
- above the table with **Roll over semester** (source: the current semester).

In the dialog, choose the **target** (default: the next semester) and:

| Option | Default | Effect |
|---|---|---|
| Keep weekday, time and room | on | otherwise they stay empty and are planned in the new semester |
| Take active enrollments along | on | as **waiting list**, dated today, with the previous discount |
| Fee from the course type | on | otherwise the fee of the previous course applies |

The **preview** in the dialog says how many courses and enrollments will be copied. Courses with the same name that
already exist in the target are **skipped** and named – so a second run creates nothing twice.

The rollover is **all or nothing**: if the conflict check rejects a course (e.g. because a room is already booked in the
target), nothing is copied and the message gives the reason.

**After the rollover:** confirm the enrollments under **Teaching › Enrollments** by changing the status from *waiting
list* to *active*; those who do not continue are set to *withdrawn*.

## Permissions

| Permission | Effect |
|---|---|
| Music school: planning: View timetable | page *Timetable* (plus the permission to view courses) |
| Music school: planning: Roll over semester | buttons *Roll over semester* (plus the permission to create courses and enrollments) |

The conflict check applies to everyone who edits courses – it needs no permission of its own.
