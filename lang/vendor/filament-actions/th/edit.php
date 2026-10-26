<?php

/*
 * แก้คำแปลของ Filament ที่ไม่เว้นวรรคก่อน :label ("แก้ไขแพ็กเกจ" → "แก้ไข แพ็กเกจ")
 * ให้ตรงกับ "แก้ไข :label" ของหน้า edit-record และ "ลบ :label" — key อื่นยังใช้ของ vendor
 */
return [

    'single' => [

        'modal' => [

            'heading' => 'แก้ไข :label',

        ],

    ],

];
