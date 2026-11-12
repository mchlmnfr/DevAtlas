/* Track foreground data readiness before module scripts run. Requests start immediately. */
(() => {
    'use strict';
    const root=document.documentElement, started=performance.now(), originalFetch=window.fetch.bind(window);
    let loaded=false, pending=0, revealed=false, settling=false, taskDone=false;
    const tasks=[], active=new Map();let sequence=0, overlay=null;
    root.classList.add('exp-loading');
    const classify=(url,method)=>{
        if(url.origin!==location.origin)return false;
        if(/\/audit\/client|\/account\/ui[-_]preferences/.test(url.pathname))return false;
        if(/\/account\/experience/.test(url.pathname)&&!url.searchParams.has('calendar'))return false;
        if(/\/notifications\/api/.test(url.pathname)&&url.searchParams.get('action')==='count')return false;
        if(/\/updates\/api/.test(url.pathname))return false;
        return /\/modules\//.test(url.pathname);
    };
    const label=url=>/export|download/.test(url.pathname+url.search)?'Preparing download':/scan/.test(url.search)?'Scanning source':/import|upload/.test(url.search)?'Processing import':'Loading workspace data';
    function ensure(){
        if(!document.body||overlay)return;
        overlay=document.createElement('div');overlay.className='exp-loading-screen';overlay.id='experienceLoader';overlay.setAttribute('role','status');overlay.setAttribute('aria-live','polite');
        const mark=document.createElement('div');mark.className='exp-loading-mark';mark.textContent='D';
        const title=document.createElement('strong');title.textContent='Getting your workspace ready';
        const hint=document.createElement('span');hint.textContent='Loading your data…';hint.id='experienceLoaderText';overlay.append(mark,title,hint);document.body.append(overlay);
        const shell=document.querySelector('.app-shell');if(shell){shell.inert=true;shell.setAttribute('aria-busy','true');}
    }
    function activity(){
        const detail={pending,active:[...active.values()],initial:!revealed};document.dispatchEvent(new CustomEvent('experience:activity',{detail}));
        if(overlay?.isConnected){const first=[...active.values()][0];document.getElementById('experienceLoaderText').textContent=first?first.label+'…':'Preparing your view…';}
    }
    async function settle(){
        if(revealed||!loaded||pending||settling)return;settling=true;
        try{
            if(!taskDone){taskDone=true;for(const task of tasks)await task();}
            await new Promise(resolve=>setTimeout(resolve,Math.max(0,300-(performance.now()-started))));
            await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
            if(pending)return;
            revealed=true;root.classList.remove('exp-loading');overlay?.remove();
            const shell=document.querySelector('.app-shell');if(shell){shell.inert=false;shell.removeAttribute('aria-busy');}
            document.dispatchEvent(new CustomEvent('experience:ready'));activity();
        }finally{settling=false;if(!revealed&&!pending)setTimeout(settle,30);}
    }
    function begin(url,method){const id=++sequence;pending++;active.set(id,{id,label:label(url),method,started:performance.now(),url:url.pathname+url.search});activity();return id;}
    function end(id,detail){if(!active.has(id))return;const job=active.get(id);active.delete(id);pending--;activity();document.dispatchEvent(new CustomEvent('experience:request-end',{detail:{...job,...detail}}));settle();}
    window.fetch=async function(input,init={}){
        let url;try{url=new URL(typeof input==='string'||input instanceof URL?input:input.url,location.href);}catch{return originalFetch(input,init);}
        const method=String(init.method||(input instanceof Request?input.method:'GET')).toUpperCase();
        if(method==='GET'&&classify(url,method)&&window.Experience?.pageSize){url.searchParams.set('page_size',String(Experience.pageSize(Experience.defaultSize||25)));if(!(input instanceof Request))input=url.href;}
        const foreground=classify(url,method),id=foreground?begin(url,method):null;
        try{
            const response=await originalFetch(input,init);
            if(id!==null&&method!=='GET'){const json=response.json.bind(response);response.json=async()=>{const data=await json();if(response.ok&&!data?.error){Object.defineProperty(response,'experienceMutationEmitted',{value:true});document.dispatchEvent(new CustomEvent('experience:mutation',{detail:{url:url.href}}));}return data;};}
            if(id!==null){response.clone().text().then(text=>{
                let data=null;try{data=JSON.parse(text);}catch{}
                end(id,{ok:response.ok,status:response.status,data,action:url.searchParams.get('action')||'',error:data?.error||(!response.ok?'Request failed ('+response.status+')':'')});
            }).catch(()=>end(id,{ok:response.ok,status:response.status,error:'The response could not be read.'}));}
            return response;
        }catch(error){if(id!==null)end(id,{ok:false,status:0,error:error.name==='AbortError'?'Request cancelled.':'The connection was interrupted.'});throw error;}
    };
    const xhrOpen=XMLHttpRequest.prototype.open,xhrSend=XMLHttpRequest.prototype.send,xhrMeta=new WeakMap();
    XMLHttpRequest.prototype.open=function(method,url,...args){try{xhrMeta.set(this,{method:String(method).toUpperCase(),url:new URL(url,location.href)});}catch{}return xhrOpen.call(this,method,url,...args);};
    XMLHttpRequest.prototype.send=function(body){const meta=xhrMeta.get(this);if(meta&&classify(meta.url,meta.method)){const id=begin(meta.url,meta.method);const progress=event=>{if(event.lengthComputable&&event.total>0&&active.has(id)){active.get(id).fraction=event.loaded/event.total;activity();}};this.upload?.addEventListener('progress',progress);this.addEventListener('progress',progress);if(meta.method!=='GET')this.addEventListener('load',()=>{let data;try{data=this.responseType==='json'?this.response:JSON.parse(this.responseText);}catch{return;}if(this.status>=200&&this.status<300&&!data?.error)document.dispatchEvent(new CustomEvent('experience:mutation',{detail:{url:meta.url.href}}));},{once:true,capture:true});this.addEventListener('loadend',()=>{let data=null;try{data=JSON.parse(this.responseText);}catch{}end(id,{ok:this.status>=200&&this.status<400,status:this.status,data,error:data?.error||''});},{once:true});}return xhrSend.call(this,body);};
    document.addEventListener('htmx:beforeRequest',event=>{const path=event.detail.pathInfo?.requestPath;if(path){const u=new URL(path,location.href);if(classify(u,'GET'))event.detail.elt.dataset.expRequest=String(begin(u,'GET'));}});
    document.addEventListener('htmx:afterRequest',event=>{const id=Number(event.detail.elt.dataset.expRequest);if(id){end(id,{ok:!event.detail.failed,status:event.detail.xhr?.status||0});delete event.detail.elt.dataset.expRequest;}});
    window.ExperienceBoot={beforeReveal(task){if(!revealed)tasks.push(task);else task();},get ready(){return revealed;},get pending(){return pending;},originalFetch};
    new MutationObserver(()=>{if(document.body)ensure();}).observe(document.documentElement,{childList:true});
    document.addEventListener('DOMContentLoaded',()=>{ensure();const shell=document.querySelector('.app-shell');if(shell&&!revealed){shell.inert=true;shell.setAttribute('aria-busy','true');}},{once:true});window.addEventListener('load',()=>{loaded=true;ensure();settle();},{once:true});
    if(document.readyState==='complete'){loaded=true;ensure();settle();}
    // A failed/very slow request must offer a way to reach the page instead of trapping the user.
    setTimeout(()=>{if(revealed)return;ensure();const hint=document.getElementById('experienceLoaderText');if(hint)hint.textContent='This is taking longer than usual. You can keep waiting or view the page.';
        const button=document.createElement('button');button.className='btn';button.textContent='View page';button.type='button';button.onclick=()=>{revealed=true;root.classList.remove('exp-loading');overlay?.remove();const s=document.querySelector('.app-shell');if(s){s.inert=false;s.removeAttribute('aria-busy');}document.dispatchEvent(new CustomEvent('experience:ready'));};overlay?.append(button);
    },12000);
})();
