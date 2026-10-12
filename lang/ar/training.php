<?php

/*
 | The words around the training guides. The guides themselves are data, in
 | resources/training/guides.php.
 */

return [
    'title' => 'جولة في النظام',
    'for_role' => 'دليل :role',
    'what_you_do' => 'ما تفعله هنا',
    'what_is_refused' => 'ما يرفضه النظام، ولماذا',
    'where_it_goes' => 'إلى مَن تصل الخطوة بعدك',
    'step' => 'الخطوة :number من :total',
    'account' => 'حساب التدريب',
    'password_note' => 'كلمة المرور التجريبية عند مسؤول النظام. هذه نسخة تدريب على بيانات مُولَّدة، ولا توجد فيها أسرة حقيقية واحدة.',
    'no_screenshot' => 'لم تُلتقط صورة هذه الشاشة في هذه الجولة.',
    'generated' => 'وُلِّد هذا الدليل من النظام نفسه في :date، من إصدار الكود :commit. وكل صورة فيه لقطةٌ من الشاشة التي يراها هذا الحساب فعلًا، لا رسمًا توضيحيًّا.',
    'footer_rule' => 'المنع في هذا الدليل مبنيٌّ في النظام لا مكتوبًا في تعليمات: ما يقول الدليل إنه مرفوض، مرفوضٌ من الشاشة ومن الواجهة البرمجية بالتساوي.',
    'contents' => 'خطوات الدليل',

    'cli' => [
        'capturing' => 'تصوير الشاشات، حساب حساب…',
        'capture_failed' => 'فشل التصوير. تأكّد أن Playwright متاح على NODE_PATH.',
        'not_serving' => 'التطبيق غير مُشغَّل. شغّل php artisan serve ثم أعِد الأمر.',
        'no_such_role' => 'لا دور بهذا الاسم في resources/training/guides.php.',
        'no_record' => 'الخطوة :step تحتاج سجلًّا لا يوجد (:path). شغّل TrainingStageSeeder.',
        'written' => 'دليل :role في :path',
        'missed' => 'لم تُلتقط :count شاشة. الدليل مكتوب، لكنه ناقص حتى تُصلَح:',
    ],
];
