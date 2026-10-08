<x-filament-panels::page>
    @php($matrix = $this->getMatrix())

    <p class="text-sm" style="color: var(--gray-500);">{{ __('sanabel.matrix.intro') }}</p>

    @foreach (['write' => __('sanabel.matrix.write'), 'read' => __('sanabel.matrix.read')] as $group => $heading)
        <x-filament::section :heading="$heading">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="sticky start-0 bg-white py-2 pe-3 text-start font-medium dark:bg-gray-900">
                                {{ __('sanabel.matrix.permission') }}
                            </th>
                            @foreach ($matrix['roles'] as $role)
                                <th class="px-2 py-2 text-center text-xs font-medium whitespace-nowrap">
                                    {{ $role->name_ar }}
                                    @if ($role->is_read_only)
                                        <span class="block text-[10px] font-normal" style="color: var(--gray-500);">
                                            {{ __('sanabel.matrix.read_only') }}
                                        </span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matrix['groups'][$group] as $permission)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="sticky start-0 bg-white py-2 pe-3 font-medium dark:bg-gray-900">
                                    {{ $permission->name_ar }}
                                </td>
                                @foreach ($matrix['roles'] as $role)
                                    @php($scope = $this->grant($role, $permission))
                                    <td class="px-2 py-2 text-center">
                                        @if ($scope)
                                            <span class="text-xs font-medium" style="color: var(--primary-600);">
                                                {{ __('sanabel.matrix.scopes.' . $scope) }}
                                            </span>
                                        @else
                                            <span style="color: var(--gray-300);">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
