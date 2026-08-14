<?php

test('the workspace page header gives every domain page a responsive semantic heading', function () {
    $component = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/WorkspacePageHeader.vue');

    expect($component)
        ->toContain('data-slot="workspace-page-header"')
        ->toContain('<h1')
        ->toContain('{{ title }}')
        ->toContain('v-if="section"')
        ->toContain('{{ section }}')
        ->toContain('v-if="description"')
        ->toContain('{{ description }}')
        ->toContain('class="@container')
        ->toContain('@3xl:flex-row')
        ->toContain('text-balance')
        ->toContain('min-w-0');
});

test('the workspace page header exposes focused status metadata and action regions', function () {
    $component = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/WorkspacePageHeader.vue');

    expect($component)
        ->toContain('data-slot="workspace-page-header-status"')
        ->toContain('<slot name="status" />')
        ->toContain('data-slot="workspace-page-header-metadata"')
        ->toContain('<slot name="metadata" />')
        ->toContain('data-slot="workspace-page-header-actions"')
        ->toContain('<slot name="actions" />')
        ->toContain('border-schedule/35')
        ->toContain('bg-schedule')
        ->toContain('aria-hidden="true"');
});
