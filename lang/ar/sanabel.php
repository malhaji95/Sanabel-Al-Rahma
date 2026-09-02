<?php

return [
    'app_name' => 'سنابل الرحمة',

    'nav' => [
        'reference' => 'البيانات المرجعية',
        'beneficiaries' => 'الملفات',
        'assessment' => 'التقييم',
        'money' => 'التبرعات',
        'modules' => 'الخدمات',
        'content' => 'المحتوى',
        'system' => 'النظام',
    ],

    'person_class' => [
        'adult' => 'بالغ',
        'child' => 'طفل',
        'elderly' => 'مسن',
    ],

    'status' => [
        'draft' => 'مسودة',
        'pending_visit' => 'بانتظار الزيارة',
        'verified' => 'تم التحقق الميداني',
        'pending_approval' => 'بانتظار الاعتماد',
        'approved' => 'معتمد',
        'published' => 'منشور',
        'needs_reassessment' => 'يحتاج إعادة تقييم',
        'suspended' => 'موقوف',
        'graduated' => 'متخرج',
        'rejected' => 'مرفوض',
        'merged' => 'مدموج',
    ],

    'donations' => [
        'duplicate_ref' => 'رقم الحوالة مستخدم من قبل. يرجى المراجعة قبل المتابعة.',
        'verified_immutable' => 'لا يمكن تعديل تبرع تم التحقق منه. يتم إنشاء قيد عكسي بدلاً من ذلك.',
        'membership_fund_blocked' => 'أموال العضويات لا يمكن تخصيصها لأي أسرة.',
        'pending' => 'قيد المراجعة',
        'verified' => 'تم التحقق',
        'rejected' => 'مرفوض',
        'reversed' => 'معكوس',
    ],

    'basket' => [
        'hold_expired' => 'انتهت مدة الحجز. يرجى إعادة اختيار الأسر.',
        'exceeds_remaining' => 'المبلغ يتجاوز حاجة الأسرة المتبقية.',
        'already_reserved' => 'تم حجز هذه الأسرة من متبرع آخر. يرجى اختيار أسرة أخرى.',
        'empty' => 'السلة فارغة.',
    ],

    'campaigns' => [
        'surplus_policy_required' => 'لا يمكن نشر الحملة قبل إدخال سياسة الفائض.',
    ],

    'sponsorships' => [
        'end_date_required' => 'تاريخ البداية وتاريخ النهاية مطلوبان.',
    ],

    'distributions' => [
        'list_frozen' => 'قائمة التوزيع مجمّدة بعد الاعتماد ولا يمكن إعادة توليدها.',
    ],

    'complaints' => [
        'owner_conflict' => 'لا يمكن إسناد الشكوى إلى الشخص المشكو منه.',
    ],

    'cases' => [
        'duplicate_national_id' => 'يوجد ملف مسجل بنفس الرقم الوطني.',
        'self_approval_blocked' => 'لا يمكن لمنشئ الملف اعتماده نهائياً.',
        'close_requires_proof' => 'لا يمكن إغلاق الملف قبل تسجيل إثبات تسليم.',
        'reject_reason_required' => 'سبب الرفض مطلوب.',
    ],

    'referrals' => [
        'expired' => 'بطاقة الإحالة منتهية الصلاحية.',
        'already_used' => 'بطاقة الإحالة مستخدمة مسبقاً.',
        'revoked' => 'بطاقة الإحالة ملغاة.',
    ],

    'masked' => [
        'urgency' => [
            'none' => 'غير عاجل',
            'low' => 'أولوية منخفضة',
            'medium' => 'أولوية متوسطة',
            'high' => 'أولوية عالية',
            'critical' => 'حالة طارئة',
        ],
        'need_type' => [
            'monthly' => 'دعم شهري',
            'one_time' => 'دعم لمرة واحدة',
        ],
        'health' => 'مرض مزمن',
        'rent_band' => [
            'none' => 'بدون إيجار',
            'low' => 'إيجار منخفض',
            'medium' => 'إيجار متوسط',
            'high' => 'إيجار مرتفع',
        ],
        'age_band' => [
            'child' => 'أطفال',
            'adult' => 'بالغون',
            'elderly' => 'مسنون',
        ],
    ],

    'coordination' => [
        'registered' => 'مسجل لدى الجمعية',
        'has_active_assessment' => 'لديه تقييم ساري',
        'supported_this_period' => 'مدعوم لهذا النوع خلال الفترة',
        'coverage' => 'نسبة التغطية',
        'coverage_none' => 'لا يوجد',
        'coverage_partial' => 'جزئي',
        'coverage_full' => 'كامل',
    ],

    'permissions' => [
        'denied' => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
        'read_only' => 'هذا الحساب للاطلاع فقط ولا يملك أي صلاحية كتابة.',
        'keys' => [
            'create_case' => 'إنشاء ملف',
            'edit_draft' => 'تعديل مسودة',
            'upload_media' => 'رفع مرفقات',
            'record_visit' => 'تسجيل زيارة',
            'recommend' => 'التوصية',
            'approve_case' => 'اعتماد/رفض/نشر',
            'suspend_graduate' => 'إيقاف/تخريج',
            'override_score' => 'تعديل الدرجة',
            'edit_config' => 'تعديل الإعدادات والمراجع',
            'request_change' => 'طلب تعديل',
            'approve_change' => 'اعتماد التعديل',
            'merge_duplicates' => 'دمج المكرر',
            'donate' => 'التبرع والسلة والكفالة',
            'verify_payment' => 'التحقق من الدفع',
            'manage_campaigns' => 'إدارة الحملات',
            'manage_distribution' => 'إنشاء وتنفيذ التوزيع',
            'confirm_delivery' => 'تأكيد التسليم',
            'manage_own_offers' => 'إدارة العروض',
            'verify_referral' => 'التحقق من بطاقة الإحالة',
            'publish_job_profile' => 'نشر ملف مهني',
            'manage_members' => 'إدارة العضويات',
            'file_complaint' => 'تقديم شكوى',
            'handle_complaint' => 'معالجة الشكاوى',
            'manage_cms' => 'إدارة المحتوى',
            'manage_users' => 'إدارة المستخدمين والأدوار',
            'view_full_case' => 'عرض الملف الكامل',
            'view_masked_case' => 'عرض الملف المقنّع',
            'search_by_national_id' => 'البحث بالرقم الوطني',
            'browse_job_market' => 'تصفح سوق العمل',
            'view_reports' => 'عرض التقارير',
        ],
    ],
];
