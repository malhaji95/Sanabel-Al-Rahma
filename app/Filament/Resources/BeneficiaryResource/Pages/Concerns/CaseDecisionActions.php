<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages\Concerns;

use App\Filament\Resources\BeneficiaryResource;
use App\Models\Assessment;
use App\Services\AnonymiseService;
use App\Services\AssessmentService;
use App\Services\CaseService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * T-133 — the decisions a family file carries, on both screens that hold it.
 *
 * Found while answering «في حال تعديل البيانات والضغط على حساب تقييم جديد …
 * هل في اي مشكلة عليها؟», 27 October. Every one of these buttons used to live
 * on the edit screen alone, and the edit screen opens for `edit_draft` —
 * which the executive director and their deputy do not hold, and should not:
 * they decide about families, they do not type the data in.
 *
 * So the account that approves a file could not reach the screen the approve
 * button was on, and in practice only the chairman of the board, who holds
 * every key, could sign one. The buttons now sit on the read-only screen as
 * well, where an approver can reach them, and each one still answers to its
 * own policy exactly as before.
 */
trait CaseDecisionActions
{
    /** @return array<int,Actions\Action> */
    protected function caseDecisionActions(): array
    {
        return [
            /*
             | T-133 — «ضمن الحاجة الشهرية المحتسبة ونسبة التغطية … في حال
             | تعديل البيانات والضغط على حساب تقييم جديد كيف تتم العملية وهل
             | في اي مشكلة عليها؟», 27 October.
             |
             | There was one, and it was here. The computation itself is
             | sound — it reads the file, freezes the reference values it used
             | into the assessment (rule 8) and supersedes the previous one —
             | but the assessment it writes arrives `approved`, and this
             | button asked nobody whether the account pressing it may approve
             | one. AssessmentPolicy has said «approve_case» since it was
             | written; the screen simply never asked it, so any account that
             | could open a draft could sign its own monthly need.
             |
             | Now it asks, and the answer also hides the button rather than
             | letting somebody press it and be refused.
             */
            Actions\Action::make('assess')
                ->label(__('sanabel.actions.recompute_assessment'))
                ->icon('heroicon-o-calculator')
                /*
                 | Asked of the assessment's own policy, and not through
                 | Filament's `authorize()`: a record-aware action prepends
                 | the family to the arguments, so Laravel would resolve
                 | BeneficiaryPolicy and answer a question about creating a
                 | file instead of about approving a need. The delegate who
                 | may open a file passed it; the director who approves one
                 | did not.
                 */
                ->visible(fn () => auth()->user()?->can('create', Assessment::class))
                ->requiresConfirmation()
                ->modalDescription(__('sanabel.actions.recompute_help'))
                ->action(function () {
                    // Hidden is not refused: the button is gone, and pressing
                    // it another way is answered here as well.
                    abort_unless(auth()->user()?->can('create', Assessment::class), 403);

                    $assessment = app(AssessmentService::class)->create($this->record, status: 'approved');

                    Notification::make()
                        ->title(__('sanabel.actions.assessment_done'))
                        ->body(__('sanabel.beneficiary.computed_need').': '.$assessment->monthly_need)
                        ->success()->send();
                }),

            /*
             | T-59 (PAY-05) — where the family's money goes.
             |
             | On a live file the wallet field is read-only, and this is the
             | only way to change it: the request is raised here and somebody
             | other than whoever raised it approves it on the change-request
             | screen. Offered only to the roles that may ask for a change.
             */
            Actions\Action::make('requestPaymentChange')
                ->label(__('sanabel.cases.request_payment_change'))
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->visible(fn () => BeneficiaryResource::paymentDetailsLocked($this->record)
                    && auth()->user()->can_('request_change'))
                ->modalDescription(__('sanabel.cases.request_payment_change_help'))
                ->form([
                    Forms\Components\TextInput::make('wallet_encrypted')
                        ->label(__('sanabel.beneficiary.wallet'))
                        ->required(),
                    Textarea::make('reason_ar')
                        ->label(__('sanabel.actions.reason'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    try {
                        app(CaseService::class)->requestChange(
                            $this->record,
                            auth()->user(),
                            ['wallet_encrypted' => $data['wallet_encrypted']],
                            $data['reason_ar'],
                        );
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('sanabel.cases.payment_change_requested'))->success()->send();
                }),

            // The two field steps sit before the admin's button, in the order
            // they must happen; each disappears once it has been taken.
            Actions\Action::make('verifyInField')
                ->label(__('sanabel.actions.verify_in_field'))
                ->icon('heroicon-o-clipboard-document-check')
                ->color('info')
                ->visible(fn () => auth()->user()->can('verifyInField', $this->record))
                ->requiresConfirmation()
                ->modalDescription(__('sanabel.actions.verify_in_field_help'))
                ->action(function () {
                    app(CaseService::class)->verifyInField($this->record, auth()->user());

                    Notification::make()->title(__('sanabel.actions.verified_in_field'))->success()->send();
                }),

            Actions\Action::make('endorse')
                ->label(__('sanabel.actions.endorse'))
                ->icon('heroicon-o-hand-thumb-up')
                ->color('info')
                ->visible(fn () => auth()->user()->can('endorse', $this->record))
                ->requiresConfirmation()
                ->modalDescription(__('sanabel.actions.endorse_help'))
                ->action(function () {
                    app(CaseService::class)->endorse($this->record, auth()->user());

                    Notification::make()->title(__('sanabel.actions.endorsed'))->success()->send();
                }),

            /*
             | T-125 — «المشرف دخل وعطاه ملاحظات بعد المندوب, كيف بترجع
             | الحالة للمندوب؟», 26 October. This is how: the file goes back
             | to the visit queue carrying the note that sent it, and the
             | account that opened it is told.
             */
            Actions\Action::make('returnToField')
                ->label(__('sanabel.actions.return_to_field'))
                ->icon('heroicon-o-arrow-uturn-right')
                ->color('warning')
                ->visible(fn () => auth()->user()->can('returnToField', $this->record))
                ->modalDescription(__('sanabel.actions.return_to_field_help'))
                ->form([
                    Textarea::make('note')
                        ->label(__('sanabel.actions.return_note'))
                        ->helperText(__('sanabel.actions.return_note_help'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    app(CaseService::class)->returnToField($this->record, auth()->user(), $data['note']);

                    Notification::make()->title(__('sanabel.actions.returned_to_field'))->warning()->send();
                }),

            Actions\Action::make('approve')
                ->label(__('sanabel.actions.approve'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                // Separation of duties — the creator is not offered the button at all.
                ->visible(fn () => $this->record->status === 'pending_approval'
                    && auth()->user()->can('approve', $this->record))
                ->requiresConfirmation()
                ->action(function () {
                    app(CaseService::class)->approve($this->record, auth()->user());

                    Notification::make()->title(__('sanabel.actions.approved'))->success()->send();
                }),

            Actions\Action::make('reject')
                ->label(__('sanabel.actions.reject'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => auth()->user()->can('reject', $this->record))
                ->form([
                    Textarea::make('reason_ar')
                        ->label(__('sanabel.actions.reason'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    app(CaseService::class)->reject($this->record, auth()->user(), $data['reason_ar']);

                    Notification::make()->title(__('sanabel.actions.rejected'))->danger()->send();
                }),

            Actions\Action::make('publish')
                ->label(__('sanabel.actions.publish'))
                ->icon('heroicon-o-globe-alt')
                ->visible(fn () => auth()->user()->can('publish', $this->record))
                ->requiresConfirmation()
                ->action(function () {
                    app(CaseService::class)->publish($this->record);

                    Notification::make()->title(__('sanabel.actions.published'))->success()->send();
                }),

            /*
             | T-65 — the law being carried out, not an edit.
             |
             | Two people, as on the disbursement path: one asks and states the
             | legal basis, another carries it out, and the person who asked is
             | refused. It cannot be undone, so one person acting alone is not
             | enough. Both buttons are admin-only.
             */
            Actions\Action::make('requestAnonymise')
                ->label(__('sanabel.anonymise.request'))
                ->icon('heroicon-o-eye-slash')
                ->color('warning')
                ->visible(fn () => auth()->user()->isAdmin()
                    && ! $this->record->isAnonymised()
                    && ! $this->record->anonymise_requested_by)
                ->modalDescription(__('sanabel.anonymise.request_help'))
                ->form([
                    Textarea::make('reason_ar')
                        ->label(__('sanabel.anonymise.reason'))
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    try {
                        app(AnonymiseService::class)
                            ->request($this->record, auth()->user(), $data['reason_ar']);
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('sanabel.anonymise.requested'))->success()->send();
                }),

            Actions\Action::make('executeAnonymise')
                ->label(__('sanabel.anonymise.execute'))
                ->icon('heroicon-o-eye-slash')
                ->color('danger')
                ->visible(fn () => auth()->user()->isAdmin()
                    && ! $this->record->isAnonymised()
                    && (bool) $this->record->anonymise_requested_by)
                ->requiresConfirmation()
                ->modalDescription(__('sanabel.anonymise.execute_help'))
                ->action(function () {
                    try {
                        app(AnonymiseService::class)->execute($this->record, auth()->user());
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('sanabel.anonymise.done'))->success()->send();
                }),

            // T-105 — closing a file is a decision about a family, and the
            // button asked whether you were the administrator instead of
            // asking the policy, which has always pointed `suspend_graduate`
            // at the executive director.
            /*
             | T-106 — the signature under the family's story. Held to
             | `approve_case` because publishing a paragraph about a
             | household to the open internet is a decision about that
             | household, and the association already named who makes those.
             */
            Actions\Action::make('approveStory')
                ->label(__('sanabel.actions.approve_story'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => filled($this->record->story_ar)
                    && $this->record->story_approved_at === null
                    && auth()->user()->can_('approve_case'))
                ->requiresConfirmation()
                ->modalDescription(__('sanabel.actions.approve_story_help'))
                ->action(function () {
                    $this->record->forceFill([
                        'story_approved_by' => auth()->id(),
                        'story_approved_at' => now(),
                    ])->save();

                    Notification::make()->title(__('sanabel.actions.story_approved'))->success()->send();
                }),

            Actions\Action::make('close')
                ->label(__('sanabel.actions.close_case'))
                ->icon('heroicon-o-lock-closed')
                ->visible(fn () => auth()->user()->can('suspend', $this->record))
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        app(CaseService::class)->close($this->record);
                        Notification::make()->title(__('sanabel.actions.closed'))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
