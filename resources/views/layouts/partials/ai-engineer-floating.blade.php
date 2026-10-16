<style>
#aiEngineerFab{position:fixed;right:22px;bottom:22px;z-index:1085;width:58px;height:58px;border:0;border-radius:50%;box-shadow:0 8px 24px rgba(0,0,0,.22);background:#276ef1;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
#aiEngineerFab:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(0,0,0,.28)}
#aiEngineerFab .ai-fab-label{position:absolute;right:68px;white-space:nowrap;background:#172b4d;color:#fff;border-radius:8px;padding:7px 10px;font-size:12px;opacity:0;pointer-events:none;transition:opacity .15s}
#aiEngineerFab:hover .ai-fab-label{opacity:1}
#aiEngineerMini{position:fixed;right:22px;bottom:90px;width:min(390px,calc(100vw - 28px));z-index:1084;display:none;border:1px solid #dbe4f0;border-radius:16px;background:var(--bs-body-bg,#fff);box-shadow:0 16px 45px rgba(0,0,0,.2);overflow:hidden}
#aiEngineerMini.open{display:block}
#aiEngineerMini .ai-mini-head{background:#172b4d;color:#fff;padding:13px 15px;display:flex;justify-content:space-between;align-items:center}
#aiEngineerMini .ai-mini-log{height:280px;overflow:auto;padding:12px}
#aiEngineerMini .ai-mini-msg{background:#f5f8fc;border-radius:10px;padding:10px;margin-bottom:8px;font-size:13px;line-height:1.45}
#aiEngineerMini .ai-mini-user{background:#e9f1ff}
#aiEngineerMini .ai-mini-foot{padding:10px;border-top:1px solid #e5eaf2}
@media(max-width:576px){#aiEngineerFab{right:14px;bottom:14px}#aiEngineerMini{right:14px;bottom:82px}}
</style>
<button id="aiEngineerFab" type="button" aria-label="Open AI Engineer">
 <i class="bi bi-stars fs-4"></i><span class="ai-fab-label">AI Engineer</span>
</button>
<div id="aiEngineerMini" aria-hidden="true">
 <div class="ai-mini-head"><strong><i class="bi bi-stars me-1"></i> AI Engineer</strong><button id="aiEngineerMiniClose" type="button" class="btn btn-sm btn-link text-white text-decoration-none">×</button></div>
 <div id="aiEngineerMiniLog" class="ai-mini-log"><div class="ai-mini-msg"><strong>AI Engineer:</strong> Ask about a customer, device, billing or outage. Read-only.</div></div>
 <div class="ai-mini-foot"><div class="input-group input-group-sm"><input id="aiEngineerMiniInput" class="form-control" placeholder="Ask AI Engineer..."><button id="aiEngineerMiniSend" class="btn btn-primary">Ask</button></div></div>
</div><script>
(() => {
 const fab=document.getElementById('aiEngineerFab'),mini=document.getElementById('aiEngineerMini'),close=document.getElementById('aiEngineerMiniClose'),input=document.getElementById('aiEngineerMiniInput'),send=document.getElementById('aiEngineerMiniSend'),log=document.getElementById('aiEngineerMiniLog');
 if(!fab||!mini)return;
 const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
 fab.addEventListener('click',()=>{mini.classList.toggle('open');mini.setAttribute('aria-hidden',mini.classList.contains('open')?'false':'true');if(mini.classList.contains('open'))input.focus()});
 close.addEventListener('click',()=>{mini.classList.remove('open');mini.setAttribute('aria-hidden','true')});
 async function ask(){
  const q=input.value.trim();if(!q)return;input.value='';log.insertAdjacentHTML('beforeend','<div class="ai-mini-msg ai-mini-user"><strong>You:</strong> '+esc(q)+'</div>');
  const box=document.createElement('div');box.className='ai-mini-msg';box.innerHTML='<strong>AI Engineer:</strong> Checking...';log.appendChild(box);log.scrollTop=log.scrollHeight;send.disabled=true;
  try{const r=await fetch('{{ route('ai-engineer.chat') }}',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({question:q,customer_id:''})});const d=await r.json();box.innerHTML='<strong>AI Engineer:</strong> '+esc(d.message||'No response.');}
  catch(e){box.innerHTML='<strong>AI Engineer:</strong> '+esc(e.message||'Request failed.');}
  finally{send.disabled=false;log.scrollTop=log.scrollHeight}
 }
 send.addEventListener('click',ask);input.addEventListener('keydown',e=>{if(e.key==='Enter')ask()});
})();
</script>