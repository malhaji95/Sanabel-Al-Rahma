<?php

use Filament\Facades\Filament;

/*
 | T-200 — «ما صغّرتها, بحيث يكون العرض أصغر من هيك والمعلومات تكون واضحة»,
 | 1 November, over the files list with the sidebar drawn round in red.
 |
 | Filament's own sidebar is 20rem. On the files list that is three hundred
 | and twenty pixels of mostly empty column beside a table of nine, and the
 | table was the thing being squeezed.
 |
 | Sixteen rems — two hundred and fifty-six pixels — is the narrowest that
 | still holds the longest row in the navigation, «طلبات المسار الصحي»,
 | without clipping it. Fifteen was tried first and cut it to «طلبات المسار
 | ال…», which was measured in the browser rather than guessed at. A screen
 | added later with a longer name has to be measured the same way; no number
 | of letters is asserted here, because the answer depends on the typeface
 | and not on the count.
 */

it('gives every panel the same deliberate sidebar width, not Filament’s default', function () {
    foreach (['admin', 'association', 'provider'] as $id) {
        expect(Filament::getPanel($id)->getSidebarWidth())
            ->toBe('16rem', "the {$id} panel's sidebar is not the width the association asked for");
    }
});
