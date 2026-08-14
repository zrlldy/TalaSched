<?php

test('the workspace state component covers the shared non-happy states', function () {
    $component = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/WorkspaceState.vue');

    expect($component)
        ->toContain('empty: {')
        ->toContain('loading: {')
        ->toContain('error: {')
        ->toContain('authorization: {')
        ->toContain('entitlement: {')
        ->toContain('data-slot="workspace-state"')
        ->toContain(':aria-labelledby="headingId"')
        ->toContain(':aria-describedby="descriptionId"')
        ->toContain("variant === 'loading' ? 'true' : undefined")
        ->toContain(':aria-live="')
        ->toContain("'assertive'")
        ->toContain("'polite'")
        ->toContain("'motion-safe:animate-spin': variant === 'loading'")
        ->toContain('bg-schedule')
        ->toContain('bg-conflict')
        ->toContain('bg-warning')
        ->toContain('<slot name="details" />')
        ->toContain('<slot name="action" />');
});
