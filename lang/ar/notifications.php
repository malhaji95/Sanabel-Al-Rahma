<?php

/*
 | Arabic notification templates. Bodies never carry personal data (rule 10) —
 | only a file number and a link to the relevant screen after login.
 */
return [
    /*
     | T-95 — OPS-03. Rule 10: the alert names the kind of record and its
     | number, never the record. Whoever receives it opens the audit log and
     | reads the rest there, behind their own sign-in.
     */
    'security_alert' => [
        'subject' => 'تنبيه أمني: تغيير يستدعي انتباهك',
        'body' => 'جرى تغيير من النوع :ref على السجل رقم :id. راجعه في سجل التدقيق من لوحتك: فيه من فعله ووقته والقيمة قبل وبعد.',
    ],

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
    // T-125 — الملف عاد إلى الميدان بملاحظة، لا رفضًا للأسرة.
    'case_returned' => [
        'subject' => 'الملف أُعيد لاستكماله',
        'body' => 'أُعيد الملف رقم :file_number لاستكمال ما نقص فيه. يرجى تسجيل الدخول للاطلاع على الملاحظة.',
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
    'donation_pending' => [
        'subject' => 'تبرع بانتظار التحقق',
        'body' => 'وصل تبرع برقم عملية :ref بانتظار التحقق. يرجى تسجيل الدخول لمطابقته.',
    ],
    'sponsorship_overdue' => [
        'subject' => 'قسط كفالة متأخر',
        'body' => 'تأخر قسط الكفالة المستحق بتاريخ :due_date. يرجى تسجيل الدخول للمتابعة.',
    ],
    'disbursement_disputed' => [
        'subject' => 'مبلغ لم يؤكد وصوله',
        'body' => 'الملف رقم :file_number لم يؤكد استلام مبلغ مصروف. يرجى تسجيل الدخول للمراجعة.',
    ],
    'complaint_received' => [
        'subject' => 'استلام شكوى',
        'body' => 'تم تسجيل شكواكم برقم مرجعي :reference_no. يرجى تسجيل الدخول لمتابعة الحالة.',
    ],
];
