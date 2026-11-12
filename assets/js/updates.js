/**
 * Filename: updates.js
 * Purpose: Coordinate updates interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

(() => 
{
    const DIALOG = document.getElementById('updatesDialog');
    if (!DIALOG) 
    {
        return;
    }
    const HISTORY = document.getElementById('updatesHistory');
    const ERROR = document.getElementById('updatesError');
    const BUTTONS = [document.getElementById('updatesClose'), document.getElementById('updatesDone')];
    let latest = null;
    let loading = null;
    let isClosing = false;
    const Api = (action, body) => AT.api(DIALOG.dataset.api + '?action=' + action, body ? 
    {
        method: 'POST',
        body
    }
    : 
    {

    });
    function GetElement(tag, text, className) 
    {
        const NODE = document.createElement(tag);
        if (text) 
        {
            NODE.textContent = text;
        }
        if (className) 
        {
            NODE.className = className;
        }
        return NODE;
    }
    async function Open() 
    {
        if (!DIALOG.open) 
        {
            DIALOG.showModal();
        }
        if (loading) 
        {
            return loading;
        }
        ERROR.hidden = true;
        HISTORY.setAttribute('aria-busy', 'true');
        loading = (async () => 
        {
            try 
            {
                const DATA = await Api('history');
                latest = DATA.releases[0];
                HISTORY.replaceChildren(...DATA.releases.map((release, index) => 
                {
                    const ARTICLE = GetElement('article', null, 'updates-entry');
                    ARTICLE.dataset.release = release.id;
                    const META = GetElement('div', null, 'updates-meta');
                    const DATE = GetElement('time', new Date(release.date + 'T00:00:00Z').toLocaleDateString('en-US', 
                    {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        timeZone: 'UTC'
                    }));
                    DATE.dateTime = release.date;
                    META.append(DATE, GetElement('span', release.id));
                    if (index === 0) 
                    {
                        META.append(GetElement('span', 'Latest update', 'badge badge-blue'));
                    }
                    const LIST = GetElement('ul');
                    release.changes.forEach(change => LIST.append(GetElement('li', change)));
                    ARTICLE.append(META, GetElement('h3', release.title), LIST);
                    return ARTICLE;
                }));
                DIALOG.querySelector('.updates-body').scrollTop = 0;
            }
            catch (e) 
            {
                ERROR.textContent = e.message;
                ERROR.hidden = false;
            }
            finally 
            {
                HISTORY.removeAttribute('aria-busy');
                loading = null;
            }
        })();
        return loading;
    }
    async function Close() 
    {
        if (isClosing) 
        {
            return;
        }
        isClosing = true;
        BUTTONS.forEach(button => button.disabled = true);
        try 
        {
            if (loading) 
            {
                await loading;
            }
            if (latest) 
            {
                await Api('acknowledge', 
                {
                    release_id: latest.id
                });
            }
            DIALOG.close();
        }
        catch (e) 
        {
            ERROR.textContent = e.message;
            ERROR.hidden = false;
        }
        finally 
        {
            isClosing = false;
            BUTTONS.forEach(button => button.disabled = false);
        }
    }
    document.addEventListener('click', event => 
    {
        const TRIGGER = event.target.closest('[data-updates-open]');
        if (!TRIGGER) 
        {
            return;
        }
        event.preventDefault();
        document.getElementById('helpMenu')?.removeAttribute('open');
        Open();
    });
    BUTTONS.forEach(button => button.addEventListener('click', Close));
    DIALOG.addEventListener('cancel', event => 
    {
        event.preventDefault();
        Close();
    });
    Api('notice').then(data => 
    {
        if (data.pending && !document.querySelector('dialog[open]')) 
        {
            if (window.ExperienceBoot && !ExperienceBoot.ready) document.addEventListener('experience:ready', () => { if (!document.querySelector('dialog[open]')) Open(); }, {once:true});
            else Open();
        }
    }).catch(() => 
    {
        /* The Help menu can retry loading the update history. */
    });
})();