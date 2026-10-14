/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useEffect, useState } from 'react';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { t } from '../i18n';
import { Alert } from './Alert';

interface Target { kind: string; name: string; users: string[]; default_user: string | null }
interface Category { id: number; name: string }
export interface DirectActionDraft {
  category_id: number | null;
  http?: { method: string; timeout_seconds: number; expected_status: string; max_redirects: number; store_response: boolean };
  request?: { url: string; headers: Array<{name: string; value: string}>; body: string | null };
  shell?: { target: {kind: string; name: string}; interpreter: string; user: string | null; workdir: string | null; timeout_seconds: number };
  script?: { source: string; env: Array<{name: string; value: string}> };
}
export function newDirectAction(kind: string): DirectActionDraft {
  return kind === 'webhook' ? {category_id:null,http:{method:'POST',timeout_seconds:30,expected_status:'200-299',max_redirects:0,store_response:false},request:{url:'',headers:[],body:null}} : {category_id:null,shell:{target:{kind:kind==='docker'?'docker':'host',name:''},interpreter:'sh',user:null,workdir:null,timeout_seconds:300},script:{source:'',env:[]}};
}

export function AutomationActionFields({kind,draft,onChange}: {kind:string;draft:DirectActionDraft;onChange:(value:DirectActionDraft)=>void}) {
  const [categories,setCategories]=useState<Category[]>([]);
  const [targets,setTargets]=useState<Target[]>([]);
  const [error,setError]=useState<string | null>(null);
  const [headers,setHeaders]=useState(()=>draft.request?.headers.map(h=>h.name+': '+h.value).join('\n')??'');
  useEffect(()=>{
    let cancelled=false;
    request<{categories:Category[]}>('GET','/api/categories?permission='+(kind==='webhook'?'jobs.edit_http':'jobs.view')).then(data=>{if(!cancelled)setCategories(data.categories);}).catch(caught=>{if(!cancelled)setError(errorMessage(caught));});
    return ()=>{cancelled=true;};
  },[kind]);
  useEffect(()=>{
    if(kind==='webhook')return;
    let cancelled=false;setTargets([]);setError(null);
    request<{targets:Target[]}>('GET','/api/shell/targets'+(draft.category_id===null?'':'?category_id='+draft.category_id)).then(data=>{if(!cancelled)setTargets(data.targets.filter(target=>target.kind===(kind==='docker'?'docker':'host')));}).catch(caught=>{if(!cancelled)setError(errorMessage(caught));});
    return ()=>{cancelled=true;};
  },[kind,draft.category_id]);
  const http=draft.http,web=draft.request,shell=draft.shell,script=draft.script;
  const picked=targets.find(target=>target.name===shell?.target.name);
  return <div className="form">
    {error&&<Alert tone="err">{error}</Alert>}
    <label className="field"><span className="field__label">{t('Kategorie')}</span><select className="input" value={draft.category_id??''} onChange={event=>onChange({...draft,category_id:event.target.value===''?null:Number(event.target.value),...(shell?{shell:{...shell,target:{...shell.target,name:''},user:null}}:{})})}><option value="">{t('Ohne Kategorie')}</option>{categories.map(category=><option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
    {http&&web&&<>
      <div className="grid-2"><label className="field"><span className="field__label">{t('Webhook-URL')}</span><input className="input" type="url" autoComplete="off" required value={web.url} onChange={event=>onChange({...draft,request:{...web,url:event.target.value}})}/></label><label className="field"><span className="field__label">{t('HTTP-Methode')}</span><select className="input" value={http.method} onChange={event=>onChange({...draft,http:{...http,method:event.target.value},request:{...web,body:['GET','HEAD'].includes(event.target.value)?null:web.body}})}>{['POST','GET','PUT','PATCH','DELETE','HEAD'].map(method=><option key={method}>{method}</option>)}</select></label></div>
      <label className="field"><span className="field__label">{t('HTTP-Header (Name: Wert je Zeile)')}</span><textarea className="input input--area" value={headers} onChange={event=>{setHeaders(event.target.value);onChange({...draft,request:{...web,headers:event.target.value.split(/\r?\n/).filter(line=>line.trim()).map(line=>{const colon=line.indexOf(':');return{name:colon<0?line:line.slice(0,colon).trim(),value:colon<0?'':line.slice(colon+1).trim()};})}});}}/></label>
      {!['GET','HEAD'].includes(http.method)&&<label className="field"><span className="field__label">{t('Anfrage-Body')}</span><textarea className="input input--area" maxLength={65536} value={web.body??''} onChange={event=>onChange({...draft,request:{...web,body:event.target.value||null}})}/></label>}
      <label className="field"><span className="field__label">{t('Zeitlimit in Sekunden')}</span><input className="input" type="number" min={1} value={http.timeout_seconds} required onChange={event=>onChange({...draft,http:{...http,timeout_seconds:Number(event.target.value)}})}/></label>
    </>}
    {shell&&script&&<>
      <div className="grid-2"><label className="field"><span className="field__label">{t(kind==='docker'?'Docker-Container':'Host-Ausführungsort')}</span><select className="input" aria-label={t(kind==='docker'?'Docker-Container':'Host-Ausführungsort')} required value={shell.target.name} onChange={event=>{const target=targets.find(entry=>entry.name===event.target.value);onChange({...draft,shell:{...shell,target:{...shell.target,name:event.target.value},user:kind==='docker'?(target?.default_user??target?.users[0]??null):null}});}}><option value="">{t('Bitte wählen …')}</option>{targets.map(target=><option key={target.name} value={target.name}>{target.name}</option>)}</select></label><label className="field"><span className="field__label">{t('Interpreter')}</span><select className="input" value={shell.interpreter} onChange={event=>onChange({...draft,shell:{...shell,interpreter:event.target.value}})}><option>sh</option><option>bash</option></select></label></div>
      {targets.length===0&&!error&&<p className="hint">{t('Zuerst einen passenden Ausführungsort für diese Kategorie freigeben.')}</p>}
      {kind==='docker'&&<label className="field"><span className="field__label">{t('Benutzer')}</span><select className="input" required value={shell.user??''} onChange={event=>onChange({...draft,shell:{...shell,user:event.target.value}})}><option value="">{t('Bitte wählen …')}</option>{picked?.users.map(user=><option key={user}>{user}</option>)}</select></label>}
      <label className="field"><span className="field__label">{t('Shell-Skript / Befehl')}</span><textarea className="input input--area input--mono" maxLength={65536} required value={script.source} onChange={event=>onChange({...draft,script:{...script,source:event.target.value}})}/></label>
      <label className="field"><span className="field__label">{t('Zeitlimit in Sekunden')}</span><input className="input" type="number" min={1} required value={shell.timeout_seconds} onChange={event=>onChange({...draft,shell:{...shell,timeout_seconds:Number(event.target.value)}})}/></label>
    </>}
    <p className="hint">{t('Diese Aktion erhält einen eigenen verschlüsselten Job und läuft ausschließlich durch die Automation, ohne eigenen Zeitplan.')}</p>
  </div>;
}
