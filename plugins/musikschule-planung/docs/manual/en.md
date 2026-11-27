# Music school: planning – user guide

## Overview

The plugin *Music school: planning* adds the following to the **Music school** app:

- the **timetable** per teacher, room or student,
- a **conflict check** when a course is saved,
- the **semester rollover**: copy courses and enrollments to the next semester,
- **generate lessons**: create a semester's weekly lessons in one go, skipping holidays,
- **record attendance**: all enrolled students of a lesson on one page,
- a **waiting-list notice** as soon as a place becomes free in a course.

Generating lessons and recording attendance need the tables *Lessons* and *Attendance*; the table **Holidays** exists
from version 1.1 of the app.

A course's duration comes from its **course type** (field *Duration (minutes)*); without one, 45 minutes apply.

## Timetable

Under **Music school › Teaching › Timetable**, choose the **semester** (default: the current one), the **view** –
*teacher*, *room* or *student* – and then the person or room.

- Each course appears as a **coloured block** on its weekday, from start to end; the colour stands for the subject
  (legend below the grid).
- Hover over a block to see time, subject, occupancy (e.g. “3 of 8 places taken”), the number on the waiting list and
  the participants' names. A **click** opens the course in the table *Courses*,
  with enrollments and lessons below it.
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

## Generating lessons

Instead of recording each lesson individually, SAFT creates them **weekly** for a period – on the course's weekday,
with its teacher and the duration of its course type. The dialog **Generate lessons** is available in four places:

- in a **course's** row (this course only),
- in a **semester's** row (all its courses),
- above the table **Courses** (all courses of the chosen semester, default: the current one).
- for the **selected courses**: tick courses in the table **Courses**, then **Generate lessons** in the bar above the
  table (the period follows their common semester, or the current one if they belong to several).

In the dialog, choose **From** and **To** – by default from today if the semester is running, otherwise from the start
of the semester, until its end – and whether to **skip holidays**. The **preview** tells you beforehand what will
happen, e.g. “8 lessons in 1 course. 2 dates fall in holidays. 3 dates already have a lesson.”

- Lessons are only created within the course's semester, even if the period extends beyond it.
- Dates that already have a lesson are skipped – so you can **repeat** the dialog without duplicates, e.g. after adding
  a new course.
- Courses without a weekday are skipped and named.
- It is **all or nothing**: if one lesson fails, none is created.

Record **holidays** under **Music school › Master data › Holidays** with name, *From* and *To*; a single public holiday
has *From* = *To*.

## Recording attendance

Under **Music school › Teaching › Record attendance** – or with the clipboard icon in a **lesson's** row – you record
the attendance of a lesson on one page.

- At the top, choose the **lesson**; the list shows the lessons of its semester, e.g. “Mo 17.08.2026 · Klavier Mia ·
  Mia Huber”. Without a preset, today's next lesson is chosen, otherwise the most recent past one.
- Below are all **actively enrolled** students of the course with three buttons *present* (default), *excused* and
  *unexcused* and a **remark**. **All present** resets all rows to *present*.
- Attendance already recorded is loaded – also for students who are no longer actively enrolled (marked as such).
- **Save** creates missing attendance records and changes modified ones; nothing is created twice. The message says how
  many are new and how many were changed.

## Waiting-list notice

When a **place becomes free** in a course and there are enrollments on the **waiting list**, you get a notice in the
bell, e.g. “Klavier Mia: 1 place free” – with those waiting in the order of their enrollment date and the button **View
enrollments**, which opens the course. A place becomes free when

- an active enrollment is set to *withdrawn* or *waiting list*,
- an active enrollment is deleted or
- the course's **maximum** number of participants rises.

Nobody moves up automatically: agree with the families who gets the place, then set its status to *active*.

## Permissions

| Permission | Effect |
|---|---|
| Music school: planning: View timetable | page *Timetable* (plus the permission to view courses) |
| Music school: planning: Roll over semester | buttons *Roll over semester* (plus the permission to create courses and enrollments) |
| Music school: planning: Generate lessons | buttons *Generate lessons* (plus the permission to create lessons) |
| Music school: planning: Record attendance | page *Record attendance* (plus the permission to view attendance; to save, to create and edit it) |

The conflict check and the waiting-list notice apply to everyone who edits courses and enrollments – they need no
permission of their own.
