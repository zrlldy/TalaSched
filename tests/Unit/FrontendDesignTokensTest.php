<?php

test('the frontend exposes scheduling semantic design tokens', function () {
    $stylesheet = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    $buttonVariants = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/ui/button/index.ts');
    $badgeVariants = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/ui/badge/index.ts');

    expect($stylesheet)
        ->toContain('--font-schedule:')
        ->toContain('--color-schedule: var(--schedule);')
        ->toContain('--color-warning: var(--warning);')
        ->toContain('--color-conflict: var(--conflict);')
        ->toContain('--color-available: var(--available);')
        ->toContain('--background: #f5f7fb;')
        ->toContain('--foreground: #17243a;')
        ->toContain('--schedule: #315efb;')
        ->toContain('--warning: #e9a72f;')
        ->toContain('--destructive-foreground: #180007;')
        ->toContain('--conflict: #d64a5b;')
        ->toContain('--conflict-foreground: #180007;')
        ->toContain('--available: #23866f;')
        ->toContain('--available-foreground: #000805;')
        ->toContain('.dark {')
        ->toContain('--schedule: #7c9cff;')
        ->toContain('--warning: #ffc65a;')
        ->toContain('--conflict: #ff7a8b;')
        ->toContain('--available: #50c6a3;');

    expect($buttonVariants)
        ->toContain('bg-destructive text-destructive-foreground')
        ->not->toContain('bg-destructive text-white');

    expect($badgeVariants)
        ->toContain('bg-destructive text-destructive-foreground')
        ->not->toContain('bg-destructive text-white');
});
