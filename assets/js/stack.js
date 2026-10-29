/**
 * Filename: stack.js
 * Purpose: Coordinate Alpine search and theme state with HTMX record fragments.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Add progressive shared components without replacing module workflows.
 */

document.addEventListener('alpine:init', () =>
{
    Alpine.data('WorkspaceSearch', () =>
    (
    {
        query: '',
        Search()
        {
            AT.searchModules(this.query);
        },
        Keydown(event)
        {
            AT.moduleSearchKeydown(event);
        }
    }));
    Alpine.data('ThemePicker', () =>
    (
    {
        preference: document.documentElement.dataset.themeMode || 'system',
        Choose(preference)
        {
            this.preference = preference;
            AT.setTheme(preference);
        }
    }));
});

document.addEventListener('htmx:configRequest', event =>
{
    const TOKEN = document.querySelector('meta[name="csrf-token"]')?.content;
    if (TOKEN)
    {
        event.detail.headers['X-CSRF-Token'] = TOKEN;
    }
});
