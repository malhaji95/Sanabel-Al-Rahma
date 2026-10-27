<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages\Concerns;

use App\Models\Beneficiary;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * T-177 — the one thing an approver may change on a family file, and the one
 * place they may write.
 *
 * «نحتاجه يطلع كامل ويقدر يكتب ملاحظات ويستطيع التعديل على التصنيف فقط —
 * الزكاة, الفقر, اليتم, مسكين وغيره», 1 November, answering the question this
 * report had left open: the executive director is barred from the edit screen,
 * deliberately, because they decide about families and do not type the
 * families' data in. That stays. What they asked for is narrower than an edit
 * and is what an approver actually needs in front of a file: the channel the
 * money is paid through, the classification it is filed under, and a sentence
 * saying what they made of it.
 *
 * So the file stays read-only and these three fields come out of it into a
 * modal of their own. Nothing else on the screen is writable, which is the
 * separation of duties the association built the permission table around.
 */
trait ClassifiesAndNotes
{
    protected function classifyAction(): Actions\Action
    {
        return Actions\Action::make('classify')
            ->label(__('sanabel.classify.action'))
            ->icon('heroicon-o-tag')
            ->color('warning')
            ->modalDescription(__('sanabel.classify.help'))
            // The same key the zakat channel has always answered to, and the
            // same one the approve button answers to: the account that signs
            // a family file is the account that files it under a category.
            ->visible(fn () => auth()->user()?->can_('approve_case'))
            ->fillForm(fn (): array => [
                'zakat_category' => $this->record->zakat_category,
                'case_classifications' => $this->record->case_classifications ?? [],
                'management_note_ar' => $this->record->management_note_ar,
            ])
            ->form([
                Forms\Components\Select::make('zakat_category')
                    ->label(__('sanabel.zakat.category'))
                    ->helperText(__('sanabel.zakat.category_help'))
                    // The researcher's reading, beside the management's choice
                    // and never instead of it — the pairing of T-73.
                    ->hint(fn (): string => match ($this->record->zakat_recommended) {
                        true => __('sanabel.zakat.recommended_yes'),
                        false => __('sanabel.zakat.recommended_no'),
                        default => __('sanabel.zakat.recommended_none'),
                    })
                    ->options(collect(Beneficiary::ZAKAT_CATEGORIES)
                        ->mapWithKeys(fn (string $k) => [$k => __('sanabel.zakat.categories.'.$k)])
                        ->all()),

                Forms\Components\Select::make('case_classifications')
                    ->label(__('sanabel.beneficiary.case_classification'))
                    ->helperText(__('sanabel.beneficiary.case_classification_help'))
                    ->multiple()
                    ->options(collect(Beneficiary::CASE_CLASSIFICATIONS)
                        ->mapWithKeys(fn (string $k) => [$k => __('sanabel.case_classification.'.$k)])
                        ->all()),

                Forms\Components\Textarea::make('management_note_ar')
                    ->label(__('sanabel.classify.note'))
                    ->helperText(__('sanabel.classify.note_help'))
                    ->rows(4),
            ])
            ->action(function (array $data) {
                abort_unless(auth()->user()?->can_('approve_case'), 403);

                // Three named columns and no others: `update()` on the whole
                // array would take whatever a crafted request put in it.
                $this->record->update([
                    'zakat_category' => $data['zakat_category'] ?: null,
                    'case_classifications' => $data['case_classifications'] ?: null,
                    'management_note_ar' => $data['management_note_ar'] ?: null,
                ]);

                // The read-only form beside the modal still holds what it was
                // filled with, so it is filled again from the saved record.
                $this->fillForm();

                Notification::make()->title(__('sanabel.classify.saved'))->success()->send();
            });
    }
}
