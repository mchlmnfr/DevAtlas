/**
 * Filename: wiki.js
 * Purpose: Coordinate wiki interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

(() => 
{
    const INPUT = document.getElementById('wikiSearch');
    const GUIDES = [...document.querySelectorAll('[data-wiki-guide]')];
    const LINKS = [...document.querySelectorAll('[data-wiki-link]')];
    const Normalize = text => text.normalize('NFKD').toLowerCase().replace(/[\u0300-\u036f]/g, '');
    const ENTRIES = GUIDES.map(element => (
    {
        element,
        key: element.dataset.wikiGuide,
        text: Normalize(element.textContent)
    }));
    function Filter() 
    {
        const TERMS = Normalize(INPUT.value.trim()).split(/\s+/).filter(Boolean);
        const VISIBLE = new Set();
        ENTRIES.forEach((
        {
            element,
            key,
            text
        }) => 
        {
            element.hidden = !TERMS.every(term => text.includes(term));
            if (!element.hidden) 
            {
                VISIBLE.add(key);
            }
        });
        LINKS.forEach(link => link.hidden = !VISIBLE.has(link.dataset.wikiLink));
        document.querySelectorAll('.wiki-nav-group').forEach(group => group.hidden = ![...group.querySelectorAll('a')].some(link => !link.hidden));
        document.getElementById('wikiCount').textContent = VISIBLE.size + ' of ' + GUIDES.length + ' module guides';
        document.getElementById('wikiEmpty').hidden = VISIBLE.size > 0;
    }
    function RevealHash() 
    {
        const KEY = location.hash.slice(1);
        const TARGET = GUIDES.find(guide => guide.id === KEY);
        if (TARGET?.hidden) 
        {
            INPUT.value = '';
            Filter();
            TARGET.scrollIntoView();
        }
    }
    document.getElementById('wikiApp').addEventListener('click', event => 
    {
        const LINK = event.target.closest('a[href^="#module-"]');
        if (!LINK) 
        {
            return;
        }
        const TARGET = GUIDES.find(guide => '#' + guide.id === LINK.getAttribute('href'));
        if (TARGET?.hidden) 
        {
            INPUT.value = '';
            Filter();
        }
    });
    INPUT.addEventListener('input', Filter);
    window.addEventListener('hashchange', RevealHash);
    Filter();
    RevealHash();
})();