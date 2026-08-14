<?php

test('the validation summary keeps corrections attached to their fields', function () {
    $types = file_get_contents(dirname(__DIR__, 2).'/resources/js/types/ui.ts');
    $component = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/ValidationSummary.vue');

    expect($types)
        ->toContain('export type ValidationErrors = Record<')
        ->toContain('string | string[] | undefined');

    expect($component)
        ->toContain('data-slot="validation-summary"')
        ->toContain('role="alert"')
        ->toContain('aria-live="assertive"')
        ->toContain('tabindex="-1"')
        ->toContain('Array.from(new Set(messages))')
        ->toContain(".split('.')")
        ->toContain(".replaceAll('_', ' ')")
        ->toContain(".join(' · ')")
        ->toContain('props.fieldLabels?.[field]')
        ->toContain('props.fieldIds?.[field]')
        ->toContain(':href="`#${entry.fieldId}`"')
        ->toContain("entries.value.length === 1 ? 'field needs' : 'fields need'")
        ->toContain("messageCount === 1 ? 'correction' : 'corrections'");
});
