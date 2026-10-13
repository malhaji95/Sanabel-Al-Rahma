{{--
 | T-92 — the whole matrix as a printed sheet: every role beside every
 | permission, the same table the oversight screen shows.
 |
 | It goes into the guides of the people who answer for the system as a whole —
 | the executive director, the oversight council, the system administrator —
 | and into the overview document. Expects $matrix as the command builds it:
 | plain arrays, since this is printed and not browsed.
--}}
@php
    /*
     | A mark, not a word: a scope spelled out wraps three times in a column
     | this narrow, and the legend below says what each mark means once.
     */
    $marks = ['all' => '●', 'area' => '◑', 'own' => '○'];
@endphp
<section class="sheet wide">
    <h2>{{ __('sanabel.matrix.title') }}</h2>

    <p class="lede">{{ __('sanabel.matrix.intro') }}</p>

    <p class="legend">
        @foreach ($marks as $scope => $mark)
            <b>{{ $mark }}</b> {{ __('sanabel.matrix.scopes.'.$scope) }}@if (! $loop->last) &nbsp;·&nbsp; @endif
        @endforeach
        &nbsp;·&nbsp; <b>—</b> {{ __('training.permissions.not_held') }}
    </p>

    @foreach (['write' => __('sanabel.matrix.write'), 'read' => __('sanabel.matrix.read')] as $group => $heading)
        <h3>{{ $heading }}</h3>

        <table class="grid matrix">
            <thead>
                <tr>
                    <th>{{ __('sanabel.matrix.permission') }}</th>
                    @foreach ($matrix['roles'] as $role)
                        <th>
                            {{ $role['name_ar'] }}
                            @if ($role['read_only'])
                                <span class="readonly">{{ __('sanabel.matrix.read_only') }}</span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($matrix['groups'][$group] as $row)
                    <tr>
                        <th scope="row">{{ $row['name_ar'] }}</th>
                        @foreach ($row['cells'] as $scope)
                            <td class="{{ $scope ? 'on' : 'off' }}">{{ $scope ? ($marks[$scope] ?? '●') : '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
</section>
