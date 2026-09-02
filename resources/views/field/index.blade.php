<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#059669">
    <link rel="manifest" href="{{ route('field.manifest') }}">
    <title>{{ __('sanabel.field.title') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=tajawal:400,500,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/field.js'])
</head>
<body class="min-h-screen">
<div class="mx-auto max-w-2xl px-4 py-6" x-data="fieldApp()" x-init="boot()">
    <header class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('sanabel.field.title') }}</h1>

        {{-- The delegate always knows whether the device is online. --}}
        <span
            class="badge"
            :class="online ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'"
            x-text="online ? '{{ __('sanabel.field.online') }}' : '{{ __('sanabel.field.offline') }}'"
        ></span>
    </header>

    <div class="card mb-6" x-show="pending > 0" x-cloak>
        <p class="text-sm">
            <span x-text="pending"></span> {{ __('sanabel.field.queued') }}
        </p>
        <button
            type="button"
            class="btn-primary mt-3"
            :disabled="!online || syncing"
            @click="syncNow()"
        >
            <span x-text="syncing ? '{{ __('sanabel.field.syncing') }}' : '{{ __('sanabel.field.sync_now') }}'"></span>
        </button>
        <p class="mt-2 text-xs text-slate-500">{{ __('sanabel.field.queue_help') }}</p>
    </div>

    <form class="card space-y-4" @submit.prevent="save()">
        <label class="block text-sm">
            {{ __('sanabel.field.case') }}
            <select x-model="form.beneficiary_id" class="field mt-1" required>
                <option value="">{{ __('sanabel.field.choose_case') }}</option>
                @foreach ($cases as $case)
                    <option
                        value="{{ $case->id }}"
                        data-updated="{{ $case->updated_at?->toIso8601String() }}"
                    >{{ $case->file_number }} — {{ $case->family_name }}</option>
                @endforeach
            </select>
        </label>

        <label class="block text-sm">
            {{ __('sanabel.field.visited_at') }}
            <input type="datetime-local" x-model="form.visited_at" class="field mt-1" required>
        </label>

        <label class="block text-sm">
            {{ __('sanabel.field.note') }}
            <textarea x-model="form.note_ar" rows="4" class="field mt-1"></textarea>
        </label>

        <label class="block text-sm">
            {{ __('sanabel.field.recommendation') }}
            <select x-model="form.recommendation" class="field mt-1">
                <option value="approve">{{ __('sanabel.field.recommend_approve') }}</option>
                <option value="reject">{{ __('sanabel.field.recommend_reject') }}</option>
                <option value="revisit">{{ __('sanabel.field.recommend_revisit') }}</option>
            </select>
        </label>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" x-model="form.is_reassessment" class="rounded border-slate-300">
            {{ __('sanabel.field.is_reassessment') }}
        </label>

        <button type="submit" class="btn-primary w-full">{{ __('sanabel.field.save') }}</button>

        <p class="text-xs text-slate-500">{{ __('sanabel.field.save_help') }}</p>
    </form>

    <p class="mt-4 text-sm text-sanabel-700" x-text="message" x-show="message" x-cloak></p>
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
