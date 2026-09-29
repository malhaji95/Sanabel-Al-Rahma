<?php

/*
 | Arabic notification templates. Bodies never carry personal data (rule 10) —
 | only a file number and a link to the relevant screen after login.
 */
return [
    'donation_verified' => [
        'subject' => 'تم التحقق من تبرعكم',
        'body' => 'تم التحقق من تبرعكم رقم :ref. شكراً لدعمكم. للاطلاع على التفاصيل يرجى تسجيل الدخول.',
    ],
    'donation_rejected' => [
        'subject' => 'تعذر التحقق من التبرع',
        'body' => 'تعذر التحقق من التبرع رقم :ref. السبب: :reason. يرجى تسجيل الدخول للمراجعة.',
    ],
    'case_approved' => [
        'subject' => 'تم اعتماد الملف',
        'body' => 'تم اعتماد الملف رقم :file_number. يرجى تسجيل الدخول للاطلاع.',
    ],
    'case_rejected' => [
        'subject' => 'تم رفض الملف',
        'body' => 'تم رفض الملف رقم :file_number. يرجى تسجيل الدخول للاطلاع على السبب.',
    ],
    'coverage_updated' => [
        'subject' => 'تحديث التغطية',
        'body' => 'تم تحديث نسبة تغطية الملف رقم :file_number. يرجى تسجيل الدخول للاطلاع.',
    ],
    'sponsorship_due' => [
        'subject' => 'تذكير بقسط الكفالة',
        'body' => 'يستحق قسط الكفالة عن الفترة :period بتاريخ :due_date. يرجى تسجيل الدخول للمتابعة.',
    ],
    'sponsorship_lapsed' => [
        'subject' => 'توقف الكفالة',
        'body' => 'تم إيقاف الكفالة رقم :id لعدم سداد قسطين متتاليين. يرجى تسجيل الدخول للمراجعة.',
    ],
    'reassessment_due' => [
        'subject' => 'إعادة تقييم مستحقة',
        'body' => 'الملف رقم :file_number بحاجة إلى إعادة تقييم. يرجى تسجيل الدخول لجدولة زيارة.',
    ],
    'distribution_executed' => [
        'subject' => 'تنفيذ توزيع',
        'body' => 'تم تنفيذ توزيع يخص الملف رقم :file_number. يرجى تسجيل الدخول للاطلاع.',
    ],
    'delivery_confirmed' => [
        'subject' => 'تأكيد التسليم',
        'body' => 'تم تأكيد تسليم المساعدة للملف رقم :file_number. يرجى تسجيل الدخول للاطلاع.',
    ],
    'basket_expiring' => [
        'subject' => 'قرب انتهاء مدة الحجز',
        'body' => 'ينتهي حجز سلتكم بتاريخ :expires_at. يرجى تسجيل الدخول لإتمام التبرع.',
    ],
    'complaint_received' => [
        'subject' => 'استلام شكوى',
        'body' => 'تم تسجيل شكواكم برقم مرجعي :reference_no. يرجى تسجيل الدخول لمتابعة الحالة.',
    ],
];
