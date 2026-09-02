<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ config('brand.colors.primary') }}">
    <link rel="manifest" href="{{ route('field.manifest') }}">
    <link rel="icon" type="image/png" href="{{ config('brand.icons.favicon') }}">
    <link rel="apple-touch-icon" href="{{ config('brand.icons.apple_touch') }}">
    <title>{{ __('sanabel.field.title') }} — {{ __('sanabel.app_name') }}</title>

    {{-- The identity typeface, self-hosted: the field app has to work with no network. --}}
    @vite(['resources/css/app.css', 'resources/js/field.js'])
</head>
<body class="min-h-screen">
<div class="mx-auto max-w-2xl px-4 py-6" x-data="fieldApp()" x-init="boot()">
    <header class="mb-6 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            {{-- The symbol only: this header has no room for the full lockup. --}}
            <x-brand.logo variant="symbol" :height="36" />
            <h1 class="text-lg font-bold">{{ __('sanabel.field.title') }}</h1>
        </div>

        {{-- The delegate always knows whether the device is online. --}}
        <span
            class="badge shrink-0"
            :class="online
                ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/50 dark:text-brand-100'
                : 'bg-gold-100 text-gold-800 dark:bg-gold-900/50 dark:text-gold-100'"
        >
            <span class="inline-block h-2 w-2 rounded-full" :class="online ? 'bg-brand-600' : 'bg-gold-500'"></span>
            <span x-text="online ? '{{ __('sanabel.field.online') }}' : '{{ __('sanabel.field.offline') }}'"></span>
        </span>
    </header>

    <div class="alert-warning mb-6 flex-col !items-stretch" x-show="pending > 0" x-cloak role="status">
        <p class="font-medium">
            <span class="tabular" x-text="pending"></span> {{ __('sanabel.field.queued') }}
        </p>
        <p class="text-sm opacity-90">{{ __('sanabel.field.queue_help') }}</p>
        <div>
            <button type="button" class="btn-primary mt-1" :disabled="!online || syncing" @click="syncNow()">
                <span x-text="syncing ? '{{ __('sanabel.field.syncing') }}' : '{{ __('sanabel.field.sync_now') }}'"></span>
            </button>
        </div>
    </div>

    <form class="card space-y-5" @submit.prevent="save()">
        <label class="block">
            <span class="field-label">{{ __('sanabel.field.case') }}</span>
            <select x-model="form.beneficiary_id" class="field" required>
                <option value="">{{ __('sanabel.field.choose_case') }}</option>
                @foreach ($cases as $case)
                    <option
                        value="{{ $case->id }}"
                        data-updated="{{ $case->updated_at?->toIso8601String() }}"
                    >{{ $case->file_number }} — {{ $case->family_name }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="field-label">{{ __('sanabel.field.visited_at') }}</span>
            <input type="datetime-local" x-model="form.visited_at" class="field" required>
        </label>

        <label class="block">
            <span class="field-label">{{ __('sanabel.field.note') }}</span>
            <textarea x-model="form.note_ar" rows="4" class="field"></textarea>
        </label>

        <label class="block">
            <span class="field-label">{{ __('sanabel.field.recommendation') }}</span>
            <select x-model="form.recommendation" class="field">
                <option value="approve">{{ __('sanabel.field.recommend_approve') }}</option>
                <option value="reject">{{ __('sanabel.field.recommend_reject') }}</option>
                <option value="revisit">{{ __('sanabel.field.recommend_revisit') }}</option>
            </select>
        </label>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" x-model="form.is_reassessment" class="rounded" style="accent-color: var(--accent);">
            {{ __('sanabel.field.is_reassessment') }}
        </label>

        <button type="submit" class="btn-primary w-full">{{ __('sanabel.field.save') }}</button>

        <p class="text-xs" style="color: var(--text-muted);">{{ __('sanabel.field.save_help') }}</p>
    </form>

    <p class="mt-4 text-sm font-medium" style="color: var(--accent);" x-text="message" x-show="message" x-cloak></p>
</div>

<script>
    function fieldApp() {
        return {
            online: navigator.onLine,
            pending: 0,
            syncing: false,
            message: '',
            form: {
                beneficiary_id: '',
                visited_at: new Date().toISOString().slice(0, 16),
                note_ar: '',
                recommendation: 'approve',
                is_reassessment: false,
            },

            async boot() {
                window.SanabelField.watchConnection((online) => { this.online = online })
                window.addEventListener('sanabel:queue-changed', () => this.refreshPending())
                await this.refreshPending()
            },

            async refreshPending() {
                this.pending = (await window.SanabelField.pendingVisits()).length
            },

            async save() {
                const option = document.querySelector(`option[value="${this.form.beneficiary_id}"]`)

                await window.SanabelField.queueVisit({
                    ...this.form,
                    beneficiary_id: Number(this.form.beneficiary_id),
                    visited_at: new Date(this.form.visited_at).toISOString(),
                    // What this device last saw. The server flags a conflict if the
                    // case moved since, and stores the visit instead of overwriting.
                    base_version_at: option?.dataset.updated ?? null,
                })

                this.form.note_ar = ''
                this.message = @js(__('sanabel.field.saved_offline'))
                await this.refreshPending()

                if (this.online) {
                    await this.syncNow()
                }
            },

            async syncNow() {
                this.syncing = true

                try {
                    const result = await window.SanabelField.sync()
                    this.message = result.conflicts > 0
                        ? @js(__('sanabel.field.synced_with_conflicts'))
                        : @js(__('sanabel.field.synced'))
                } catch (e) {
                    this.message = @js(__('sanabel.field.sync_failed'))
                } finally {
                    this.syncing = false
                    await this.refreshPending()
                }
            },
        }
    }
</script>
</body>
</html>
