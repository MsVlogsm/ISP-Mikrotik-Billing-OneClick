<style>
#aiEngineerFab{position:fixed;right:22px;bottom:22px;z-index:1085;width:58px;height:58px;border:0;border-radius:50%;box-shadow:0 8px 24px rgba(0,0,0,.22);background:#276ef1;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
#aiEngineerFab:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(0,0,0,.28)}
#aiEngineerFab .ai-fab-label{position:absolute;right:68px;white-space:nowrap;background:#172b4d;color:#fff;border-radius:8px;padding:7px 10px;font-size:12px;opacity:0;pointer-events:none;transition:opacity .15s}
#aiEngineerFab:hover .ai-fab-label{opacity:1}
#aiEngineerMini{position:fixed;right:22px;bottom:90px;width:min(390px,calc(100vw - 28px));z-index:1084;display:none;border:1px solid #dbe4f0;border-radius:16px;background:var(--bs-body-bg,#fff);box-shadow:0 16px 45px rgba(0,0,0,.2);overflow:hidden}
#aiEngineerMini.open{display:block}
#aiEngineerMini .ai-mini-head{background:#172b4d;color:#fff;padding:13px 15px;display:flex;justify-content:space-between;align-items:center}
#aiEngineerMini .ai-mini-actions{display:flex;gap:6px;align-items:center}
#aiEngineerMini .ai-mini-history{display:none;max-height:170px;overflow:auto;border-bottom:1px solid #e5eaf2;padding:8px}
#aiEngineerMini .ai-mini-history.open{display:block}
#aiEngineerMini .ai-history-item{display:block;width:100%;text-align:left;border:0;background:transparent;padding:7px 8px;border-radius:7px;color:var(--bs-body-color);font-size:12px}
#aiEngineerMini .ai-history-item:hover{background:rgba(120,140,160,.15)}
#aiEngineerMini .ai-mini-tool{display:inline-block;font-family:monospace;font-size:11px;color:#b7791f;background:rgba(183,121,31,.12);padding:3px 8px;border-radius:20px;margin:2px 0 7px}
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
 <div class="ai-mini-head"><strong><i class="bi bi-stars me-1"></i> Assistant</strong><div class="ai-mini-actions"><button id="aiEngineerHistoryToggle" type="button" class="btn btn-sm btn-outline-light">History</button><button id="aiEngineerNew" type="button" class="btn btn-sm btn-outline-light">＋ New</button><button id="aiEngineerMiniClose" type="button" class="btn btn-sm btn-link text-white text-decoration-none">×</button></div></div>
 <div id="aiEngineerHistory" class="ai-mini-history"></div>
 <div id="aiEngineerMiniLog" class="ai-mini-log"><div class="ai-mini-msg"><strong>AI Engineer:</strong> Ask about a customer, device, billing or outage. Read-only.</div></div>
 <div class="ai-mini-foot"><div class="input-group input-group-sm"><input id="aiEngineerMiniInput" class="form-control" placeholder="Ask AI Engineer..."><button id="aiEngineerMiniSend" class="btn btn-primary">Ask</button></div></div>
</div><script>
(() => {
 const fab=document.getElementById('aiEngineerFab'),mini=document.getElementById('aiEngineerMini'),close=document.getElementById('aiEngineerMiniClose'),input=document.getElementById('aiEngineerMiniInput'),send=document.getElementById('aiEngineerMiniSend'),log=document.getElementById('aiEngineerMiniLog'),historyBox=document.getElementById('aiEngineerHistory'),historyToggle=document.getElementById('aiEngineerHistoryToggle'),newButton=document.getElementById('aiEngineerNew');
 if(!fab||!mini)return;
 const chatUrl='{{ route('ai-engineer.chat') }}',historyUrl='{{ Route::has('ai-engineer.history') ? route('ai-engineer.history') : '' }}',csrf='{{ csrf_token() }}';
 let conversationId=null;
 const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
 const append=(role,text,tool='')=>{const box=document.createElement('div');box.className='ai-mini-msg'+(role==='user'?' ai-mini-user':'');box.innerHTML=(role==='user'?'<strong>You:</strong> ':'<strong>Assistant:</strong> ')+esc(text)+(tool?'<div><span class="ai-mini-tool">› '+esc(tool)+'</span></div>':'');log.appendChild(box);log.scrollTop=log.scrollHeight;};
 const request=async(url,options={})=>{const r=await fetch(url,{...options,headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf,...(options.headers||{})}});if(!r.ok)throw new Error('Request failed ('+r.status+')');return r.json();};
 async function loadHistory(){historyBox.classList.add('open');if(!historyUrl){historyBox.textContent='Saved conversation history is not enabled on this server.';return;}historyBox.innerHTML='<div class="ai-mini-msg">Loading history…</div>';try{const d=await request(historyUrl);historyBox.innerHTML='';(d.items||[]).forEach(item=>{const b=document.createElement('button');b.className='ai-history-item';b.textContent=item.title||'New conversation';b.addEventListener('click',()=>openHistory(item.id));historyBox.appendChild(b)});if(!d.items?.length)historyBox.textContent='No saved conversations yet.';}catch(e){historyBox.textContent=e.message;}}
 async function openHistory(id){try{const d=await request(historyUrl+'/'+encodeURIComponent(id));conversationId=d.id;log.innerHTML='';(d.messages||[]).forEach(m=>append(m.role==='user'?'user':'assistant',m.content,(m.tools||[]).map(t=>t.name).filter(Boolean).join(', ')));historyBox.classList.remove('open');}catch(e){append('assistant',e.message);}}
 fab.addEventListener('click',()=>{mini.classList.toggle('open');mini.setAttribute('aria-hidden',mini.classList.contains('open')?'false':'true');if(mini.classList.contains('open'))input.focus()});
 close.addEventListener('click',()=>{mini.classList.remove('open');mini.setAttribute('aria-hidden','true')});
 historyToggle.addEventListener('click',()=>historyBox.classList.contains('open')?historyBox.classList.remove('open'):loadHistory());
 newButton.addEventListener('click',()=>{conversationId=null;log.innerHTML='';append('assistant','Hello! Ask about collections, customers, live PPPoE sessions or network diagnostics. Read-only.');historyBox.classList.remove('open');input.focus();});
 async function ask(){const q=input.value.trim();if(!q)return;input.value='';append('user',q);const box=document.createElement('div');box.className='ai-mini-msg';box.innerHTML='<strong>Assistant:</strong> Thinking…';log.appendChild(box);log.scrollTop=log.scrollHeight;send.disabled=true;
  try{const d=await request(chatUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:q,customer_id:'',conversation_id:conversationId})});conversationId=d.conversation_id||conversationId;box.innerHTML='<strong>Assistant:</strong> '+esc(d.message||'No response.');if(d.tools?.length)box.insertAdjacentHTML('beforeend','<div><span class="ai-mini-tool">› '+esc(d.tools.join(' · '))+'</span></div>');}
  catch(e){box.innerHTML='<strong>Assistant:</strong> '+esc(e.message||'Request failed.');}
  finally{send.disabled=false;log.scrollTop=log.scrollHeight;}
 }
 send.addEventListener('click',ask);input.addEventListener('keydown',e=>{if(e.key==='Enter')ask()});
})();
</script>