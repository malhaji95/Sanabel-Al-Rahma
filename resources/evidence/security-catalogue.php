<?php

/*
 | The association's security file, item by item, as data.
 |
 | The file (SEC-ROADMAP, 29 Sep 2026) numbers 58 implementation items and 15
 | acceptance tests, and says plainly: «يمنع اعتماد الحالة «تم» دون دليل». So
 | every item here carries a verdict we can be held to, and `php artisan
 | evidence:report` ties each one to the tests that actually ran.
 |
 | scope  — whose court the item is in:
 |          app      our code, and ours to evidence
 |          hosting  settled by the hosting decision, not by code
 |          client   the association's own act (a policy, a contract, a budget)
 |          phase2   the file asks for something phase 1 does not contain
 | state  — done | partial | pending | not_applicable
 | gap    — what is still missing when the state is not `done`
 | tests  — set by `->group('ID')` in the test files, never listed here, so a
 |          deleted test shows up as a missing proof instead of a stale claim.
 */

return [

    'sections' => [
        'IAM' => 'الهوية وإدارة الوصول',
        'AUTH' => 'الصلاحيات ومنع تجاوزها',
        'APP' => 'أمان التطبيق',
        'FILE' => 'حماية المستندات والوسائط',
        'DATA' => 'عزل البيانات وتشفيرها',
        'PAY' => 'المسار المالي',
        'INF' => 'البنية التحتية والشبكة',
        'CICD' => 'سلسلة البناء والنشر',
        'OPS' => 'التشغيل والمراقبة والحوادث',
        'PERF' => 'الأحمال والتوسع',
        'GOV' => 'الالتزام والحكامة',
    ],

    'items' => [

        /* 01 — الهوية وإدارة الوصول */

        'IAM-01' => [
            'title' => 'هوية موظفين مستقلة وMFA',
            'owner' => 'Backend + DevOps',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'التحقق بخطوتين قائم وملزم للإدارة والمجلس ومدير المؤسسة. وما قام: الربط بمزود هوية خارجي (SSO) ومفاتيح الأمان المقاومة للتصيد، وكلاهما يتوقف على قرار الاستضافة.',
        ],
        'IAM-02' => [
            'title' => 'جلسات وتسجيل دخول آمن',
            'owner' => 'Backend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'خصائص الجلسة وتشفيرها ومهلتها مضبوطة ومختبرة. وما قام: إبطال الجلسات القائمة عند تغيير كلمة المرور أو تعطيل الحساب.',
        ],
        'IAM-03' => [
            'title' => 'حماية الاسترداد ومحاولات الدخول',
            'owner' => 'Backend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'تحديد معدل المحاولات قائم على الحساب وعلى العنوان معًا. وما قام: اختبار رسائل الاسترداد بأنها لا تكشف وجود الحساب، ورمز استرداد أحادي الاستخدام.',
        ],
        'IAM-04' => [
            'title' => 'وصول إداري مقيّد ومؤقت',
            'owner' => 'DevOps + Security',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'بوابة شبكة خاصة أمام لوحات الإدارة. لا يُنفَّذ في الكود، بل في إعداد الاستضافة بعد اختيارها.',
        ],
        'IAM-05' => [
            'title' => 'دورة حياة الحساب والصلاحية',
            'owner' => 'Owner + Security',
            'priority' => 'RUN',
            'scope' => 'client',
            'state' => 'pending',
            'gap' => 'مراجعة دورية للحسابات والصلاحيات. النظام يعرض المصفوفة ويسجّل كل تغيير صلاحية؛ وموعد المراجعة ومن يوقّعها قرار إداري.',
        ],

        /* 02 — الصلاحيات */

        'AUTH-01' => [
            'title' => 'مصفوفة أدوار وصلاحيات',
            'owner' => 'Owner + Backend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'AUTH-02' => [
            'title' => 'فحص ملكية السجل في كل API',
            'owner' => 'Backend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'AUTH-03' => [
            'title' => 'صلاحيات الحقول والتعديل الجماعي',
            'owner' => 'Backend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'التعديل الجماعي مُقفل: كل طلب تعديل يمر على قائمة حقول مسموحة، وأعمدة القرار خارجها. وصنف الزكاة صار قرارًا محصورًا بمن يملك اعتماد الملفات، والمنع في النموذج لا في الشاشة. وما قام: بقيّة صلاحيات الحقول على مستوى الدور، وهي موقوفة على مصفوفة الحقول التي طلبناها من المؤسسة.',
        ],
        'AUTH-04' => [
            'title' => 'التصدير والبحث الشامل',
            'owner' => 'Backend + Security',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'كل قائمة مُصفحة بحد أعلى ومحصورة بنطاق المستخدم، ومخرجات المتبرع مقنّعة. وما قام: تسجيل كل تصدير في سجل التدقيق وتقييد حجمه.',
        ],
        'AUTH-05' => [
            'title' => 'الفصل بين المالية والمستفيدين',
            'owner' => 'Backend + Owner',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],

        /* 03 — أمان التطبيق */

        'APP-01' => [
            'title' => 'مصفوفة ASVS والتحقق من المنطق',
            'owner' => 'Security + Backend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'منطق العمل مُختبَر بالكامل (الاعتماد، والتسعير، والتغطية، والصرف). وما قام: وثيقة المقابلة مع ASVS مستوى 2 بندًا ببند، وتنتظر إذنًا بالكتابة في docs/.',
        ],
        'APP-02' => [
            'title' => 'منع Injection وXSS',
            'owner' => 'Backend + Frontend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'لا استعلام نصي في النظام كله، والعرض مُهرَّب افتراضيًا، وسياسة المحتوى تمنع أي نص برمجي من خارج النطاق. وما قام: فحص آلي للحقن في خط البناء، وهو بند CICD-02.',
        ],
        'APP-03' => [
            'title' => 'API Inventory وSchema Validation',
            'owner' => 'Backend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'APP-04' => [
            'title' => 'Rate Limits ومنع إساءة الاستخدام',
            'owner' => 'Backend + DevOps',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'APP-05' => [
            'title' => 'الحد من SSRF والاتصال الصادر',
            'owner' => 'Backend + DevOps',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'partial',
            'gap' => 'النظام لا يأخذ عنوانًا من المستخدم ولا يطلبه، فلا مدخل لـ SSRF من التطبيق. وما قام: قائمة الوجهات المسموحة على مستوى الشبكة، وهي إعداد استضافة.',
        ],
        'APP-06' => [
            'title' => 'أمان المتصفح وTLS',
            'owner' => 'Frontend + DevOps',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'APP-07' => [
            'title' => 'منع تسرب Cache',
            'owner' => 'Frontend + DevOps',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],

        /* 04 — المستندات والوسائط */

        'FILE-01' => [
            'title' => 'رفع وعزل المستندات',
            'owner' => 'Backend + DevOps',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'FILE-02' => [
            'title' => 'فحص واعتماد آمن',
            'owner' => 'Backend + Security',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'النظام يحجز كل مرفوع جديد ولا يخدمه قبل اعتماده، والمفتاح جاهز. وما قام: ربط الفاحص الخارجي نفسه، ويتوقف على الاستضافة.',
        ],
        'FILE-03' => [
            'title' => 'التنزيل المؤقّت والمصرّح',
            'owner' => 'Backend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'FILE-04' => [
            'title' => 'الاحتفاظ والحذف القانوني',
            'owner' => 'Owner + Backend',
            'priority' => 'RUN',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'إخفاء هوية الأسرة وإتلاف أوراقها مع بقاء السجل منفَّذ ومختبَر. ومدة الاحتفاظ صارت خمس سنوات تُحتسب من آخر نشاط على الملف، وهي إعداد يُغيَّر من الشاشة بلا نشر، والطمس محصور برئيس مجلس الأمناء ونائبه والمدير التنفيذي ونائبه ويلزمه شخصان، ومدير النظام خارجهم ببوابة تُفتح عند الحاجة ولا يفتحها لنفسه. وسياسة الخصوصية صارت معتمدة ومنشورة بنسخة 1.0 بتاريخ 13 تشرين الأول، وتنصّ على الاحتفاظ بالمدة التي تفرضها الأنظمة ثم الحذف أو الإتلاف أو الإخفاء. وما قام: المادة القانونية السورية نفسها، وأفادت المؤسسة أنه لا آلية محددة بعد وأنها تسعى لمستشار قانوني، فالسياسة معتمدة قبل مراجعته.',
        ],

        /* 05 — عزل البيانات وتشفيرها */

        'DATA-01' => [
            'title' => 'فصل المجالات الأمنية',
            'owner' => 'Architect + DevOps + Backend',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'فصل شبكات الواجهة العامة عن قاعدة البيانات والوسائط. إعداد استضافة، وأعددنا له في فصل مخازن redis عن بعضها.',
        ],
        'DATA-02' => [
            'title' => 'قواعد بيانات خاصة',
            'owner' => 'DevOps + DBA',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'قاعدة بيانات غير منشورة على الإنترنت بهوية خدمة مستقلة. في مقترح الاستضافة.',
        ],
        'DATA-03' => [
            'title' => 'أقل صلاحية على الجداول',
            'owner' => 'DBA + Backend',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'حساب قاعدة بيانات للتطبيق بلا صلاحية تعديل البنية. يُضبط عند التركيب.',
        ],
        'DATA-04' => [
            'title' => 'تشفير ومفاتيح مستقلة',
            'owner' => 'DevOps + Security',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'الأرقام الوطنية والهواتف والمحافظ والتقارير الطبية مشفّرة في العمود نفسه ومخفية عن أي مخرج. وما قام: حفظ المفتاح في خدمة مفاتيح مُدارة بدل متغير بيئة، وتدويره، وكلاهما يتوقف على الاستضافة.',
        ],
        'DATA-05' => [
            'title' => 'النسخ المعزولة من الاختراق',
            'owner' => 'DevOps + Security',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'نسخ غير قابلة للحذف من حساب الإنتاج. في مقترح الاستضافة.',
        ],
        'DATA-06' => [
            'title' => 'إخفاء بيانات غير الإنتاج',
            'owner' => 'DBA + QA',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'DATA-07' => [
            'title' => 'التعافي عند اختراق شامل',
            'owner' => 'Architect + Security',
            'priority' => 'P1',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'خطة إعادة بناء البيئة من الصفر. تُكتب بعد اختيار الاستضافة.',
        ],

        /* 06 — المسار المالي */

        'PAY-01' => [
            'title' => 'بوابة دفع وتقليل نطاق البطاقات',
            'owner' => 'Backend + Finance',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'PAY-02' => [
            'title' => 'Webhook آمن وموثوق',
            'owner' => 'Backend',
            'priority' => 'P0',
            'scope' => 'phase2',
            'state' => 'not_applicable',
            'gap' => 'لا يوجد في المرحلة الأولى أي تحويل آلي ولا مزود دفع، فلا Webhook يُستقبل. البند يسري يوم تُربط بوابة دفع، والواجهة جاهزة لها بمشغّل واحد.',
        ],
        'PAY-03' => [
            'title' => 'Idempotency وقيود مالية',
            'owner' => 'Backend + DBA',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'PAY-04' => [
            'title' => 'تسوية واسترداد وقيود تدقيق',
            'owner' => 'Finance + Backend',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'التبرع المؤكَّد لا يُعدَّل أبدًا، والتصحيح يُنشئ صفًّا معاكسًا مرتبطًا، وكل خطوة في سجل التدقيق. وما قام: شاشة المطابقة مع كشف البنك، وتنتظر نموذج كشف.',
        ],
        'PAY-05' => [
            'title' => 'موافقات الصرف وتغيير البنك',
            'owner' => 'Finance + Backend + Security',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'PAY-06' => [
            'title' => 'حماية صفحات الدفع من الحقن',
            'owner' => 'Frontend + Security',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'سياسة المحتوى سارية على كل صفحة بلا استثناء، ولا طرف ثالث مسموح. وما قام: ما لا يسري بعد، إذ لا صفحة دفع في المرحلة الأولى.',
        ],

        /* 07 — البنية التحتية */

        'INF-01' => [
            'title' => 'CDN وWAF وDDoS',
            'owner' => 'DevOps',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'في مقترح الاستضافة، مع التكلفة الشهرية لكل خيار.',
        ],
        'INF-02' => [
            'title' => 'منع تجاوز Origin',
            'owner' => 'DevOps',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'منع الوصول المباشر إلى عنوان الخادم خلف الـ CDN. إعداد استضافة، وأعددنا له في قراءة الطلب الموكَّل كآمن.',
        ],
        'INF-03' => [
            'title' => 'تقسيم الشبكة داخليًا',
            'owner' => 'DevOps + Security',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'في مقترح الاستضافة.',
        ],
        'INF-04' => [
            'title' => 'تقوية الأنظمة والخدمات',
            'owner' => 'DevOps',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'في مقترح الاستضافة. والصورة المُعدّة للتشغيل تحمل إعداداتها كلها ولا تبوت بلا مفتاح تطبيق.',
        ],
        'INF-05' => [
            'title' => 'ملكية السحابة والنطاق والبريد',
            'owner' => 'Owner + DevOps',
            'priority' => 'P0',
            'scope' => 'client',
            'state' => 'pending',
            'gap' => 'كل حساب يُفتح باسم المؤسسة لا باسمنا. يُنفَّذ يوم اختيار الاستضافة، ونحن نوصي به صراحةً.',
        ],
        'INF-06' => [
            'title' => 'المرونة ونشر دون انقطاع',
            'owner' => 'DevOps',
            'priority' => 'P1',
            'scope' => 'hosting',
            'state' => 'pending',
            'gap' => 'في مقترح الاستضافة. والتطبيق لا يحمل حالة في ذاكرة الخادم، فيقبل التوسع أفقيًا.',
        ],

        /* 08 — سلسلة البناء والنشر */

        'CICD-01' => [
            'title' => 'مراجعة PR وحماية الفروع',
            'owner' => 'Tech Lead',
            'priority' => 'P0',
            'scope' => 'client',
            'state' => 'pending',
            'gap' => 'إعداد في مستودع الكود نفسه، ويُضبط عند تسليم ملكية المستودع للمؤسسة.',
        ],
        'CICD-02' => [
            'title' => 'فحص تلقائي متعدد الطبقات',
            'owner' => 'DevOps + Security',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'pending',
            'gap' => 'خط البناء الحالي موروث عن قالب Laravel ولا يشغّل اختبارات المشروع فعلًا. يلزم خط يشغّل الاختبارات ويفحص التبعيات والأسرار على كل دفعة.',
        ],
        'CICD-03' => [
            'title' => 'Secrets وإلغاء المفاتيح',
            'owner' => 'DevOps',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'لا سر واحد في الكود، والصورة لا تترك للمشغّل إلا الأسرار، ولا تبوت بلا مفتاح. وما قام: خدمة أسرار مُدارة وتدوير المفاتيح، وتتوقف على الاستضافة.',
        ],
        'CICD-04' => [
            'title' => 'جرد مكونات البرمجيات SBOM',
            'owner' => 'Tech Lead',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'pending',
            'gap' => 'التبعيات مثبّتة بقفل إصدارات، لكن لا جرد SBOM يُصدَر مع كل إصدار.',
        ],
        'CICD-05' => [
            'title' => 'فصل البيئات والنشر',
            'owner' => 'DevOps',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'partial',
            'gap' => 'الإعداد كله يأتي من البيئة، فالصورة واحدة لكل بيئة ولا تحمل بيانات بيئة بعينها. وما قام: البيئات نفسها، وتُنشأ عند الاستضافة.',
        ],

        /* 09 — التشغيل والمراقبة */

        'OPS-01' => [
            'title' => 'Audit Trail للأحداث الحساسة',
            'owner' => 'Backend + Security',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'OPS-02' => [
            'title' => 'سجلات مقاومة للتعديل',
            'owner' => 'DevOps + Security',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'done',
        ],
        'OPS-03' => [
            'title' => 'قياسات أمنية وتنبيهات',
            'owner' => 'DevOps + Security',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'pending',
            'gap' => 'الأحداث كلها مسجّلة، لكن لا تنبيه يخرج منها إلى أحد. يلزم تحديد من يستقبل التنبيه وعلى أي حدث، وهو سؤال معلّق على المؤسسة.',
        ],
        'OPS-04' => [
            'title' => 'خطة حادثة وتسرب بيانات',
            'owner' => 'Security + Owner',
            'priority' => 'P0',
            'scope' => 'client',
            'state' => 'pending',
            'gap' => 'الخطة تلزمها جهة إبلاغ مسمّاة ومدة إبلاغ نظامية، وهما موقوفان على المرجع القانوني.',
        ],
        'OPS-05' => [
            'title' => 'RTO / RPO ونسخة قابلة للاستعادة',
            'owner' => 'Owner + DevOps',
            'priority' => 'P0',
            'scope' => 'hosting',
            'state' => 'partial',
            'gap' => 'النسخ تشمل الوسائط مع قاعدة البيانات، لا قاعدة البيانات وحدها. وما قام: زمن التعافي المعتمد واختبار استعادة فعلي، وكلاهما بعد الاستضافة.',
        ],
        'OPS-06' => [
            'title' => 'فحص اختراق مستقل وإعادة اختبار',
            'owner' => 'Security + Independent Tester',
            'priority' => 'P0',
            'scope' => 'client',
            'state' => 'pending',
            'gap' => 'جهة فحص مستقلة وميزانية لها، ثم إعادة اختبار بعد المعالجة. قرار المؤسسة، ونوصي به قبل التشغيل.',
        ],
        'OPS-07' => [
            'title' => 'الصيانة والتمارين الدورية',
            'owner' => 'Owner + Security + DevOps',
            'priority' => 'RUN',
            'scope' => 'client',
            'state' => 'pending',
            'gap' => 'بند تشغيل مستمر بعد التسليم.',
        ],

        /* 10 — الأحمال */

        'PERF-01' => [
            'title' => 'قياس الأحمال على 3 مسارات',
            'owner' => 'QA + DevOps',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'pending',
            'gap' => 'يلزمه بيئة تشبه الإنتاج ليكون الرقم ذا معنى، فموعده بعد الاستضافة. وخطة القياس والمسارات الثلاثة تُسلَّم مع مقترح الاستضافة.',
        ],
        'PERF-02' => [
            'title' => 'التوسع والتدهور المنضبط',
            'owner' => 'DevOps + Backend',
            'priority' => 'P1',
            'scope' => 'hosting',
            'state' => 'partial',
            'gap' => 'الجلسة والذاكرة المؤقتة والطوابير خارج ذاكرة الخادم، فتعدد الخوادم لا يُسقط جلسة أحد. وما قام: التوسع التلقائي وحدود التدهور، وهما إعداد استضافة.',
        ],

        /* 11 — الالتزام والحكامة */

        'GOV-01' => [
            'title' => 'تقييم الأنظمة المنطبقة',
            'owner' => 'Owner + Compliance',
            'priority' => 'P0',
            'scope' => 'client',
            'state' => 'pending',
            'gap' => 'المرجع سوري مع معايير سعودية وعالمية، وهذا مُعتمد منهم. ومدة الاحتفاظ صارت خمس سنوات بقرارهم. وما بقي: المادة القانونية السورية التي تُقاس عليها المدة، وجهة الإبلاغ المسمَّاة. وأفادوا أنه لا آلية محددة في سوريا بعد وأنهم يسعون لمستشار قانوني.',
        ],
        'GOV-02' => [
            'title' => 'تدفق البيانات ودورة حياتها',
            'owner' => 'Owner + Architect',
            'priority' => 'P0',
            'scope' => 'app',
            'state' => 'pending',
            'gap' => 'نموذج البيانات موصوف في docs/، لكن لا خريطة تدفق تبيّن من يرى أي حقل وإلى أين يخرج. تنتظر إذنًا بالكتابة في docs/.',
        ],
        'GOV-03' => [
            'title' => 'تقييم الموردين والعقود',
            'owner' => 'Owner + Security',
            'priority' => 'P0',
            'scope' => 'client',
            'state' => 'pending',
            'gap' => 'مقترح الاستضافة يحمل الشروط التي تُقاس عليها كل جهة، والتعاقد قرار المؤسسة.',
        ],
        'GOV-04' => [
            'title' => 'ملكية الحسابات والتسليم',
            'owner' => 'Owner + Tech Lead',
            'priority' => 'P0',
            'scope' => 'client',
            'state' => 'pending',
            'gap' => 'يُنفَّذ في التسليم: كل حساب ومفتاح ونطاق باسم المؤسسة.',
        ],
    ],

    /*
     | The fifteen acceptance tests the file lists in its own table. Each one
     | names the item it exercises, so the report can answer the association's
     | question — «ما الدليل؟» — in their own numbering.
     */
    'acceptance' => [
        'AT-01' => ['covers' => ['AUTH-02'], 'title' => 'تبديل معرّف ملف مستخدم آخر في الطلب'],
        'AT-02' => ['covers' => ['AUTH-03'], 'title' => 'إرسال حقول لم تُصرَّح بها، مثل الاعتماد والنشر'],
        'AT-03' => ['covers' => ['AUTH-04'], 'title' => 'محاولة استخراج كل السجلات بالبحث أو التصدير'],
        'AT-04' => ['covers' => ['IAM-01'], 'title' => 'دخول حساب إدارة دون تحقق بخطوتين'],
        'AT-05' => ['covers' => ['APP-07'], 'title' => 'وصول رد مستخدم إلى جلسة مستخدم آخر عبر الـ cache'],
        'AT-06' => ['covers' => ['FILE-01', 'FILE-02'], 'title' => 'رفع ملف خبيث أو نوع مخالف'],
        'AT-07' => ['covers' => ['FILE-03'], 'title' => 'فتح رابط تنزيل منتهٍ أو ملف أسرة أخرى'],
        'AT-08' => ['covers' => ['DATA-01', 'DATA-02'], 'title' => 'استخدام بيانات خدمة عامة للوصول إلى قاعدة البيانات'],
        'AT-09' => ['covers' => ['PAY-02', 'PAY-03'], 'title' => 'حدث دفع مزوَّر أو مكرر'],
        'AT-10' => ['covers' => ['PAY-04', 'PAY-05'], 'title' => 'صرف دون اعتماد ثانٍ، أو تعديل بيانات بنكية'],
        'AT-11' => ['covers' => ['INF-02'], 'title' => 'طلب مباشر إلى عنوان الخادم خلف الـ CDN'],
        'AT-12' => ['covers' => ['OPS-02'], 'title' => 'استخدام هوية التطبيق لحذف السجلات'],
        'AT-13' => ['covers' => ['DATA-05'], 'title' => 'حذف النسخ من حساب الإنتاج'],
        'AT-14' => ['covers' => ['OPS-03', 'OPS-04'], 'title' => 'محاكاة تسرب أو تغيير صلاحية حرجة'],
        'AT-15' => ['covers' => ['PERF-01'], 'title' => 'حمل مستقل بخمس مئة ألف زيارة في نافذة محددة'],
    ],
];
