<?php

namespace App\Filament\Resources\BeneficiaryResource\Pages\Concerns;

use App\Filament\Resources\BeneficiaryResource;
use App\Models\Assessment;
use App\Models\Beneficiary;
use App\Models\User;
use App\Services\AnonymiseService;
use App\Services\AssessmentService;
use App\Services\CaseService;
use App\Services\ConflictOfInterest;
use App\Services\CoverageService;
use App\Services\GraduationService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

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
    /**
     * T-305 — the disclosure, where the person who has to make it is.
     *
     * A bar with no way to answer the question behind it is a dead end, so
     * the question sits beside the decisions it stands in front of, and the
     * button says which of the two states the account is in.
     */
    protected function conflictDisclosureAction(): Actions\Action
    {
        return Actions\Action::make('declare_conflict')
            ->label(__('sanabel.conflict.title'))
            ->icon('heroicon-o-hand-raised')
            ->color(fn (Beneficiary $record) => app(ConflictOfInterest::class)
                ->isBarred($record, auth()->user()) ? 'danger' : 'gray')
            ->modalHeading(__('sanabel.conflict.question'))
            ->modalDescription(__('sanabel.conflict.help'))
            ->form([
                Forms\Components\Radio::make('has_relationship')
                    ->label(__('sanabel.conflict.question'))
                    ->boolean(
                        trueLabel: __('sanabel.beneficiary.answer_yes'),
                        falseLabel: __('sanabel.beneficiary.answer_no'),
                    )
                    ->required()
                    ->validationMessages(['required' => __('sanabel.beneficiary.answer_required')])
                    ->live(),

                Forms\Components\Select::make('relation_type')
                    ->label(__('sanabel.conflict.relation_type'))
                    ->options(__('sanabel.conflict.relations'))
                    ->visible(fn (Forms\Get $get) => $get('has_relationship') === true)
                    ->required(fn (Forms\Get $get) => $get('has_relationship') === true),

                Textarea::make('relation_note_ar')
                    ->label(__('sanabel.conflict.relation_note_ar'))
                    ->rows(2)
                    ->visible(fn (Forms\Get $get) => $get('has_relationship') === true),
            ])
            ->fillForm(function (Beneficiary $record) {
                $said = app(ConflictOfInterest::class)->disclosureFor($record, auth()->user());

                return [
                    'has_relationship' => $said?->has_relationship,
                    'relation_type' => $said?->relation_type,
                    'relation_note_ar' => $said?->relation_note_ar,
                ];
            })
            ->action(function (Beneficiary $record, array $data) {
                $said = app(ConflictOfInterest::class)->declare(
                    $record,
                    auth()->user(),
                    (bool) $data['has_relationship'],
                    $data['relation_type'] ?? null,
                    $data['relation_note_ar'] ?? null,
                );

                Notification::make()
                    ->title(__($said->has_relationship
                        ? 'sanabel.conflict.declared_yes'
                        : 'sanabel.conflict.declared_no'))
                    ->{$said->has_relationship ? 'warning' : 'success'}()
                    ->send();
            });
    }

    /**
     * T-305 — lifting a bar, for the account that holds the key of its own.
     *
     * Shown only where there is a bar to lift, and never to the person it
     * would be lifted for: the service refuses that, and a button that only
     * ever refuses is a button that should not be there.
     */
    protected function conflictWaiverAction(): Actions\Action
    {
        return Actions\Action::make('waive_conflict')
            ->label(__('sanabel.conflict.waiver'))
            ->icon('heroicon-o-key')
            ->color('warning')
            ->visible(fn (Beneficiary $record) => auth()->user()?->can_(ConflictOfInterest::WAIVER_KEY)
                && $record->conflictDisclosures()
                    ->where('has_relationship', true)
                    ->where('user_id', '<>', auth()->id())
                    ->exists())
            ->form([
                Forms\Components\Select::make('user_id')
                    ->label(__('sanabel.conflict.waiver'))
                    ->options(fn (Beneficiary $record) => $record->conflictDisclosures()
                        ->with('user')
                        ->where('has_relationship', true)
                        ->where('user_id', '<>', auth()->id())
                        ->get()
                        ->mapWithKeys(fn ($d) => [
                            $d->user_id => $d->user?->name.' — '
                                .__('sanabel.conflict.relations.'.($d->relation_type ?? 'other')),
                        ]))
                    ->required(),

                Textarea::make('reason_ar')
                    ->label(__('sanabel.conflict.waiver_reason'))
                    ->helperText(__('sanabel.conflict.waiver_reason_help'))
                    ->rows(3)
                    ->required(),
            ])
            ->action(function (Beneficiary $record, array $data) {
                app(ConflictOfInterest::class)->waive(
                    $record,
                    User::findOrFail($data['user_id']),
                    auth()->user(),
                    (string) $data['reason_ar'],
                );

                Notification::make()->title(__('sanabel.conflict.waived'))->success()->send();
            });
    }

    protected function caseDecisionActions(): array
    {
        return [
            // T-305 — before the decisions, because it stands in front of them.
            $this->conflictDisclosureAction(),
            $this->conflictWaiverAction(),
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

            /*
             | T-267 — pausing a file, which nothing in the panel could do.
             |
             | Not a rejection and not a graduation: the family still needs
             | help and the association has stopped it for a reason — a
             | doubt, an unanswered question, a household that travelled.
             | The reason is required, and the job profile goes dark with
             | the file, which is what `suspend()` has always done.
             */
            Actions\Action::make('suspend')
                ->label(__('sanabel.actions.suspend'))
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => in_array($this->record->status, Beneficiary::MAY_RECEIVE_MONEY, true)
                    && auth()->user()->can('suspend', $this->record))
                ->form([
                    Textarea::make('reason_ar')
                        ->label(__('sanabel.actions.reason'))
                        ->helperText(__('sanabel.actions.suspend_help'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    app(CaseService::class)->suspend($this->record, $data['reason_ar']);

                    Notification::make()->title(__('sanabel.actions.suspended'))->warning()->send();
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
                        // T-151 — the field asked for a legal basis and did
                        // not say what one is, so it says so here: what
                        // compels the erasure and where it came from.
                        ->helperText(__('sanabel.anonymise.reason_help'))
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

            /*
             | T-249 — the delegate's graduation study, raised from the
             | family's own screen because that is where the figures are.
             | It decides nothing: the supervisor recommends and the
             | executive director decides, and the service refuses any
             | other order.
             */
            Actions\Action::make('studyForGraduation')
                ->label(__('sanabel.graduation.study'))
                ->icon('heroicon-o-academic-cap')
                ->visible(fn () => in_array($this->record->status, Beneficiary::MAY_RECEIVE_MONEY, true)
                    && auth()->user()->can_('record_visit'))
                ->modalDescription(__('sanabel.graduation.study_help'))
                ->form([
                    Forms\Components\TextInput::make('monthly_income')
                        ->label(__('sanabel.graduation.monthly_income'))
                        ->numeric()->minValue(1)->required()
                        ->helperText(__('sanabel.graduation.need_snapshot').': '
                            .number_format(app(CoverageService::class)->needAmount($this->record))),
                    Forms\Components\DatePicker::make('stable_since')
                        ->label(__('sanabel.graduation.stable_since'))
                        ->maxDate(now())
                        ->required(),
                    Textarea::make('study_note_ar')
                        ->label(__('sanabel.graduation.study_note'))
                        ->rows(4)
                        ->required(),
                ])
                ->action(function (array $data) {
                    try {
                        app(GraduationService::class)->study(
                            $this->record,
                            auth()->user(),
                            (int) ($data['monthly_income'] ?? 0),
                            Carbon::parse($data['stable_since']),
                            $data['study_note_ar'] ?? '',
                        );

                        Notification::make()->title(__('sanabel.graduation.raised'))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),

            /*
             | T-239 — a file that has ended is reopened by a request, never
             | by an edit. The reasons are the association's own list, so the
             | select is empty until they fill it and the action says so
             | rather than offering a free-text box.
             */
            Actions\Action::make('requestReopen')
                ->label(__('sanabel.reopen.request'))
                ->icon('heroicon-o-lock-open')
                ->visible(fn () => in_array($this->record->status, Beneficiary::REOPENABLE_STATUSES, true)
                    && auth()->user()->can_('request_change'))
                ->modalDescription(__('sanabel.reopen.request_help'))
                ->form([
                    Forms\Components\Select::make('reason_ar')
                        ->label(__('sanabel.reopen.reason'))
                        ->options(fn () => collect(CaseService::reopenReasons())
                            ->mapWithKeys(fn ($reason) => [$reason => $reason])
                            ->all())
                        ->required(),
                    Textarea::make('note_ar')
                        ->label(__('sanabel.reopen.note'))
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    try {
                        app(CaseService::class)->requestReopen(
                            $this->record,
                            auth()->user(),
                            $data['reason_ar'] ?? '',
                            $data['note_ar'] ?? null,
                        );

                        Notification::make()->title(__('sanabel.reopen.requested'))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
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
