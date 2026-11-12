/* Account-owned workspace controls. No business record or credential is stored here. */
(() => {
    'use strict';
    if (window.Experience) return;
    const state=window.AT_EXPERIENCE||{actor:0,module:'',documents:{},catalog:[],storage_ready:false};
    const defaultSizes={usermgmt:15,servers:15,proginventory:20,directory:12,datadict:15,audit:50};
    const defaultSize=defaultSizes[state.module]||25,pagedModules=['usermgmt','servers','proginventory','directory','datadict','codereview','dailylogs','teammonitoring','leaves','approvals','reviewlogs','audit','notifications'];
    const root=document.documentElement, moduleKey='module.'+state.module, queues=new Map(), undo=[];
    const clone=value=>JSON.parse(JSON.stringify(value)), object=value=>value&&!Array.isArray(value)?value:{};
    const $=(s,scope=document)=>scope.querySelector(s), $$=(s,scope=document)=>[...scope.querySelectorAll(s)];
    const el=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e;};
    const button=(text,fn,cls='btn btn-sm')=>{const b=el('button',cls,text);b.type='button';if(fn)b.addEventListener('click',event=>{try{Promise.resolve(fn(event)).catch(e=>toast(e.message||'Could not complete this action.','error'));}catch(e){toast(e.message||'Could not complete this action.','error');}});return b;};
    const toast=(message,type='info')=>typeof AT!=='undefined'?AT.toast(message,type):console.info(message);
    const doc=key=>state.documents[key]||{value:{},revision:0};
    const moduleValue=()=>{const v=doc(moduleKey).value;return {...v,filters:{...v.filters},pageSize:{...v.pageSize},tables:{...v.tables},views:v.views||[]};};
    async function request(body,params={}) {
        if(!state.actor||!state.endpoint)throw new Error('Sign in to save workspace settings.');
        const url=new URL(state.endpoint,location.origin);Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,v));
        const r=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',headers:body?{'Content-Type':'application/json','X-CSRF-Token':$('meta[name="csrf-token"]')?.content||''}:{},body:body?JSON.stringify(body):undefined});
        let data;try{data=await r.json();}catch{throw new Error('Your session changed. Sign in and reload this page.');}
        if(!r.ok||data.error){const e=new Error(data.error||'Could not save workspace settings.');e.status=r.status;throw e;}
        return data;
    }
    async function read(key){const r=await request(null,{key});state.documents[key]=r.document;return clone(r.document);}
    async function saveBatch(changes,ui){
        if(!state.storage_ready)throw new Error('Run the workspace update installer to enable saved settings.');
        const payload={changes:changes.map(c=>({key:c.key,revision:c.revision??doc(c.key).revision,value:c.value}))};if(ui)payload.ui=ui;
        const r=await request(payload);Object.assign(state.documents,r.documents);document.dispatchEvent(new CustomEvent('experience:saved',{detail:r}));return r;
    }
    function update(key,mutate){
        const previous=queues.get(key)||Promise.resolve();
        const task=previous.catch(()=>{}).then(async()=>{
            for(let attempt=0;attempt<2;attempt++){
                const value=mutate(clone(doc(key).value));
                try{return await saveBatch([{key,value}]);}catch(e){if(e.status===409&&!attempt){await read(key);continue;}throw e;}
            }
        });queues.set(key,task);task.catch(e=>toast(e.message,'error'));return task;
    }
    const moduleUpdate=fn=>update(moduleKey,v=>fn({...v,filters:{...v.filters},pageSize:{...v.pageSize},tables:{...v.tables},views:v.views||[]}));
    function applyAppearance(value){root.dataset.accent=value.accent||'blue';root.dataset.readingSize=value.textSize||'normal';}
    const storageKey='devatlas:visit:'+state.actor+':'+location.pathname;
    let visit={};try{visit=JSON.parse(sessionStorage.getItem(storageKey)||'{}');}catch{}
    function rememberVisit(){try{sessionStorage.setItem(storageKey,JSON.stringify(visit));}catch{}}
    function snapshot(){undo.push(clone({...moduleValue(),filters:values()}));if(undo.length>20)undo.shift();if(toolbar)$('[data-exp-undo]',toolbar).disabled=false;}
    async function undoView(){if(!undo.length)return;const value=undo.pop();await update(moduleKey,()=>value);applyFilters(value.filters,true);document.dispatchEvent(new CustomEvent('experience:table-layout'));const event=new CustomEvent('experience:page-size',{detail:{handled:false}});document.dispatchEvent(event);const size=$('[data-exp-page-size]');if(size)size.value=String(Experience.pageSize(defaultSize));if(toolbar)$('[data-exp-undo]',toolbar).disabled=!undo.length;}
    let effectiveFilters=null, toolbar=null, chips=null, savedSelect=null, progress=null, progressTimer=null, filters=[], defaults={}, filterTimer=null, restoring=true, touched=false;
    const filterName=e=>e.id||e.name;
    const isFilter=e=>!!e.closest('.main .content')&&e.matches('input:not([type=hidden]):not([type=password]):not([type=file]):not([type=checkbox]):not([type=radio]),select')&&!e.closest('dialog,.exp-toolbar,[data-exp-ui]')&&(e.closest('.wf-filters,.ws-filters,[id$="Filters"],[id$="filters"],.filter-bar,.filters,.filter-row,.ws-toolbar,.audit-filters,.dd-filter-pair,.dd-filter-row,.lg-filters,.bm-filters')||(/(?:search|filter|month)/i.test(e.id)&&!e.closest('.modal,form:not(.wf-filters):not([id$="Filters"])')));
    function findFilters(){filters=$$('input,select').filter(isFilter);filters.forEach(e=>{const key=filterName(e);if(key&&!(key in defaults))defaults[key]=e.value;});return filters;}
    function values(){const v={};filters.forEach(e=>{if(filterName(e))v[filterName(e)]=e.value;});return v;}
    function applyFilters(v,fire=false){
        let first=null;findFilters();for(const e of filters){const key=filterName(e);if(!(key in v))continue;if(e.tagName==='SELECT'&&!Array.from(e.options).some(o=>o.value===String(v[key])))continue;if(e.value===String(v[key]))continue;e.value=v[key];e._flatpickr?.setDate(e.value,false);first??=e;if(fire)e.dispatchEvent(new Event(e.tagName==='SELECT'?'change':'input',{bubbles:true}));}
        if(fire&&first){const f=first.closest('form');if(f)f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}
        paintChips();return !!first;
    }
    function syncQuery(v){const url=new URL(location.href);for(const e of filters){const query=e.name||(/search/i.test(e.id)?'q':'');if(!query)continue;const value=v[filterName(e)]??'';if(!value||value===defaults[filterName(e)])url.searchParams.delete(query);else url.searchParams.set(query,value);}history.replaceState(history.state,'',url);}
    function persistFilters(){if(restoring||!state.storage_ready)return;touched=true;clearTimeout(filterTimer);filterTimer=setTimeout(()=>{const filters=values();syncQuery(filters);moduleUpdate(v=>({...v,filters}));paintChips();},450);}
    function clearFilters(){snapshot();applyFilters(defaults,true);syncQuery(defaults);moduleUpdate(v=>({...v,filters:{...defaults}}));}
    function paintChips(){if(!chips)return;chips.replaceChildren();filters.forEach(e=>{if(!e.value||e.value===defaults[filterName(e)])return;const label=$$('label').find(l=>l.htmlFor===e.id)?.textContent.trim()||e.placeholder||e.id;const value=e.tagName==='SELECT'?e.selectedOptions[0]?.textContent:e.value;const b=button(label+': '+value+' ×',()=>{snapshot();e.value=defaults[filterName(e)]??'';e.dispatchEvent(new Event(e.tagName==='SELECT'?'change':'input',{bubbles:true}));e.closest('form')?.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));persistFilters();},'exp-filter-chip');chips.append(b);});chips.hidden=!chips.children.length;}
    function paintViews(){if(!savedSelect)return;const old=savedSelect.value;savedSelect.replaceChildren(new Option('Saved views',''));for(const v of moduleValue().views)savedSelect.add(new Option(v.name,v.name));savedSelect.value=old;}
    function dialog(title){const d=el('dialog','exp-dialog');d.dataset.expUi='true';const head=el('div','exp-dialog-head');const heading=el('h2','',title);const close=button('Close',()=>d.close());head.append(heading,close);const body=el('div','exp-dialog-body'),foot=el('div','exp-dialog-foot');d.append(head,body,foot);document.body.append(d);d.addEventListener('close',()=>d.remove());d.setAttribute('aria-label',title);return {d,body,foot};}
    async function saveView(){const {d,body,foot}=dialog('Save this filter view');const label=el('label','','View name'),input=el('input');input.maxLength=60;input.required=true;label.append(input);body.append(label);foot.append(button('Save view',async()=>{const name=input.value.trim();if(!name){input.reportValidity();return;}const current=moduleValue();if(current.views.length>=12&&!current.views.some(v=>v.name===name)){toast('Use up to twelve saved views.','warning');return;}await moduleUpdate(v=>({...v,views:[...v.views.filter(x=>x.name!==name),{name,filters:values(),pageSize:{...v.pageSize}}]}));paintViews();d.close();}));d.showModal();input.focus();}
    function refresh(){const b=$$('.main .content button').find(e=>/refresh|reload/i.test(e.id)&&!e.closest('[data-exp-ui],dialog'));if(b){b.click();return;}location.reload();}
    function setPageSize(value){snapshot();moduleUpdate(v=>({...v,pageSize:{...v.pageSize,main:String(value)}})).then(()=>{const event=new CustomEvent('experience:page-size',{detail:{handled:false}});document.dispatchEvent(event);if(!event.detail.handled){const form=filters.find(e=>e.closest('form'))?.closest('form');if(form)form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));else refresh();}document.dispatchEvent(new CustomEvent('experience:table-layout'));}).catch(()=>{});}
    function setupToolbar(){
        const content=$('.main .content');if(!content||!state.actor)return;
        toolbar=el('div','exp-toolbar');toolbar.dataset.expUi='true';toolbar.setAttribute('aria-label','Workspace tools');
        const left=el('div','exp-toolbar-group'),right=el('div','exp-toolbar-group');
        if(filters.length){savedSelect=el('select');savedSelect.setAttribute('aria-label','Saved filter view');savedSelect.addEventListener('change',()=>{const v=moduleValue().views.find(v=>v.name===savedSelect.value);if(!v)return;snapshot();moduleUpdate(current=>({...current,filters:v.filters,pageSize:v.pageSize})).then(()=>{applyFilters(v.filters,true);document.dispatchEvent(new CustomEvent('experience:table-layout'));}).catch(()=>{});});left.append(savedSelect,button('Save view',saveView),button('Delete view',async()=>{const name=savedSelect.value;if(!name)return;await moduleUpdate(v=>({...v,views:v.views.filter(x=>x.name!==name)}));paintViews();}),button('Clear filters',clearFilters));}
        const backUrl=(()=>{try{return sessionStorage.getItem('devatlas:back:'+state.actor+':'+location.pathname);}catch{return null;}})();
        if(backUrl){try{const u=new URL(backUrl,location.origin);if(u.origin===location.origin){const a=el('a','btn btn-sm','Back to filtered list');a.href=u.pathname+u.search;left.prepend(a);}}catch{}}
        const undoButton=button('Undo view',undoView);undoButton.dataset.expUndo='';undoButton.disabled=true;right.append(undoButton,button('Refresh',refresh),button('Appearance',openAppearance),button('Shortcuts',showShortcuts));
        if(pagedModules.includes(state.module)){const label=el('label','','Rows per page '),size=el('select');size.dataset.expPageSize='true';size.setAttribute('aria-label','Rows per page');for(const n of [5,10,12,15,20,25,50,100])size.add(new Option(String(n),String(n)));size.value=String(Experience.pageSize(defaultSize));size.addEventListener('change',()=>setPageSize(Number(size.value)));label.append(size);left.append(label);}
        toolbar.append(left,right);content.prepend(toolbar);chips=el('div','exp-filter-chips');chips.dataset.expUi='true';toolbar.after(chips);paintViews();paintChips();
        progress=el('div','exp-request-status');progress.hidden=true;progress.setAttribute('role','status');progress.dataset.expUi='true';chips.after(progress);
        const freshness=el('div','exp-freshness');freshness.dataset.expUi='true';const text=el('span','','Data loaded just now');const reload=button('Refresh data',refresh);reload.hidden=true;freshness.append(text,reload);progress.after(freshness);
        let last=Date.now();document.addEventListener('experience:request-end',e=>{if(e.detail.ok){last=Date.now();text.textContent='Last refreshed '+new Date(last).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});reload.hidden=true;}else if(e.detail.method==='GET'){text.textContent=e.detail.error||'Data could not be loaded.';reload.hidden=false;reload.textContent='Retry';}});
        const channel=typeof BroadcastChannel==='function'?new BroadcastChannel('devatlas:'+state.actor):null;
        document.addEventListener('experience:mutation',()=>channel?.postMessage({module:state.module}));
        if(channel)channel.onmessage=e=>{if(e.data.module===state.module){text.textContent='New data is available from another tab.';reload.textContent='Refresh data';reload.hidden=false;}};
        window.addEventListener('focus',()=>{if(Date.now()-last>60000){text.textContent='Last refreshed '+new Date(last).toLocaleTimeString()+'. Check for updates.';reload.hidden=false;}});
        const access=window.AT_ACCESS||{}, permissions=access.modules?.[state.module];if(permissions&&permissions.view&&!permissions.create&&!permissions.edit&&!permissions.delete&&!permissions.review){const badge=el('span','exp-readonly','Read-only access');badge.title='Your role can view this module. Contact an administrator to request changes.';left.prepend(badge);}
        if(!state.storage_ready){const notice=el('span','exp-readonly','Workspace saving is not enabled');notice.title='An administrator must run bin/install-workspace-experience.php.';right.append(notice);}
    }
    function activity(e){if(!progress||e.detail.initial)return;const jobs=e.detail.active;$$('.content [data-api]').filter(e=>!e.closest('dialog')).forEach(e=>e.setAttribute('aria-busy',String(!!jobs.length)));clearInterval(progressTimer);progress.hidden=!jobs.length;if(!jobs.length)return;const show=()=>{const elapsed=Math.max(0,Math.floor((performance.now()-Math.min(...jobs.map(j=>j.started)))/1000));progress.textContent=jobs[0].label+(Number.isFinite(jobs[0].fraction)?' · '+Math.round(jobs[0].fraction*100)+'% transferred':'')+(jobs.length>1?' · '+jobs.length+' requests':'')+' · '+elapsed+'s';};show();progressTimer=setInterval(show,1000);}
    let fieldSequence=0;
    function field(label,control){const row=el('div','form-row'),l=el('label','',label);if(!control.id)control.id='experienceOption'+(++fieldSequence);l.htmlFor=control.id;row.append(l,control);return row;}
    function openAppearance(){
        const saved=clone(doc('appearance').value),beforeMotion=root.dataset.motion,beforeDensity=$('#actionDashboard')?.dataset.density;
        const {d,body,foot}=dialog('Appearance and accessibility');let kept=false;
        const accent=el('select'),size=el('select'),motion=el('select'),density=el('select');
        for(const v of ['blue','teal','violet','slate'])accent.add(new Option(v[0].toUpperCase()+v.slice(1),v));for(const v of ['normal','large'])size.add(new Option(v==='large'?'Larger text':'Standard text',v));for(const v of ['standard','subtle','off'])motion.add(new Option(v,v));for(const v of ['comfortable','compact'])density.add(new Option(v,v));
        accent.value=saved.accent||'blue';size.value=saved.textSize||'normal';motion.value=beforeMotion||'standard';density.value=beforeDensity||'comfortable';
        body.append(field('Accent colour',accent),field('Reading size',size),field('Motion',motion),field('Dashboard spacing',density));
        const preview=()=>{applyAppearance({accent:accent.value,textSize:size.value});window.DevAtlasMotion?.set(motion.value);if($('#actionDashboard'))$('#actionDashboard').dataset.density=density.value;};[accent,size,motion,density].forEach(e=>e.addEventListener('change',preview));
        const error=el('p','exp-field-error');error.setAttribute('role','alert');body.append(error);
        foot.append(button('Cancel',()=>d.close()),button('Save appearance',async event=>{event.currentTarget.disabled=true;try{let ui;const legacy=$('#dashboardPreferenceState');if(legacy){const s=JSON.parse(legacy.textContent);ui={...s.preferences,density:density.value,motion:motion.value,revision:s.revision};}else{const u=await fetch(state.endpoint.replace(/experience(?:\.php)?$/,'ui_preferences.php'));const s=await u.json();if(!u.ok)throw new Error(s.error||'Could not load appearance.');ui={...s.preferences,density:density.value,motion:motion.value,revision:s.revision};}const r=await saveBatch([{key:'appearance',value:{accent:accent.value,textSize:size.value}}],ui);if(legacy&&r.ui)legacy.textContent=JSON.stringify(r.ui);kept=true;d.close();toast('Appearance saved.','success');}catch(e){error.textContent=e.message;event.currentTarget.disabled=false;}}));
        d.addEventListener('close',()=>{if(!kept){applyAppearance(saved);window.DevAtlasMotion?.set(beforeMotion);if($('#actionDashboard'))$('#actionDashboard').dataset.density=beforeDensity;}});d.showModal();
    }
    function showShortcuts(){const {d,body,foot}=dialog('Keyboard shortcuts');const list=el('dl','exp-shortcuts');for(const [key,text] of [['Ctrl / ⌘ + K','Search modules and records'],['?','Show this guide'],['Tab / Shift + Tab','Move between controls'],['Enter / Space','Activate a button or expand a date group'],['Escape','Close a menu or dialog'],['Dashboard edit mode','Use move arrows and size controls on each widget']])list.append(el('dt','',key),el('dd','',text));body.append(list);foot.append(button('Close',()=>d.close()));d.showModal();}
    function paintFavorites(){
        const nav=$('#sidebarNav');if(!nav)return;const fav=doc('favorites').value.modules||[];
        $$('.exp-favorite-strip',nav).forEach(e=>e.remove());
        $$('.exp-favorite-pin',nav).forEach(b=>{const active=fav.includes(b.dataset.module);b.textContent=active?'★':'☆';b.setAttribute('aria-pressed',String(active));b.title=(active?'Unpin ':'Pin ')+b.dataset.label;});
        const strip=el('div','exp-favorite-strip');strip.setAttribute('aria-label','Favourite modules');for(const key of fav){const item=state.catalog.find(m=>m.id===key);if(!item)continue;const a=el('a','',item.label);a.href=item.url;a.title=item.label;strip.append(a);}if(strip.children.length)$('.nav-section',nav)?.append(strip);
    }
    function setupFavorites(){const nav=$('#sidebarNav');if(!nav)return;for(const item of state.catalog){const a=$$('a',nav).find(a=>new URL(a.href).pathname.replace(/index\.php$|\/$/g,'')===new URL(item.url,location.origin).pathname.replace(/index\.php$|\/$/g,''));if(!a)continue;a.title=item.label;const row=el('div','exp-nav-row');a.replaceWith(row);row.append(a);const pin=button('☆',()=>update('favorites',v=>{const modules=v.modules||[];if(!modules.includes(item.id)&&modules.length>=12)throw new Error('Pin up to twelve modules.');return {modules:modules.includes(item.id)?modules.filter(k=>k!==item.id):[...modules,item.id]};}).then(paintFavorites),'exp-favorite-pin');pin.dataset.module=item.id;pin.dataset.label=item.label;pin.setAttribute('aria-label','Pin '+item.label);row.append(pin);}paintFavorites();}
    function animateDetails(details){if(details.dataset.expAnimated)return;details.dataset.expAnimated='true';const summary=$('summary',details);if(!summary)return;summary.addEventListener('click',event=>{if(root.dataset.motion==='off'||matchMedia('(prefers-reduced-motion: reduce)').matches||event.target.closest('button,a,input,select'))return;event.preventDefault();const before=details.getBoundingClientRect().height;if(details.open){const after=summary.getBoundingClientRect().height;details.animate([{height:before+'px',overflow:'hidden'},{height:after+'px',overflow:'hidden'}],{duration:root.dataset.motion==='subtle'?80:180,easing:'ease-out'}).finished.then(()=>{details.open=false;}).catch(()=>{});}else{details.open=true;const after=details.getBoundingClientRect().height;details.animate([{height:before+'px',overflow:'hidden'},{height:after+'px',overflow:'hidden'}],{duration:root.dataset.motion==='subtle'?80:180,easing:'ease-out'});} });}
    const statuses={draft:'neutral',submitted:'pending',pending:'pending','pending approval':'pending',reviewed:'success',approved:'success',returned:'danger',rejected:'danger',overdue:'danger',disabled:'neutral',active:'success',enabled:'success'};
    let polishQueued=false;
    function polish(){polishQueued=false;$$('details:not([data-exp-animated])').forEach(animateDetails);$$('.badge,.status-badge,.ws-status,.ws-tag,.status-pill').forEach(e=>{const status=statuses[e.textContent.trim().toLowerCase()];if(status)e.dataset.expStatus=status;});$$('button:disabled,[aria-disabled="true"]').forEach(e=>{if(!e.title)e.title='Unavailable for your role, the current selection, or this record’s status.';});$$('.empty-state,.ws-empty').forEach(e=>{if(e.dataset.expEnhanced||!e.textContent.trim()||e.hidden)return;e.dataset.expEnhanced='true';const hint=el('p','exp-empty-hint',filters.some(f=>f.value&&f.value!==defaults[filterName(f)])?'Try clearing the current filters to see more records.':'New records will appear here when they are available.');e.append(hint);});}
    window.Experience={state,$,$$,el,button,dialog,field,clone,doc,read,request,saveBatch,update,moduleUpdate,moduleValue,snapshot,toast,applyAppearance,openAppearance,refresh,visit,rememberVisit,defaultSize,pagedModules,pageSize:fallback=>{const n=Number(moduleValue().pageSize.main);return [5,10,12,15,20,25,50,100].includes(n)?n:fallback;},setPageSize,copy:async value=>{if(typeof AT!=='undefined')await AT.copyText(value);},filters:()=>filters};
    if(state.documents.appearance)applyAppearance(doc('appearance').value);findFilters();
    // Modules initialise from query strings. Preserve explicit URLs and seed only missing saved fields.
    if(filters.length){const url=new URL(location.href),saved={...moduleValue().filters};let changed=false;for(const e of filters){const key=filterName(e),query=e.name||(/search/i.test(e.id)?'q':'');if(query&&url.searchParams.has(query)){saved[key]=url.searchParams.get(query);continue;}if(key in saved&&query){url.searchParams.set(query,saved[key]);changed=true;}}if(changed)history.replaceState(history.state,'',url);effectiveFilters=saved;applyFilters(saved);}
    setupToolbar();setupFavorites();
    document.addEventListener('input',e=>{if(filters.includes(e.target))persistFilters();});document.addEventListener('change',e=>{if(filters.includes(e.target))persistFilters();});
    document.addEventListener('experience:activity',activity);
    window.ExperienceBoot?.beforeReveal(async()=>{if(!touched){if(applyFilters(effectiveFilters||moduleValue().filters,true))await new Promise(r=>setTimeout(r,380));}restoring=false;polish();});
    document.addEventListener('experience:ready',()=>{if(Number.isFinite(visit.scroll))window.scrollTo({top:visit.scroll,behavior:'instant'});});
    window.addEventListener('scroll',()=>{visit.scroll=scrollY;rememberVisit();},{passive:true});
    document.addEventListener('keydown',e=>{if(e.key==='?'&&!e.ctrlKey&&!e.metaKey&&!e.target.closest('input,textarea,select,[contenteditable=true]')){e.preventDefault();showShortcuts();}});
    document.addEventListener('click',e=>{const a=e.target.closest('a[href]');if(a&&!a.closest('#sidebarNav')&&a.origin===location.origin&&a.pathname!==location.pathname&&!/logout|download|export/.test(a.href)){try{sessionStorage.setItem('devatlas:back:'+state.actor+':'+a.pathname,location.pathname+location.search);}catch{}}const copy=e.target.closest('[data-exp-copy]');if(copy)Experience.copy(copy.dataset.expCopy);});
    document.addEventListener('toggle',e=>{if(e.target.tagName==='DETAILS'){const key=e.target.id||e.target.dataset.expKey;if(key){visit.details={...visit.details,[key]:e.target.open};rememberVisit();}}},true);
    new MutationObserver(()=>{if(!polishQueued){polishQueued=true;requestAnimationFrame(polish);}}).observe(document.body,{childList:true,subtree:true});polish();
})();
