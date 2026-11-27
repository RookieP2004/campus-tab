<?php

/*
| Tests and marks (Phase 6). The school's own rules live in its assessment
| schemes (the Principal changes everything) and in the school config under
| policies.marks. These are code-level numbers and the optional starter
| presets. See docs/report/configuration.md.
|
| Presets are STARTING POINTS ONLY (owner's rule): every screen shows them
| with "Check against your board's latest circular", and nothing in a
| preset is fixed. Boards change their schemes; the school is responsible
| for matching its board.
*/

return [

    // Codes a teacher may type instead of a mark.
    'codes' => [
        'AB' => 'Absent',
        'ML' => 'Medical leave',
    ],

    // Marks may be whole or end in .5 (for example 32.5).
    'mark_step' => 0.5,

    // A remark beside a mark.
    'remark_max' => 200,

    // A correction's reason, and the Principal's typed reason to approve it.
    'reason_min' => 10,

    'types' => ['weekly', 'monthly', 'periodic', 'half_yearly', 'annual', 'practical', 'project', 'internal'],

    // Class tests a Subject Teacher creates for their own sections.
    'class_test_types' => ['weekly', 'monthly'],

    'presets' => [
        'cbse_6_8' => [
            'label' => 'CBSE-style, Classes VI to VIII',
            'note' => 'A starting point only. Check against your board\'s latest circular and change anything that differs.',
            'terms' => ['Term 1', 'Term 2'],
            'components' => [
                ['key' => 'pt', 'name' => 'Periodic tests', 'max' => 10, 'weight' => 10],
                ['key' => 'notebook', 'name' => 'Notebook', 'max' => 5, 'weight' => 5],
                ['key' => 'enrichment', 'name' => 'Subject enrichment', 'max' => 5, 'weight' => 5],
                ['key' => 'exam', 'name' => 'Term exam', 'max' => 80, 'weight' => 80],
            ],
            'grade_scale' => [
                ['grade' => 'A1', 'min' => 91], ['grade' => 'A2', 'min' => 81], ['grade' => 'B1', 'min' => 71], ['grade' => 'B2', 'min' => 61],
                ['grade' => 'C1', 'min' => 51], ['grade' => 'C2', 'min' => 41], ['grade' => 'D', 'min' => 33], ['grade' => 'E', 'min' => 0],
            ],
            'rules' => ['pass_percent' => 33, 'rounding' => 'half_up', 'best_of' => null, 'grace_marks' => 0],
        ],
        'cbse_9_10' => [
            'label' => 'CBSE-style, Classes IX and X',
            'note' => 'A starting point only. Check against your board\'s latest circular and change anything that differs.',
            'terms' => ['Year'],
            'components' => [
                ['key' => 'internal', 'name' => 'Internal assessment', 'max' => 20, 'weight' => 20],
                ['key' => 'board', 'name' => 'Board-pattern exam', 'max' => 80, 'weight' => 80],
            ],
            'grade_scale' => [
                ['grade' => 'A1', 'min' => 91], ['grade' => 'A2', 'min' => 81], ['grade' => 'B1', 'min' => 71], ['grade' => 'B2', 'min' => 61],
                ['grade' => 'C1', 'min' => 51], ['grade' => 'C2', 'min' => 41], ['grade' => 'D', 'min' => 33], ['grade' => 'E', 'min' => 0],
            ],
            'rules' => ['pass_percent' => 33, 'rounding' => 'half_up', 'best_of' => null, 'grace_marks' => 0],
        ],
        'generic' => [
            'label' => 'Simple two-term scheme',
            'note' => 'A starting point only. Check against your board\'s latest circular and change anything that differs.',
            'terms' => ['Term 1', 'Term 2'],
            'components' => [
                ['key' => 'tests', 'name' => 'Class tests', 'max' => 20, 'weight' => 20],
                ['key' => 'exam', 'name' => 'Term exam', 'max' => 80, 'weight' => 80],
            ],
            'grade_scale' => [
                ['grade' => 'A', 'min' => 80], ['grade' => 'B', 'min' => 60], ['grade' => 'C', 'min' => 45], ['grade' => 'D', 'min' => 33], ['grade' => 'E', 'min' => 0],
            ],
            'rules' => ['pass_percent' => 33, 'rounding' => 'half_up', 'best_of' => null, 'grace_marks' => 0],
        ],
    ],

];
