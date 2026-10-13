{{--
 | T-92 — «صلاحيات حسابك»: one role's own page of the matrix.
 |
 | Built from the rows the policies read, never written by hand, so a guide
 | cannot promise a power the system withholds or hide one it grants. Expects
 | $sheet (PermissionService::roleSheet) and $roleName.
--}}
<section class="sheet">
    <h2>{{ __('training.permissions.title') }}</h2>

    <p class="lede">{{ __('training.permissions.intro', ['role' => $roleName]) }}</p>

    @if ($sheet['read_only'])
        <p class="callout">{{ __('training.permissions.read_only') }}</p>
    @endif

    @foreach (['write' => __('training.permissions.may_write'), 'read' => __('training.permissions.may_read')] as $group => $heading)
        <h3>{{ $heading }}</h3>

        @if ($sheet['held'][$group] === [])
            <p class="lede">{{ __('training.permissions.none_held') }}</p>
        @else
            <table class="grid">
                <thead>
                    <tr>
                        <th>{{ __('sanabel.matrix.permission') }}</th>
                        <th style="width: 110px;">{{ __('training.permissions.scope') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sheet['held'][$group] as $held)
                        <tr>
                            <td>{{ $held['name_ar'] }}</td>
                            <td class="scope">{{ __('sanabel.matrix.scopes.'.$held['scope']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <h3>{{ __('training.permissions.refused') }}</h3>

    <p class="lede">{{ __('training.permissions.refused_intro') }}</p>

    <ul class="chips">
        @foreach (array_merge($sheet['refused']['write'], $sheet['refused']['read']) as $name)
            <li>{{ $name }}</li>
        @endforeach
    </ul>
</section>
