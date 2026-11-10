{{--
    T-292 — ما ينتظر قرارك, وما ينتظر اطّلاعك, ومحاولات الوصول المرفوضة.

    Colours and borders are written out rather than taken from classes: this
    renders inside Filament's own stylesheet, and a utility its build never
    saw draws nothing at all.
--}}
<x-filament-panels::page>
    <div dir="rtl" style="font-size: 0.9rem; line-height: 1.7;">

        {{-- ١ — ما ينتظر قرارك --}}
        <div style="font-weight: 700; margin-bottom: 0.5rem;">
            {{ __('sanabel.governance.awaiting_your_decision') }}
        </div>

        @php($approvals = $this->awaitingApproval())

        @if ($approvals->isEmpty())
            <div style="opacity: 0.7; margin-bottom: 1.5rem;">
                {{ __('sanabel.governance.nothing_awaits_you') }}
            </div>
        @else
            <table style="width: 100%; border-collapse: collapse; text-align: right; margin-bottom: 1.5rem;">
                <thead>
                    <tr style="border-bottom: 1px solid rgba(128,128,128,0.35);">
                        <th style="padding: 0.4rem 0.25rem; font-weight: 600;">{{ __('sanabel.governance.document') }}</th>
                        <th style="padding: 0.4rem 0.25rem; font-weight: 600;">{{ __('sanabel.need_breakdown.version') }}</th>
                        <th style="padding: 0.4rem 0.25rem; font-weight: 600;">{{ __('sanabel.governance.submitted_by') }}</th>
                        <th style="padding: 0.4rem 0.25rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($approvals as $version)
                        <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                            <td style="padding: 0.5rem 0.25rem;">
                                <div style="font-weight: 600;">{{ $version->document->title_ar }}</div>
                                <div style="opacity: 0.75;">
                                    {{ __('sanabel.governance.kinds.'.$version->document->kind) }}
                                    · {{ __('sanabel.governance.classifications.'.$version->document->classification) }}
                                </div>
                            </td>
                            <td style="padding: 0.5rem 0.25rem; white-space: nowrap;">{{ $version->version_no }}</td>
                            <td style="padding: 0.5rem 0.25rem;">
                                {{ $version->submitter?->name ?? '—' }}
                                <div style="opacity: 0.75;" dir="ltr" class="tabular">
                                    {{ $version->submitted_at?->format('Y-m-d H:i') }}
                                </div>
                            </td>
                            <td style="padding: 0.5rem 0.25rem; text-align: left;">
                                <x-filament::button size="sm" wire:click="approve({{ $version->id }})">
                                    {{ __('sanabel.actions.approve') }}
                                </x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- ٢ — ما ينتظر اطّلاعك --}}
        <div style="font-weight: 700; margin-bottom: 0.5rem;">
            {{ __('sanabel.governance.awaiting_you') }}
        </div>

        @php($unread = $this->awaitingAcknowledgement())

        @if ($unread->isEmpty())
            <div style="opacity: 0.7; margin-bottom: 1.5rem;">
                {{ __('sanabel.governance.nothing_to_read') }}
            </div>
        @else
            <table style="width: 100%; border-collapse: collapse; text-align: right; margin-bottom: 1.5rem;">
                <tbody>
                    @foreach ($unread as $version)
                        <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                            <td style="padding: 0.5rem 0.25rem;">
                                <div style="font-weight: 600;">{{ $version->document->title_ar }}</div>
                                <div style="opacity: 0.75;">
                                    {{ __('sanabel.need_breakdown.version') }} {{ $version->version_no }}
                                    · <span dir="ltr" class="tabular">{{ $version->approved_at?->format('Y-m-d') }}</span>
                                </div>
                            </td>
                            <td style="padding: 0.5rem 0.25rem; text-align: left;">
                                <x-filament::button size="sm" color="gray"
                                    wire:click="acknowledge({{ $version->id }})">
                                    {{ __('sanabel.governance.acknowledge') }}
                                </x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- ٣ — محاولات الوصول المرفوضة, لمن يُراقب --}}
        @if ($this->mayOversee())
            @php($refused = $this->refusedAttempts())

            <div style="font-weight: 700; margin-bottom: 0.5rem;">
                {{ __('sanabel.governance.refused_attempts') }}
            </div>

            @if ($refused->isEmpty())
                <div style="opacity: 0.7;">{{ __('sanabel.governance.no_refusals') }}</div>
            @else
                <table style="width: 100%; border-collapse: collapse; text-align: right;">
                    <tbody>
                        @foreach ($refused as $line)
                            <tr style="border-bottom: 1px solid rgba(128,128,128,0.18);">
                                <td style="padding: 0.4rem 0.25rem;">{{ $line->user?->name ?? '—' }}</td>
                                <td style="padding: 0.4rem 0.25rem;">{{ $line->document?->title_ar ?? '—' }}</td>
                                <td style="padding: 0.4rem 0.25rem;">
                                    {{ __('sanabel.governance.abilities.'.$line->ability) }}
                                </td>
                                <td style="padding: 0.4rem 0.25rem; white-space: nowrap;" dir="ltr" class="tabular">
                                    {{ $line->created_at?->format('Y-m-d H:i') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </div>
</x-filament-panels::page>
