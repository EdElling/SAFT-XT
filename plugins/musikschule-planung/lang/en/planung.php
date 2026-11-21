<?php

declare(strict_types=1);

return [
    'plugin' => 'Music school: planning',
    'description' => 'Timetable per teacher, room or student, conflict check when saving a course, and semester rollover (example for the "Music school" demo).',
    'permissions' => [
        'timetable' => 'View timetable',
        'rollover' => 'Roll over semester',
    ],
    'days' => ['mo' => 'Monday', 'di' => 'Tuesday', 'mi' => 'Wednesday', 'do' => 'Thursday', 'fr' => 'Friday', 'sa' => 'Saturday', 'so' => 'Sunday'],
    'conflict' => [
        'teacher' => ':name already teaches ":course" on :day :from–:to.',
        'room' => ':name is already booked for ":course" on :day :from–:to.',
    ],
    'capacity' => [
        'title' => 'Room too small',
        'body' => '":course" allows :max participants, :room has only :places places.',
    ],
    'rollover' => [
        'action' => 'Roll over semester',
        'heading' => 'Copy courses to the next semester',
        'submit' => 'Roll over',
        'source' => 'From semester',
        'target' => 'To semester',
        'keep_schedule' => 'Keep weekday, time and room',
        'keep_schedule_help' => 'Otherwise they stay empty and are planned in the new semester.',
        'enrolments' => 'Take active enrollments along',
        'enrolments_help' => 'As waiting list – confirm them by setting the status to "active".',
        'new_tariff' => 'Fee from the course type',
        'new_tariff_help' => 'Otherwise the fee of the previous course applies.',
        'choose' => 'Please choose source and target.',
        'same' => 'Source and target must be different semesters.',
        'preview' => ':courses courses and :enrolments enrollments will be copied.',
        'preview_skipped' => ':count courses already exist in the target and are skipped: :names.',
        'done' => ':courses courses and :enrolments enrollments copied to :target.',
        'failed' => 'Rollover not possible – nothing was copied.',
        'rights' => 'You lack the permission to create records in: :tables.',
    ],
    'timetable' => [
        'title' => 'Timetable',
        'semester' => 'Semester',
        'view' => 'View',
        'views' => ['teacher' => 'Teacher', 'room' => 'Room', 'student' => 'Student'],
        'choice' => ['lehrperson' => 'Teacher', 'raum' => 'Room', 'schueler' => 'Student'],
        'tooltip' => ':count of :max places taken',
        'conflicts' => ':count courses overlap (outlined in red).',
        'empty' => 'No courses in this semester.',
        'unscheduled' => 'Without weekday or time',
        'print' => 'Print',
        'unavailable_title' => 'Music school app incomplete',
        'unavailable' => 'Missing:',
        'no_rights' => 'You may not view the courses.',
    ],
];
