/**
 * Filename: quick-links.js
 * Purpose: Coordinate quick links interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Public link browsing; only the authenticated catalog renders editing controls. */
(() => 
{
    const 
    {
        $: ELEMENT,
        esc: ESC,
        icon: ICON
    }
    = WUI;
    const ROOT = ELEMENT('quickLinksApp');
    const API = ROOT.dataset.api;
    const IS_MANAGE = ROOT.dataset.canManage === '1';
    const Tags = row => (row.tags || '').split(',').map(tag => tag.trim()).filter(Boolean);
    const Valid = value => 
    {
        try 
        {
            const LOCAL_URL = new URL(value);
            return ['http:', 'https:'].includes(LOCAL_URL.protocol) && !LOCAL_URL.username && !LOCAL_URL.password ? LOCAL_URL : null;
        }
        catch 
        {
            return null;
        }
    };
    let rows = [];
    let page = 1;
    let isPinnedOnly = false;
    let editing = null;
    let pins = new Set();
    try 
    {
        const STORED = JSON.parse(localStorage.getItem('at_quick_link_pins') || '[]');
        if (Array.isArray(STORED)) 
        {
            pins = new Set(STORED.filter(id => Number.isInteger(id) && id > 0).slice(0, 5000));
        }
    }
    catch 
    {
        /* An empty device selection is safe. */
    }
    document.querySelectorAll('[data-icon]').forEach(el => el.innerHTML = ICON(el.dataset.icon));
    async function Load() 
    {
        ELEMENT('linksLoadError').hidden = true;
        ELEMENT('bookmarkGrid').setAttribute('aria-busy', 'true');
        try 
        {
            rows = await AT.api(API + '?action=list_bookmarks');
            const TAG = ELEMENT('linksTag').value;
            const ALL_TAGS = new Map();
            for (const ROW of rows) 
            {
                for (const VALUE of Tags(ROW)) 
                {
                    if (!ALL_TAGS.has(VALUE.toLocaleLowerCase())) 
                    {
                        ALL_TAGS.set(VALUE.toLocaleLowerCase(), VALUE);
                    }
                }
            }
            ELEMENT('linksTag').innerHTML = '<option value="">All tags</option>' + [...ALL_TAGS].sort((a, b) => a[1].localeCompare(b[1])).map(([value, label]) => `<option value="${ESC(value)}">${ESC(label)}</option>`).join('');
            if (ALL_TAGS.has(TAG)) 
            {
                ELEMENT('linksTag').value = TAG;
            }
            ELEMENT('linksTotal').textContent = rows.length.toLocaleString();
            ELEMENT('linksTagCount').textContent = ALL_TAGS.size.toLocaleString();
            ELEMENT('linksHostCount').textContent = new Set(rows.map(row => Valid(row.url)?.host).filter(Boolean)).size.toLocaleString();
            Render();
        }
        catch (error) 
        {
            ELEMENT('linksLoadError').hidden = false;
            ELEMENT('bookmarkGrid').innerHTML = '';
            ELEMENT('linksEmpty').hidden = true;
            ELEMENT('linksPageSummary').textContent = 'Links unavailable';
        }
        finally 
        {
            ELEMENT('bookmarkGrid').setAttribute('aria-busy', 'false');
        }
    }
    function Render() 
    {
        const SEARCH = ELEMENT('bmSearch').value.trim().toLocaleLowerCase();
        const TAG = ELEMENT('linksTag').value;
        const FILTERED = rows.filter(row => (!isPinnedOnly || pins.has(row.bookmark_id)) && (!TAG || Tags(row).some(value => value.toLocaleLowerCase() === TAG)) && (!SEARCH || [row.title, row.url, row.description, row.tags].filter(Boolean).join(' ').toLocaleLowerCase().includes(SEARCH)));
        FILTERED.sort(ELEMENT('linksSort').value === 'newest' ? (a, b) => b.bookmark_id - a.bookmark_id : (a, b) => a.title.localeCompare(b.title, undefined, 
        {
            numeric: true
        }) || a.bookmark_id - b.bookmark_id);
        const PAGES = Math.max(1, Math.ceil(FILTERED.length / 12));
        page = Math.max(1, Math.min(page, PAGES));
        const START = (page - 1) * 12;
        ELEMENT('bookmarkGrid').innerHTML = FILTERED.slice(START, START + 12).map(row => 
        {
            const LOCAL_URL = Valid(row.url);
            const ID = Number(row.bookmark_id);
            const IS_PINNED = pins.has(ID);
            return `<article class="link-card"><div class="link-card-header"><span class="link-site-icon" aria-hidden="true">${AT.icon('external-link')}</span><div class="link-card-title"><h3>${LOCAL_URL ? `<a href="${ESC(LOCAL_URL.href)}" target="_blank" rel="noopener noreferrer">${ESC(row.title)}</a>` : ESC(row.title)}</h3><small>${ESC(LOCAL_URL?.host || 'Address needs an update')}</small></div><button class="link-pin" type="button" data-pin="${ID}" aria-pressed="${IS_PINNED}" aria-label="${IS_PINNED ? 'Unpin' : 'Pin'} ${ESC(row.title)}">${AT.icon('star')}</button></div><p class="link-card-description">${ESC(row.description || 'No description added.')}</p><div class="link-tags">${Tags(row).map(value => `<button type="button" class="link-tag" data-tag="${ESC(value.toLocaleLowerCase())}">${ESC(value)}</button>`).join('')}</div><div class="link-card-footer">${LOCAL_URL ? `<a class="btn btn-primary" href="${ESC(LOCAL_URL.href)}" target="_blank" rel="noopener noreferrer">Open link ${AT.icon('arrow-up-right')}</a><button class="ws-link-button" type="button" data-copy="${ID}">Copy address</button>` : '<span class="ws-tag gray">Unavailable</span>'}${IS_MANAGE ? `<span class="link-manage"><button type="button" class="ws-link-button" data-edit="${ID}">Edit</button><button type="button" class="ws-link-button" data-delete="${ID}">Delete</button></span>` : ''}</div></article>`;
        }).join('');
        ELEMENT('linksEmpty').hidden = FILTERED.length > 0;
        ELEMENT('linksEmptyTitle').textContent = !rows.length ? 'No shared links yet.' : isPinnedOnly ? 'No pinned links match.' : 'No matching links.';
        ELEMENT('linksEmptyText').textContent = isPinnedOnly ? 'Use the star on a link to pin it on this device.' : 'Try another search or clear your filters.';
        ELEMENT('linksPageSummary').textContent = FILTERED.length ? `${START + 1}–${Math.min(START + 12, FILTERED.length)} of ${FILTERED.length} links` : '0 links';
        ELEMENT('linksPrevious').disabled = page <= 1;
        ELEMENT('linksNext').disabled = page >= PAGES;
        ELEMENT('linksAll').setAttribute('aria-pressed', String(!isPinnedOnly));
        ELEMENT('linksPinned').setAttribute('aria-pressed', String(isPinnedOnly));
    }
    function Editor(row = null) 
    {
        editing = row?.bookmark_id || null;
        ELEMENT('bookmarkModalTitle').textContent = row ? 'Edit link' : 'New link';
        for (const FIELD of ['title', 'url', 'description', 'tags']) 
        {
            ELEMENT('bm_' + FIELD).value = row?.[FIELD] || '';
        }
        WUI.error('linkFormError', '');
        WUI.open('bookmarkModal');
    }
    ELEMENT('bookmarkGrid').addEventListener('click', async event => 
    {
        const PIN = event.target.closest('[data-pin]');
        const TAG = event.target.closest('[data-tag]');
        const COPY = event.target.closest('[data-copy]');
        const EDIT = event.target.closest('[data-edit]');
        const REMOVE = event.target.closest('[data-delete]');
        if (PIN) 
        {
            const ID = Number(PIN.dataset.pin);
            pins.has(ID) ? pins.delete(ID) : pins.add(ID);
            try 
            {
                localStorage.setItem('at_quick_link_pins', JSON.stringify([...pins]));
            }
            catch 
            {
                AT.toast('Pinned for this visit. Browser storage is unavailable.', 'error');
            }
            Render();
            document.querySelector(`[data-pin="${ID}"]`)?.focus();
        }
        if (TAG) 
        {
            ELEMENT('linksTag').value = TAG.dataset.tag;
            page = 1;
            Render();
        }
        if (COPY) 
        {
            const ROW = rows.find(row => row.bookmark_id === Number(COPY.dataset.copy));
            if (Valid(ROW?.url)) 
            {
                await AT.copyText(ROW.url);
            }
        }
        if (IS_MANAGE && EDIT) 
        {
            Editor(rows.find(row => row.bookmark_id === Number(EDIT.dataset.edit)));
        }
        if (IS_MANAGE && REMOVE) 
        {
            const ROW = rows.find(row => row.bookmark_id === Number(REMOVE.dataset.delete));
            if (!ROW || !(await WUI.confirm('Delete this shared link?', `Remove “${ROW.title}” from the public catalog?`, 'Delete link'))) 
            {
                return;
            }
            try 
            {
                await AT.api(API + '?action=delete_bookmark', 
                {
                    method: 'POST',
                    body: 
                    {
                        bookmark_id: ROW.bookmark_id
                    }
                });
                AT.toast('Link deleted.');
                await Load();
            }
            catch 
            {
                /* API feedback is shown. */
            }
        }
    });
    if (IS_MANAGE) 
    {
        ELEMENT('linkAdd').addEventListener('click', () => Editor());
        ELEMENT('bookmarkForm').addEventListener('submit', event => 
        {
            event.preventDefault();
            WUI.busy(ELEMENT('linkSave'), 'Saving…', async () => 
            {
                WUI.error('linkFormError', '');
                const BODY = 
                {
                    bookmark_id: editing
                };
                for (const FIELD of ['title', 'url', 'description', 'tags']) 
                {
                    BODY[FIELD] = ELEMENT('bm_' + FIELD).value.trim();
                }
                try 
                {
                    await AT.api(API + '?action=save_bookmark', 
                    {
                        method: 'POST',
                        body: BODY
                    });
                    WUI.close('bookmarkModal');
                    AT.toast('Shared link saved.');
                    await Load();
                }
                catch (error) 
                {
                    WUI.error('linkFormError', error.message);
                }
            });
        });
    }
    ['bmSearch', 'linksTag', 'linksSort'].forEach(id => ELEMENT(id).addEventListener(id === 'bmSearch' ? 'input' : 'change', () => 
    {
        page = 1;
        Render();
    }));
    ELEMENT('linksAll').addEventListener('click', () => 
    {
        isPinnedOnly = false;
        page = 1;
        Render();
    });
    ELEMENT('linksPinned').addEventListener('click', () => 
    {
        isPinnedOnly = true;
        page = 1;
        Render();
    });
    ELEMENT('linksReset').addEventListener('click', () => 
    {
        ELEMENT('bmSearch').value = '';
        ELEMENT('linksTag').value = '';
        isPinnedOnly = false;
        page = 1;
        Render();
    });
    ELEMENT('linksPrevious').addEventListener('click', () => 
    {
        page--;
        Render();
    });
    ELEMENT('linksNext').addEventListener('click', () => 
    {
        page++;
        Render();
    });
    ELEMENT('linksRetry').addEventListener('click', Load);
    ELEMENT('bmSearch').value = (new URLSearchParams(location.search).get('q') || '').slice(0, 200);
    Load();
})();